<?php

namespace Tests\Feature\Utilities;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\Fund;
use App\Models\LedgerEntry;
use App\Models\PaidUtilityBill;
use App\Models\Property;
use App\Models\Provider;
use App\Models\UtilityAccount;
use App\Services\Utilities\FundLedger;
use App\Services\Utilities\UtilityAccountService;
use App\Services\Utilities\UtilityBalances;
use App\Services\Utilities\UtilityImporter;
use App\Services\Utilities\UtilityMonth;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Contracts\ContractTestCase;
use Tests\Support\Funds;
use Tests\Support\UtilityFiles;

/**
 * Общият фонд в книгата (PROJECT.md, „Общ фонд“, решено 22.11.2026, уточнено
 * 23.11.2026): сметка 407 на фонда (с връзка към фонда, без имот), с два
 * вида записи — „приход във фонда“ (фиксираните такси, 407 −) и „разход от
 * фонда“ (сметка, платена от фонда, 407 +). Сметката от фонд не се дели на
 * наемателите и не стига до месеца. Справката (събрано, платено, остатък)
 * се смята само от книгата. Справките по имот не броят записите на фонда.
 * Само измислени имена, номера и суми.
 */
class FundLedgerTest extends ContractTestCase
{
    use Funds, UtilityFiles;

    private FundLedger $ledger;

    private Provider $power;

    private Property $a;

    private Property $b;

    private Property $c;

    private Fund $entrance;

    private UtilityAccount $fee;

    private UtilityAccount $light;

    private int $ref = 920000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(FundLedger::class);
        $this->power = Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL', 'epay_name' => 'Измислено Електро АД']);
        [$this->a, $this->b, $this->c] = [$this->property(), $this->property(), $this->property()];
        $this->entrance = $this->fund([$this->a, $this->b, $this->c], ['name' => 'Вход (измислен)']);

