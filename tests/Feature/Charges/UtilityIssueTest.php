<?php

namespace Tests\Feature\Charges;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\ChargeDraft;
use App\Models\Fund;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\PaidUtilityBill;
use App\Models\Property;
use App\Models\Provider;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\UtilityAccount;
use App\Services\Charges\Issuer;
use App\Services\Charges\MonthWorksheet;
use App\Services\Charges\Reconciliation;
use App\Services\Utilities\FundLedger;
use App\Services\Utilities\UtilityAccountService;
use App\Services\Utilities\UtilityBalances;
use App\Services\Utilities\UtilityImporter;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Contracts\ContractTestCase;
use Tests\Support\Funds;
use Tests\Support\UtilityFiles;

/**
 * Издаване на битовите редове и записите в книгата (PROJECT.md, „Битови сметки
 * в месеца“, 22.11.2026, и „Общ фонд“, 22–23.11.2026; част 4б):
 *
 * - битов ред: 418 + към наемателя (договор, имот, известие, платена сметка)
 *   и 407 − за същата сметка и имот; при пълно начисление 407 на имота е 0;
 * - фиксирана такса: 418 + към наемателя и приход във фонда (407 − на фонда),
 *   с връзка към известието;
 * - ред със сума 0 не е в известието и не е в книгата;
 * - ръчно намален или махнат ред: разликата остава неначислена (407 ≠ 0);
 * - защита от двойно начисление на една и съща сметка;
 * - „Издай сега“ в неиздаден месец взима и битовите редове; в издаден месец е
 *   без тях (отиват в следващото известие);
 * - промяна на договора след издаването не преизчислява битовите дялове;
 *   сверката е само за наема и собственика.
 *
 * Пример: ток 90 € за ноември, платен на 05.12; А живее до 11.11 (10 дни →
 * 30,00 €), Б от 11.11 (20 дни → 60,00 €). Само измислени номера, имена и суми.
 */
class UtilityIssueTest extends ContractTestCase
{
    use Funds, UtilityFiles;

    private MonthWorksheet $sheet;

    private Issuer $issuer;

    private Provider $power;

    private Property $flat;

    private UtilityAccount $meter;

    private int $ref = 850000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sheet = app(MonthWorksheet::class);
        $this->issuer = app(Issuer::class);
        $this->power = Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL']);
        $this->flat = $this->property();
        $this->percentContract([$this->flat]);
        $this->meter = app(UtilityAccountService::class)->create([
            'provider_id' => $this->power->id, 'number' => '0012345', 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => [['property_id' => $this->flat->id]],
        ]);
    }

    private function lease(Property $property, string $from, ?string $terminated = null): LeaseContract
    {
        $lease = $this->leaseContract([], ['signed_on' => $from, 'lines' => [['property_id' => $property->id, 'rent' => '500.00']]]);
        if ($terminated !== null) {
            $lease->update(['terminated_on' => $terminated]);
        }

        return $lease;
    }

    private function bill(UtilityAccount $account, string $paidAt, float $amount): PaidUtilityBill
    {
        $this->ref++;
        app(UtilityImporter::class)->import($this->easypayFile([
            ['ref' => $this->ref, 'date' => "{$paidAt} 10:00:00", 'number' => (int) $account->number, 'provider' => 'IZM EL', 'amount' => $amount],
        ]), 'f.xlsx');

        return PaidUtilityBill::query()->where('reference', (string) $this->ref)->sole();
    }

    private function fixedFee(Property $property, string $amount): UtilityAccount
    {
        return app(UtilityAccountService::class)->create([
            'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => $amount, 'valid_from' => '2026-01-01', 'note' => 'Такса вход',
            'fund_id' => $this->fund([$property])->id, 'properties' => [['property_id' => $property->id]],
        ]);
    }

    private function row(ChargeType $type, LeaseContract $lease, string $month = '2026-12'): ChargeDraft
    {
        return ChargeDraft::query()->whereDate('month', "{$month}-01")->where('type', $type)->where('lease_contract_id', $lease->id)->sole();
    }

    /** Записите по сметка и вид: [договор, имот, сума]. */
    private function entries(LedgerAccount $account, ChargeType $type): array
    {
        return LedgerEntry::query()->where('account', $account)->where('type', $type)->orderBy('id')->get()
            ->map(fn (LedgerEntry $e) => [$e->lease_contract_id, $e->property_id, $e->amount])->all();
    }

    private function balance(): ?string
    {
        return app(UtilityBalances::class)->byProperty($this->staff->company_id)[$this->flat->id] ?? null;
    }

    // Битов ред

    public function test_utility_row_is_418_to_the_tenant_and_407_minus_for_the_bill_and_property(): void
    {
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-11');
        $bill = $this->bill($this->meter, '2026-12-05', 90.00);
        $this->sheet->generate('2026-12');

        $this->issuer->issueMonth('2026-12');

        $this->assertEqualsCanonicalizing([[$a->id, $this->flat->id, '30.00'], [$b->id, $this->flat->id, '60.00']], $this->entries(LedgerAccount::Tenant, ChargeType::Utility));
        foreach (LedgerEntry::query()->where('account', '418')->where('type', ChargeType::Utility)->get() as $entry) {
            $this->assertSame($bill->id, $entry->paid_utility_bill_id);
            $this->assertSame(LedgerKind::Issue, $entry->kind);
            $this->assertSame(Statement::query()->where('lease_contract_id', $entry->lease_contract_id)->sole()->id, $entry->statement_id);
            $this->assertSame('2026-12-01', $entry->month->toDateString());
        }

        $minus = LedgerEntry::query()->where('account', '407')->where('kind', LedgerKind::Issue)->orderBy('id')->get();
        $this->assertEqualsCanonicalizing([[$this->flat->id, '-30.00'], [$this->flat->id, '-60.00']], $minus->map(fn ($e) => [$e->property_id, $e->amount])->all());
        $this->assertSame([$bill->id, $bill->id], $minus->pluck('paid_utility_bill_id')->all());
        $this->assertSame([null, null], $minus->pluck('fund_id')->all());
        $this->assertNotNull($minus[0]->statement_id);

        $this->assertSame('0.00', $this->balance(), 'изцяло начислена: 90 − 30 − 60');
    }

    public function test_notice_line_keeps_the_bill_account_and_period(): void
    {
        $lease = $this->lease($this->flat, '2026-01-10');
        $bill = $this->bill($this->meter, '2026-12-05', 90.00);
        $this->sheet->generate('2026-12');

        $this->issuer->issueMonth('2026-12');

        $line = StatementLine::query()->where('type', ChargeType::Utility)->sole();
        $this->assertSame([$bill->id, $this->meter->id, '2026-11-01', '2026-11-30', '90.00'],
            [$line->paid_utility_bill_id, $line->utility_account_id, $line->period_from->toDateString(), $line->period_to->toDateString(), $line->amount]);
        $statement = Statement::query()->where('lease_contract_id', $lease->id)->sole();
        $this->assertSame('590.00', $statement->total, 'наем 500 + ток 90');
        $this->assertSame($statement->id, $this->row(ChargeType::Utility, $lease)->statement_id);
    }

    // Фиксирана такса

    public function test_fixed_fee_is_418_to_the_tenant_and_income_of_the_fund(): void
    {
        $lease = $this->lease($this->flat, '2026-01-10');
        $fee = $this->fixedFee($this->flat, '15');
        $this->sheet->generate('2026-12');

        $this->issuer->issueMonth('2026-12');

        $statement = Statement::query()->where('lease_contract_id', $lease->id)->sole();
        $this->assertSame([[$lease->id, $this->flat->id, '15.00']], $this->entries(LedgerAccount::Tenant, ChargeType::FixedFee));
        $income = LedgerEntry::query()->where('kind', LedgerKind::FundIncome)->sole();
        $this->assertSame([$fee->fund_id, null, null, '-15.00', $statement->id, '2026-12-01'],
            [$income->fund_id, $income->property_id, $income->lease_contract_id, $income->amount, $income->statement_id, $income->month->toDateString()]);
        $this->assertSame(1, LedgerEntry::query()->where('account', '407')->count(), 'нищо по 407 на имота');

        $report = app(FundLedger::class)->report(Fund::query()->findOrFail($fee->fund_id));
        $this->assertSame(['15.00', '0.00', '15.00'], [$report->collected, $report->paid, $report->left]);
        $this->assertNull($this->balance(), 'фиксираната такса не е по 407 на имота');
    }

    // Сума 0, намален и махнат ред

    public function test_zero_utility_row_is_not_in_the_notice_or_the_ledger(): void
    {
        $lease = $this->lease($this->flat, '2026-01-10');
        $this->bill($this->meter, '2026-12-05', 90.00);
        $this->sheet->generate('2026-12');
        $this->sheet->change($this->row(ChargeType::Utility, $lease), ['amount' => '0', 'reason' => 'наемателят е платил сам']);

        $this->issuer->issueMonth('2026-12');

        $this->assertSame(0, StatementLine::query()->where('type', ChargeType::Utility)->count());
        $this->assertSame([], $this->entries(LedgerAccount::Tenant, ChargeType::Utility));
        $this->assertSame(0, LedgerEntry::query()->where('account', '407')->where('kind', LedgerKind::Issue)->count());
        $this->assertSame('500.00', Statement::query()->sole()->total);
        $this->assertSame('90.00', $this->balance(), 'остава неначислено (част 5)');
    }

    public function test_reduced_row_leaves_the_difference_uncharged(): void
    {
        $lease = $this->lease($this->flat, '2026-01-10');
        $this->bill($this->meter, '2026-12-05', 90.00);
        $this->sheet->generate('2026-12');
        $this->sheet->change($this->row(ChargeType::Utility, $lease), ['amount' => '60', 'reason' => 'отстъпка']);

        $this->issuer->issueMonth('2026-12');

        $this->assertSame([[$lease->id, $this->flat->id, '60.00']], $this->entries(LedgerAccount::Tenant, ChargeType::Utility));
        $this->assertSame('30.00', $this->balance(), 'частично начислена: 90 − 60');
    }

    public function test_removed_row_is_not_charged(): void
    {
        $lease = $this->lease($this->flat, '2026-01-10');
        $this->bill($this->meter, '2026-12-05', 90.00);
        $this->sheet->generate('2026-12');
        $this->sheet->remove($this->row(ChargeType::Utility, $lease), 'платено на място');

        $this->issuer->issueMonth('2026-12');

        $this->assertSame([], $this->entries(LedgerAccount::Tenant, ChargeType::Utility));
        $this->assertSame('90.00', $this->balance());
    }

    // Двойно начисление

    public function test_the_same_bill_is_not_charged_twice(): void
    {
        $lease = $this->lease($this->flat, '2026-01-10');
        $this->bill($this->meter, '2026-12-05', 90.00);
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');
        $this->sheet->generate('2027-01');

        // Ред за същата сметка, договор и имот и в януари (например от грешка).
        $copy = $this->row(ChargeType::Utility, $lease)->replicate(['statement_id']);
        $copy->forceFill(['month' => '2027-01-01', 'statement_id' => null])->save();
        $before = LedgerEntry::query()->count();

        $this->assertRejected(fn () => $this->issuer->issueMonth('2027-01'), 'month');
        $this->assertRejected(fn () => $this->issuer->issueNow($lease, '2027-01'), 'month');
        $this->assertSame($before, LedgerEntry::query()->count(), 'нищо не е записано');
        $this->assertSame('0.00', $this->balance());
    }

    public function test_the_same_bill_is_not_charged_twice_to_one_contract_even_with_money_left_on_it(): void
    {
        // Декември: 60 от 90 (остават 30 неначислени). Януари: същата сметка на същия договор още веднъж, 30 €.
        $lease = $this->lease($this->flat, '2026-01-10');
        $this->bill($this->meter, '2026-12-05', 90.00);
        $this->sheet->generate('2026-12');
        $this->sheet->change($this->row(ChargeType::Utility, $lease), ['amount' => '60', 'reason' => 'отстъпка']);
        $this->issuer->issueMonth('2026-12');
        $this->sheet->generate('2027-01');
        $copy = $this->row(ChargeType::Utility, $lease)->replicate(['statement_id']);
        $copy->forceFill(['month' => '2027-01-01', 'statement_id' => null, 'amount' => '30.00'])->save();

        $this->assertRejected(fn () => $this->issuer->issueMonth('2027-01'), 'month');
        $this->assertSame('30.00', $this->balance(), 'остатъкът е за част 5, не за второ начисление');
    }

    public function test_a_bill_is_not_charged_over_its_paid_amount(): void
    {
        $lease = $this->lease($this->flat, '2026-01-10');
        $this->bill($this->meter, '2026-12-05', 90.00);
        $this->sheet->generate('2026-12');
        $this->sheet->change($this->row(ChargeType::Utility, $lease), ['amount' => '95', 'reason' => 'грешка при въвеждане']);

        $this->assertRejected(fn () => $this->issuer->issueMonth('2026-12'), 'month');
        $this->assertSame(0, LedgerEntry::query()->where('account', '<>', '407')->count());
    }

    public function test_the_same_fixed_fee_is_not_charged_twice(): void
    {
        $lease = $this->lease($this->flat, '2026-01-10');
        $this->fixedFee($this->flat, '15');
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');
        $this->sheet->generate('2027-01');

        // Таксата за ноември още веднъж, в януари.
        $copy = $this->row(ChargeType::FixedFee, $lease)->replicate(['statement_id']);
        $copy->forceFill(['month' => '2027-01-01', 'statement_id' => null])->save();
        $before = LedgerEntry::query()->count();

        $this->assertRejected(fn () => $this->issuer->issueMonth('2027-01'), 'month');
        $this->assertSame($before, LedgerEntry::query()->count());
    }

    // „Издай сега“

    public function test_issue_now_in_an_open_month_takes_the_utility_rows_of_the_contract(): void
    {
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-11');
        $this->bill($this->meter, '2026-12-05', 90.00);
        $this->fixedFee($this->flat, '15');
        $this->sheet->generate('2026-12');

        $statement = $this->issuer->issueNow($b, '2026-12');

        $this->assertSame(['rent', 'utility', 'fixed_fee'], $statement->lines()->orderBy('id')->get()->map(fn ($l) => $l->type->value)->all());
        $this->assertSame([[$b->id, $this->flat->id, '60.00']], $this->entries(LedgerAccount::Tenant, ChargeType::Utility));
        $this->assertSame('30.00', $this->balance(), 'частта на А още не е издадена');

        // „Издай месеца“ издава А, без да повтаря Б.
        $this->issuer->issueMonth('2026-12');
        $this->assertSame([[$b->id, $this->flat->id, '60.00'], [$a->id, $this->flat->id, '30.00']], $this->entries(LedgerAccount::Tenant, ChargeType::Utility));
        $this->assertSame('0.00', $this->balance());
        // Таксата за ноември: А 10 дни → 5,00 €, Б 20 дни → 10,00 €; първо Б (с „Издай сега“), после А.
        $this->assertSame(['-10.00', '-5.00'], LedgerEntry::query()->where('kind', LedgerKind::FundIncome)->orderBy('id')->pluck('amount')->all());
    }

    public function test_issue_now_in_an_issued_month_is_without_utilities_they_go_to_the_next_notice(): void
    {
        $this->bill($this->meter, '2026-12-05', 90.00); // имотът е празен при генерирането
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');

        // Договор от 01.11, въведен след издаването на декември.
        $late = $this->lease($this->flat, '2026-11-01');
        $statement = $this->issuer->issueNow($late, '2026-12');

        $this->assertSame(['rent'], $statement->lines()->get()->map(fn ($l) => $l->type->value)->all());
        $this->assertSame([], $this->entries(LedgerAccount::Tenant, ChargeType::Utility));

        // Сметката отива в следващото известие.
        $this->sheet->generate('2027-01');
        $this->assertSame('90.00', $this->row(ChargeType::Utility, $late, '2027-01')->amount);
    }

    public function test_issue_now_before_the_month_is_generated_computes_the_contract_shares_and_the_rest_goes_to_the_others(): void
    {
        // PROJECT.md, план за част 4, т. 5 (решено 23.11.2026): нищо не остава неначислено заради реда на действията.
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-11');
        $bill = $this->bill($this->meter, '2026-12-05', 90.00);
        $this->fixedFee($this->flat, '15');

        // А с „Издай сега“ преди генерирането: само неговите дялове (ток 10 дни → 30,00; такса 10 дни → 5,00).
        $statement = $this->issuer->issueNow($a, '2026-12');
        $this->assertSame([['utility', '30.00'], ['fixed_fee', '5.00']],
            $statement->lines()->orderBy('id')->get()->map(fn ($l) => [$l->type->value, $l->amount])->all());
        $line = $statement->lines()->where('type', ChargeType::Utility)->sole();
        $this->assertSame([$bill->id, $this->meter->id, '2026-11-01'], [$line->paid_utility_bill_id, $line->utility_account_id, $line->period_from->toDateString()]);
        $this->assertSame('60.00', $this->balance());

        // Генерирането за Б: делът на А е начислен, Б получава своите 60,00 и 10,00.
        $this->sheet->generate('2026-12');
        $this->assertSame(0, ChargeDraft::query()->where('lease_contract_id', $a->id)->count());
        $this->assertSame('60.00', $this->row(ChargeType::Utility, $b)->amount);
        $this->assertSame('10.00', $this->row(ChargeType::FixedFee, $b)->amount);

        $this->issuer->issueMonth('2026-12');
        $this->assertSame('0.00', $this->balance(), 'изцяло начислена: 30 + 60');
        $this->assertSame(['-5.00', '-10.00'], LedgerEntry::query()->where('kind', LedgerKind::FundIncome)->orderBy('id')->pluck('amount')->all());
    }

    public function test_bill_charged_with_issue_now_before_generating_is_not_offered_again_next_month(): void
    {
        $lease = $this->lease($this->flat, '2026-01-10');
        $this->bill($this->meter, '2026-12-05', 90.00);

        $this->issuer->issueNow($lease, '2026-12');
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');
        $this->sheet->generate('2027-01');

        $this->assertSame(0, ChargeDraft::query()->whereDate('month', '2027-01-01')->where('type', ChargeType::Utility)->count());
        $this->assertSame([[$lease->id, $this->flat->id, '90.00']], $this->entries(LedgerAccount::Tenant, ChargeType::Utility));
        $this->assertSame('0.00', $this->balance());
    }

    // След издаването

    public function test_contract_change_after_issuing_does_not_recompute_utility_shares_and_reconciliation_is_for_rent_and_owner_only(): void
    {
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-11');
        $this->bill($this->meter, '2026-12-05', 90.00);
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');
        $this->assertSame([], app(Reconciliation::class)->differences('2026-12'), 'след издаването няма разлики');
        $utilities = LedgerEntry::query()->whereIn('type', [ChargeType::Utility->value, ChargeType::FixedFee->value])->orderBy('id')->get()->toArray();

        // Б е въведен със закъснение: всъщност е от 21.11 (А е стоял до 21.11) и напуска на 16.12.
        // (Направо в базата: тук е важно само, че договорите са други след издаването.)
        DB::table('lease_contracts')->where('id', $b->id)->update(['signed_on' => '2026-11-21', 'terminated_on' => '2026-12-16']);
        DB::table('lease_contracts')->where('id', $a->id)->update(['terminated_on' => '2026-11-21']);
        $this->sheet->generate('2027-01');

        $this->assertSame($utilities, LedgerEntry::query()->whereIn('type', [ChargeType::Utility->value, ChargeType::FixedFee->value])->orderBy('id')->get()->toArray(),
            'битовите записи не се пипат');
        $this->assertSame(0, ChargeDraft::query()->whereDate('month', '2027-01-01')->where('type', ChargeType::Utility)->count(), 'сметката не се начислява отново');
        $differences = app(Reconciliation::class)->differences('2026-12');
        $this->assertNotEmpty($differences, 'Б напуска на 16.12: наемът за декември е друг');
        foreach ($differences as $difference) {
            $this->assertContains($difference->type, [ChargeType::Rent, ChargeType::Owner], 'сверката е само за наема и собственика');
        }
    }

    // Напуснал наемател само със сметки

    public function test_former_tenant_gets_a_notice_with_only_the_utility_line(): void
    {
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $this->lease($this->flat, '2026-11-11');
        $this->bill($this->meter, '2026-12-05', 90.00);
        $this->sheet->generate('2026-12');

        $this->issuer->issueMonth('2026-12');

        $statement = Statement::query()->where('lease_contract_id', $a->id)->sole();
        $this->assertSame(['utility'], $statement->lines()->get()->map(fn ($l) => $l->type->value)->all());
        $this->assertSame('30.00', $statement->total);
    }
}