        $accounts = app(UtilityAccountService::class);
        // Такса вход 15 € към фонда.
        $this->fee = $accounts->create([
            'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => '15', 'valid_from' => '2026-01-01', 'note' => 'Такса вход',
            'fund_id' => $this->entrance->id, 'properties' => [['property_id' => $this->a->id]],
        ]);
        // Общият ток на входа, платен от фонда.
        $this->light = $accounts->create([
            'provider_id' => $this->power->id, 'number' => '0077001', 'kind' => 'electricity', 'billing' => 'actual', 'valid_from' => '2026-01-01',
            'paid_from_fund' => '1', 'fund_id' => $this->entrance->id,
            'properties' => [['property_id' => $this->a->id], ['property_id' => $this->b->id], ['property_id' => $this->c->id]],
        ]);
    }

    private function import(int|string $number, float $amount, string $date = '2026-12-05 10:00:00'): PaidUtilityBill
    {
        $this->ref++;
        app(UtilityImporter::class)->import($this->easypayFile([
            ['ref' => $this->ref, 'date' => $date, 'number' => $number, 'provider' => 'IZM EL', 'amount' => $amount],
        ]), 'f.xlsx');

        return PaidUtilityBill::query()->where('reference', (string) $this->ref)->sole();
    }

    private function month(string $month): CarbonImmutable
    {
        return CarbonImmutable::parse("{$month}-01");
    }

    // Сметка, платена от фонда

    public function test_bill_paid_from_the_fund_is_a_fund_expense_and_is_not_split(): void
    {
        // И в прегледа преди вноса: без части по имоти.
        $preview = app(UtilityImporter::class)->preview($this->easypayFile([
            ["ref" => 930001, "date" => "2026-12-05 10:00:00", "number" => 77001, "provider" => "IZM EL", "amount" => 12.40],
        ]));
        $this->assertSame([], $preview->bills[0]->parts);

        $bill = $this->import(77001, 12.40);

        $this->assertSame(0, $bill->parts()->count(), 'не се дели по имоти');
        $entry = LedgerEntry::query()->sole();
        $this->assertSame(LedgerAccount::Utility, $entry->account);
        $this->assertSame(LedgerKind::FundExpense, $entry->kind);
        $this->assertSame(ChargeType::Utility, $entry->type);
        $this->assertSame($this->entrance->id, $entry->fund_id);
        $this->assertNull($entry->property_id, 'без имот');
        $this->assertNull($entry->lease_contract_id);
        $this->assertSame($bill->id, $entry->paid_utility_bill_id);
        $this->assertSame('12.40', $entry->amount, 'платено: 407 +');
        $this->assertSame('2026-12-01', $entry->month->toDateString(), 'в месеца на плащане');
    }

    public function test_bill_paid_from_the_fund_does_not_reach_the_month_or_the_decisions(): void
    {
        $tenant = $this->leaseContract([$this->a]);
        $this->assertNotNull($tenant->id);
        $bill = $this->import(77001, 12.40, '2027-01-05 10:00:00');

        $month = app(UtilityMonth::class);
        $this->assertNotContains($bill->id, $month->bills($this->staff->company_id, $this->month('2027-01'))->pluck('id')->all());
        $proposals = $month->proposals($this->staff->company_id, $this->month('2027-01'));
        $this->assertSame([], array_values(array_filter($proposals['rows'], fn ($row) => $row['bill'] === $bill->id)), 'не се дели на наемателите');
        $this->assertSame([], $proposals['forDecision'], 'не отива „за решение“');
        $this->assertSame(0, $month->notInWorksheet($this->staff->company_id, $this->month('2027-01')));
    }

    // Фиксирана такса: приход във фонда

    public function test_fixed_fee_is_income_of_the_fund(): void
    {
        $entry = $this->ledger->income($this->fee, $this->month('2026-12'), 1500);

        $this->assertSame(LedgerAccount::Utility, $entry->account);
        $this->assertSame(LedgerKind::FundIncome, $entry->kind);
        $this->assertSame(ChargeType::FixedFee, $entry->type);
        $this->assertSame($this->entrance->id, $entry->fund_id);
        $this->assertNull($entry->property_id);
        $this->assertSame('-15.00', $entry->amount, 'събрано: 407 −');
        $this->assertSame('2026-12-01', $entry->month->toDateString());
    }

    public function test_only_a_fixed_account_brings_income_to_a_fund(): void
    {
        $this->expectException(LogicException::class);
        $this->ledger->income($this->light, $this->month('2026-12'), 1500);
    }

    public function test_zero_fee_is_not_booked(): void
    {
        $this->assertNull($this->ledger->income($this->fee, $this->month('2026-12'), 0));
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    // Справката

    public function test_report_collected_paid_and_left_for_a_period_and_in_total(): void
    {
        // Ноември: 15,00 събрани. Декември: 15,00 + 7,50 събрани, 12,40 и 20,00 платени.
        $this->ledger->income($this->fee, $this->month('2026-11'), 1500);
        $this->ledger->income($this->fee, $this->month('2026-12'), 1500);
        $this->ledger->income($this->fee, $this->month('2026-12'), 750);
        $this->import(77001, 12.40, '2026-12-05 10:00:00');
        $this->import(77001, 20.00, '2026-12-20 10:00:00');

        // Друг фонд не се смесва.
        $other = $this->fund([$this->b]);
        $otherFee = app(UtilityAccountService::class)->create([
            'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => '9', 'valid_from' => '2026-01-01',
            'fund_id' => $other->id, 'properties' => [['property_id' => $this->b->id]],
        ]);
        $this->ledger->income($otherFee, $this->month('2026-12'), 900);

        $december = $this->ledger->report($this->entrance, $this->month('2026-12'), $this->month('2026-12'));
        $this->assertSame(['22.50', '32.40', '-9.90'], [$december->collected, $december->paid, $december->left]);

        $total = $this->ledger->report($this->entrance);
        $this->assertSame(['37.50', '32.40', '5.10'], [$total->collected, $total->paid, $total->left]);

        $november = $this->ledger->report($this->entrance, $this->month('2026-11'), $this->month('2026-11'));
        $this->assertSame(['15.00', '0.00', '15.00'], [$november->collected, $november->paid, $november->left]);
    }

    public function test_report_of_a_fund_of_another_company_is_refused(): void
    {
        $foreign = $this->inOtherCompany(fn () => $this->fund([$this->property()]));

        $this->expectException(ModelNotFoundException::class);
        $this->ledger->report($foreign);
    }

    // Справките по имот

    public function test_property_balances_ignore_the_fund_entries(): void
    {
        $own = app(UtilityAccountService::class)->create([
            'provider_id' => $this->power->id, 'number' => '0012345', 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => [['property_id' => $this->a->id]],
        ]);
        $this->assertFalse($own->paid_from_fund);
        $this->import(12345, 48.20);
        $this->import(77001, 12.40);
        $this->ledger->income($this->fee, $this->month('2026-12'), 1500);

        $this->assertSame([$this->a->id => '48.20'], app(UtilityBalances::class)->byProperty($this->staff->company_id));
        $this->assertSame(3, LedgerEntry::query()->where('account', LedgerAccount::Utility)->count(), 'и трите са по 407');
    }

    public function test_fund_entries_do_not_appear_on_the_lease_contract(): void
    {
        $lease = $this->leaseContract([$this->a]);
        $this->ledger->income($this->fee, $this->month('2026-12'), 1500);
        $this->import(77001, 12.40);

        $this->assertSame(0, LedgerEntry::query()->where('lease_contract_id', $lease->id)->count());
    }

    // Защитите в базата

    public function test_database_keeps_fund_entries_apart_from_property_entries(): void
    {
        $bill = $this->import(77001, 12.40);
        $lease = $this->leaseContract([$this->a]);
        $base = [
            'company_id' => $this->staff->company_id, 'month' => '2026-12-01', 'entry_date' => '2026-12-05',
            'account' => '407', 'type' => 'utility', 'amount' => '1.00', 'created_at' => now(),
        ];

        $rejected = 0;
        foreach ([
            ['kind' => 'fund_expense', 'fund_id' => $this->entrance->id, 'property_id' => $this->a->id, 'paid_utility_bill_id' => $bill->id], // фонд с имот
            ['kind' => 'manual', 'fund_id' => null, 'property_id' => null],                                                                  // 407 без фонд и без имот
            ['kind' => 'fund_expense', 'fund_id' => $this->entrance->id, 'property_id' => null],                                             // разход без сметка
            ['kind' => 'fund_expense', 'fund_id' => null, 'property_id' => $this->a->id, 'paid_utility_bill_id' => $bill->id],               // разход без фонд
            ['kind' => 'fund_income', 'fund_id' => $this->entrance->id, 'property_id' => null, 'amount' => '15.00'],                         // приходът е 407 −
            ['kind' => 'utility_paid', 'fund_id' => $this->entrance->id, 'property_id' => null, 'paid_utility_bill_id' => $bill->id],        // 407 на имот към фонд
            ['kind' => 'manual', 'account' => '418', 'type' => 'rent', 'lease_contract_id' => $lease->id, 'fund_id' => $this->entrance->id, 'property_id' => $this->a->id], // 418 към фонд
        ] as $row) {
            try {
                DB::transaction(fn () => DB::table('ledger_entries')->insert(array_merge($base, $row)));
            } catch (QueryException) {
                $rejected++;
            }
        }

        $this->assertSame(7, $rejected);

        // Същият ред по 418, но без фонд, се приема: отказът е заради фонда.
        DB::table('ledger_entries')->insert(array_merge($base, ['kind' => 'manual', 'account' => '418', 'type' => 'rent', 'lease_contract_id' => $lease->id, 'property_id' => $this->a->id]));
        $this->assertSame(1, LedgerEntry::query()->where('account', '418')->count());
    }

    public function test_fund_entries_are_never_changed_or_deleted(): void
    {
        $entry = $this->ledger->income($this->fee, $this->month('2026-12'), 1500);

        foreach ([fn () => $entry->update(['amount' => '-1.00']), fn () => $entry->delete()] as $change) {
            try {
                $change();
                $this->fail('Записът в книгата трябваше да остане непроменен.');
            } catch (LogicException) {
            }
        }

        $this->assertSame('-15.00', $entry->fresh()->amount);
    }
}
