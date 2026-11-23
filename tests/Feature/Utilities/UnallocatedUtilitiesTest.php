<?php

namespace Tests\Feature\Utilities;

use App\Enums\ChargeType;
use App\Enums\LedgerKind;
use App\Models\ChargeDraft;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\PaidUtilityBill;
use App\Models\Property;
use App\Models\Provider;
use App\Models\User;
use App\Models\UserProperty;
use App\Models\UtilityAccount;
use App\Models\UtilityDecision;
use App\Services\Charges\Issuer;
use App\Services\Charges\MonthWorksheet;
use App\Services\Utilities\UnallocatedItem;
use App\Services\Utilities\UnallocatedUtilities;
use App\Services\Utilities\UtilityAccountService;
use App\Services\Utilities\UtilityBalances;
use App\Services\Utilities\UtilityDecisions;
use App\Services\Utilities\UtilityImporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Contracts\ContractTestCase;
use Tests\Support\Funds;
use Tests\Support\UtilityFiles;

/**
 * „Неразпределени битови сметки“ (PROJECT.md, т. 3.2, т. 3.4 и „Част 5“,
 * решено 23.11.2026):
 *
 * - основният списък: сметките (по имот) с 407 на имота различно от 0, които
 *   искат решение — празен имот, напуснал наемател след срока (предложено
 *   „за сметка на управителя“), ръчно намален или махнат ред, частично
 *   начислена; сметките от фонд не са тук;
 * - „Чакат месеца“: внесени, но още не в издаден месец — без бутони;
 * - „за сметка на управителя“: 407 − за остатъка, отделен вид; отменя се с
 *   обратен запис (книгата е само за добавяне);
 * - „към следващия наемател“: само ако има договор за имота след периода;
 *   редът излиза при следващото генериране; отменя се, докато не е издаден;
 * - нищо не отива към собственика; решава само „четене и запис“ + „всички имоти“.
 *
 * Само измислени номера, имена и суми.
 */
class UnallocatedUtilitiesTest extends ContractTestCase
{
    use Funds, UtilityFiles;

    private MonthWorksheet $sheet;

    private Issuer $issuer;

    private UtilityDecisions $decisions;

    private Provider $power;

    private Property $flat;

    private UtilityAccount $meter;

    private int $ref = 870000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sheet = app(MonthWorksheet::class);
        $this->issuer = app(Issuer::class);
        $this->decisions = app(UtilityDecisions::class);
        $this->power = Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL']);
        $this->flat = $this->property();
        $this->percentContract([$this->flat]);
        $this->meter = $this->account('0012345', $this->flat);
    }

    private function account(string $number, Property $property, array $extra = []): UtilityAccount
    {
        return app(UtilityAccountService::class)->create(array_merge([
            'provider_id' => $this->power->id, 'number' => $number, 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => [['property_id' => $property->id]],
        ], $extra));
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

    private function issue(string $month): void
    {
        $this->sheet->generate($month);
        $this->issuer->issueMonth($month);
    }

    /** @return array{decide: list<UnallocatedItem>, waiting: list<UnallocatedItem>} */
    private function items(): array
    {
        return app(UnallocatedUtilities::class)->items();
    }

    /** Основният списък: [сметка, имот, платено, начислено, остатък, причина]. */
    private function decide(): array
    {
        return array_map(fn (UnallocatedItem $i) => [$i->bill->id, $i->propertyId, $i->paid, $i->charged, $i->left, $i->reason], $this->items()['decide']);
    }

    private function balance(?Property $property = null): ?string
    {
        return app(UtilityBalances::class)->byProperty($this->staff->company_id)[($property ?? $this->flat)->id] ?? null;
    }

    // Какво влиза в списъка

    public function test_vacant_bill_needs_a_decision(): void
    {
        $bill = $this->bill($this->meter, '2026-12-05', 48.20); // никой през ноември

        $this->assertSame([[$bill->id, $this->flat->id, '48.20', '0.00', '48.20', 'vacant']], $this->decide());
        $this->assertSame('not_charged', $this->items()['decide'][0]->status);
        $this->assertNull($this->items()['decide'][0]->suggested);
        $this->assertSame([], $this->items()['waiting']);
    }

    public function test_former_tenant_after_the_deadline_is_listed_with_the_manager_suggested_and_nothing_is_booked(): void
    {
        // А е до 15.10: последното начисление е в декември. Сметката за октомври е внесена след издаването на декември.
        $this->lease($this->flat, '2026-01-10', '2026-10-15');
        $this->issue('2026-11');
        $this->issue('2026-12');
        $bill = $this->bill($this->meter, '2026-11-05', 35.00);

        $this->assertSame([[$bill->id, $this->flat->id, '35.00', '0.00', '35.00', 'deadline_passed']], $this->decide());
        $this->assertSame(UtilityDecision::MANAGER, $this->items()['decide'][0]->suggested);
        $this->assertSame(0, LedgerEntry::query()->where('kind', LedgerKind::ManagerCost)->count(), 'нищо не се записва само');
    }

    public function test_reduced_and_removed_rows_of_an_issued_month_are_listed(): void
    {
        $other = $this->property();
        $this->percentContract([$other]);
        $second = $this->account('0012346', $other);
        $a = $this->lease($this->flat, '2026-01-10');
        $b = $this->lease($other, '2026-01-10');
        $reduced = $this->bill($this->meter, '2026-12-05', 90.00);
        $removed = $this->bill($second, '2026-12-06', 40.00);
        $this->sheet->generate('2026-12');
        $this->sheet->change($this->row($a), ['amount' => '60', 'reason' => 'отстъпка']);
        $this->sheet->remove($this->row($b), 'платено на място');
        $this->issuer->issueMonth('2026-12');

        $this->assertEqualsCanonicalizing([
            [$reduced->id, $this->flat->id, '90.00', '60.00', '30.00', 'reduced'],
            [$removed->id, $other->id, '40.00', '0.00', '40.00', 'removed'],
        ], $this->decide());
        $statuses = array_map(fn ($i) => $i->status, $this->items()['decide']);
        $this->assertEqualsCanonicalizing(['partly', 'not_charged'], $statuses);
    }

    public function test_bills_waiting_for_their_month_are_in_a_separate_section(): void
    {
        $this->lease($this->flat, '2026-01-10');
        $bill = $this->bill($this->meter, '2026-12-05', 90.00);

        $this->assertSame([], $this->items()['decide']);
        $waiting = $this->items()['waiting'];
        $this->assertSame([[$bill->id, 'month', '2026-12']], array_map(fn ($i) => [$i->bill->id, $i->waiting, $i->month->format('Y-m')], $waiting));

        $this->sheet->generate('2026-12');
        $this->assertSame([['worksheet', '2026-12']], array_map(fn ($i) => [$i->waiting, $i->month->format('Y-m')], $this->items()['waiting']));

        $this->issuer->issueMonth('2026-12');
        $this->assertSame(['decide' => [], 'waiting' => []], $this->items(), 'изцяло начислена: нищо не остава');
    }

    public function test_rest_left_by_issue_now_before_generating_is_listed_once_a_later_month_is_generated(): void
    {
        // Остатъчният случай от 4б: „Издай сега“ за А в негенериран декември, после направо януари.
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $this->lease($this->flat, '2026-11-11');
        $bill = $this->bill($this->meter, '2026-12-05', 90.00);
        $this->issuer->issueNow($a, '2026-12');
        $this->assertSame([], $this->decide());

        $this->sheet->generate('2027-01');

        $this->assertSame([[$bill->id, $this->flat->id, '90.00', '30.00', '60.00', 'partial']], $this->decide());
    }

    public function test_bills_paid_from_a_fund_are_not_listed(): void
    {
        $fund = $this->fund([$this->flat]);
        $this->account('0077001', $this->flat, ['paid_from_fund' => '1', 'fund_id' => $fund->id]);
        $this->bill(UtilityAccount::query()->where('number', '0077001')->sole(), '2026-12-05', 12.40);

        $this->assertSame(['decide' => [], 'waiting' => []], $this->items());
    }

    // „За сметка на управителя“

    public function test_manager_decision_books_407_minus_for_the_rest_and_the_bill_leaves_the_list(): void
    {
        $bill = $this->bill($this->meter, '2026-12-05', 48.20);

        $decision = $this->decisions->toManager($bill, $this->flat->id, 'празен апартамент, ремонт');

        $entry = LedgerEntry::query()->where('kind', LedgerKind::ManagerCost)->sole();
        $this->assertSame(['407', $this->flat->id, $bill->id, $decision->id, null, null, '-48.20'],
            [$entry->account->value, $entry->property_id, $entry->paid_utility_bill_id, $entry->utility_decision_id, $entry->fund_id, $entry->lease_contract_id, $entry->amount]);
        $this->assertSame('0.00', $this->balance());
        $this->assertSame(['decide' => [], 'waiting' => []], $this->items());
        $this->assertSame([UtilityDecision::MANAGER, '48.20', 'празен апартамент, ремонт', $this->staff->id],
            [$decision->kind, $decision->amount, $decision->reason, $decision->decided_by]);

        // И не е „за решение“ в работната таблица на следващия месец.
        $this->assertSame([], $this->sheet->utilitiesForDecision('2027-01'));
    }

    public function test_manager_decision_for_the_rest_of_a_reduced_row(): void
    {
        $lease = $this->lease($this->flat, '2026-01-10');
        $bill = $this->bill($this->meter, '2026-12-05', 90.00);
        $this->sheet->generate('2026-12');
        $this->sheet->change($this->row($lease), ['amount' => '60', 'reason' => 'отстъпка']);
        $this->issuer->issueMonth('2026-12');

        $this->decisions->toManager($bill, $this->flat->id, 'отстъпката е за наша сметка');

        $this->assertSame('-30.00', LedgerEntry::query()->where('kind', LedgerKind::ManagerCost)->sole()->amount);
        $this->assertSame('0.00', $this->balance());
    }

    public function test_manager_decision_is_cancelled_with_a_reverse_entry_and_the_bill_comes_back(): void
    {
        $bill = $this->bill($this->meter, '2026-12-05', 48.20);
        $decision = $this->decisions->toManager($bill, $this->flat->id, 'празен');
        $first = LedgerEntry::query()->where('kind', LedgerKind::ManagerCost)->sole()->toArray();

        $this->decisions->cancel($decision, 'ще го поеме следващият наемател');

        $entries = LedgerEntry::query()->where('kind', LedgerKind::ManagerCost)->orderBy('id')->get();
        $this->assertSame(['-48.20', '48.20'], $entries->pluck('amount')->all(), 'обратен запис, без промяна на първия');
        $this->assertSame($first, $entries[0]->toArray());
        $this->assertSame('ще го поеме следващият наемател', $entries[1]->reason);
        $this->assertNotNull($decision->fresh()->cancelled_at);
        $this->assertSame('48.20', $this->balance());
        $this->assertSame([[$bill->id, $this->flat->id, '48.20', '0.00', '48.20', 'vacant']], $this->decide());

        // Отменено решение не се отменя втори път.
        $this->assertRejected(fn () => $this->decisions->cancel($decision->fresh(), 'пак'), 'decision');
    }

    // „Към следващия наемател“

    public function test_next_tenant_gets_the_rest_in_the_next_generated_month(): void
    {
        $bill = $this->bill($this->meter, '2026-12-05', 48.20); // ноември празен
        $next = $this->lease($this->flat, '2026-12-01');
        $this->issue('2026-12');

        $decision = $this->decisions->toNextTenant($bill, $this->flat->id, $next, 'по уговорка с новия наемател');
        $this->assertSame(0, LedgerEntry::query()->where('kind', LedgerKind::ManagerCost)->count(), 'при решението книгата не се пипа');
        $this->assertSame([[$bill->id, 'next_tenant']], array_map(fn ($i) => [$i->bill->id, $i->waiting], $this->items()['waiting']));
        $this->assertSame([], $this->decide());

        $this->sheet->generate('2027-01');
        $row = ChargeDraft::query()->whereDate('month', '2027-01-01')->where('utility_decision_id', $decision->id)->sole();
        $this->assertSame([ChargeType::Utility, $next->id, $this->flat->id, $bill->id, '48.20', '2026-11-01', '2026-11-30'],
            [$row->type, $row->lease_contract_id, $row->property_id, $row->paid_utility_bill_id, $row->amount, $row->period_from->toDateString(), $row->period_to->toDateString()]);
        $this->assertSame(__('utilities.unallocated.next_tenant_note'), $row->note);

        $this->issuer->issueMonth('2027-01');
        $this->assertSame('48.20', LedgerEntry::query()->where('account', '418')->where('type', ChargeType::Utility)->where('lease_contract_id', $next->id)->sole()->amount);
        $this->assertSame('0.00', $this->balance());
        $this->assertSame(['decide' => [], 'waiting' => []], $this->items());
    }

    public function test_next_tenant_needs_a_contract_on_the_property_after_the_period(): void
    {
        $before = $this->lease($this->flat, '2026-01-10', '2026-10-31'); // напуснал преди периода
        $bill = $this->bill($this->meter, '2026-12-05', 48.20);
        $elsewhere = $this->lease($this->property(), '2026-12-01');

        $this->assertSame([], $this->items()['decide'][0]->nextLeases, 'няма договор след периода: няма бутон');
        $this->assertRejected(fn () => $this->decisions->toNextTenant($bill, $this->flat->id, $before, 'опит'), 'lease_contract_id');
        $this->assertRejected(fn () => $this->decisions->toNextTenant($bill, $this->flat->id, $elsewhere, 'опит'), 'lease_contract_id');

        $next = $this->lease($this->flat, '2026-12-01');
        $this->assertSame([$next->id], $this->items()['decide'][0]->nextLeases);
    }

    public function test_next_tenant_decision_is_cancelled_until_its_row_is_issued(): void
    {
        $bill = $this->bill($this->meter, '2026-12-05', 48.20);
        $next = $this->lease($this->flat, '2026-12-01');
        $this->issue('2026-12');
        $decision = $this->decisions->toNextTenant($bill, $this->flat->id, $next, 'уговорка');
        $this->sheet->generate('2027-01');

        $this->decisions->cancel($decision, 'наемателят отказа');

        $this->assertSame(0, ChargeDraft::query()->where('utility_decision_id', $decision->id)->count(), 'редът излиза от таблицата');
        $this->sheet->generate('2027-01');
        $this->assertSame(0, ChargeDraft::query()->where('utility_decision_id', $decision->id)->count());
        $this->assertSame([[$bill->id, $this->flat->id, '48.20', '0.00', '48.20', 'vacant']], $this->decide());

        // След издаване — не се отменя (поправката е ръчна корекция).
        $again = $this->decisions->toNextTenant($bill, $this->flat->id, $next, 'пак уговорка');
        $this->issue('2027-01');
        $this->assertRejected(fn () => $this->decisions->cancel($again, 'късно'), 'decision');
    }

    // Правила за решенията

    public function test_a_decision_needs_a_reason_and_only_one_per_bill_and_property(): void
    {
        $bill = $this->bill($this->meter, '2026-12-05', 48.20);

        $this->assertRejected(fn () => $this->decisions->toManager($bill, $this->flat->id, ' '), 'reason');
        $this->decisions->toManager($bill, $this->flat->id, 'празен');
        $this->assertRejected(fn () => $this->decisions->toManager($bill, $this->flat->id, 'втори път'), 'decision');
        $this->assertSame(1, UtilityDecision::query()->count());
    }

    public function test_a_waiting_bill_cannot_be_decided(): void
    {
        $this->lease($this->flat, '2026-01-10');
        $bill = $this->bill($this->meter, '2026-12-05', 90.00);

        $this->assertRejected(fn () => $this->decisions->toManager($bill, $this->flat->id, 'опит'), 'decision');
        $this->assertSame(0, LedgerEntry::query()->where('kind', LedgerKind::ManagerCost)->count());
    }

    public function test_nothing_goes_to_the_owner(): void
    {
        $bill = $this->bill($this->meter, '2026-12-05', 48.20);
        $next = $this->lease($this->flat, '2026-12-01');
        $this->decisions->toNextTenant($bill, $this->flat->id, $next, 'уговорка');
        $this->issue('2026-12');
        $before = LedgerEntry::query()->where('account', '408')->count();

        $other = $this->bill($this->account('0012399', $this->flat), '2026-12-07', 20.00);
        $this->decisions->toManager($other, $this->flat->id, 'празен');

        $this->assertSame($before, LedgerEntry::query()->where('account', '408')->count());
        $this->assertSame(0, LedgerEntry::query()->where('account', '408')->where('type', ChargeType::Utility)->count());
    }

    public function test_database_keeps_manager_entries_tied_to_a_decision_bill_and_property(): void
    {
        $bill = $this->bill($this->meter, '2026-12-05', 48.20);
        $base = ['company_id' => $this->staff->company_id, 'month' => '2026-12-01', 'entry_date' => '2026-12-05', 'account' => '407',
            'type' => 'utility', 'kind' => 'manager_cost', 'amount' => '-1.00', 'created_at' => now()];

        $rejected = 0;
        foreach ([
            ['property_id' => $this->flat->id, 'paid_utility_bill_id' => $bill->id],                 // без решение
            ['property_id' => null, 'paid_utility_bill_id' => $bill->id, 'fund_id' => $this->fund([$this->flat])->id], // на фонд
        ] as $row) {
            try {
                DB::transaction(fn () => DB::table('ledger_entries')->insert(array_merge($base, $row)));
            } catch (\Illuminate\Database\QueryException) {
                $rejected++;
            }
        }
        $this->assertSame(2, $rejected);
    }

    // Достъп и фирми

    public function test_read_only_staff_sees_the_list_but_cannot_decide(): void
    {
        $bill = $this->bill($this->meter, '2026-12-05', 48.20);
        $this->actingAs(User::factory()->create(['company_id' => $this->staff->company_id, 'access_level' => 'read']));

        $this->assertCount(1, $this->items()['decide']);
        $this->expectException(AuthorizationException::class);
        $this->decisions->toManager($bill, $this->flat->id, 'опит');
    }

    public function test_assigned_only_staff_sees_only_bills_of_their_properties_and_cannot_decide(): void
    {
        $other = $this->property();
        $this->bill($this->meter, '2026-12-05', 48.20);
        $hidden = $this->bill($this->account('0012346', $other), '2026-12-06', 30.00);
        $assigned = User::factory()->assignedPropertiesOnly()->create(['company_id' => $this->staff->company_id]);
        UserProperty::query()->create(['user_id' => $assigned->id, 'property_id' => $this->flat->id]);
        $this->actingAs($assigned);

        $this->assertSame([$this->flat->id], array_map(fn ($i) => $i->propertyId, $this->items()['decide']));
        $this->expectException(AuthorizationException::class);
        $this->decisions->toManager($hidden, $other->id, 'опит');
    }

    public function test_company_a_does_not_see_or_decide_bills_of_company_b(): void
    {
        $foreign = $this->inOtherCompany(function () {
            $provider = Provider::query()->create(['name' => 'Друго Електро', 'easypay_name' => 'DRUGO EL']);
            $property = $this->property();
            app(UtilityAccountService::class)->create([
                'provider_id' => $provider->id, 'number' => '999', 'kind' => 'electricity', 'billing' => 'actual',
                'valid_from' => '2026-01-01', 'properties' => [['property_id' => $property->id]],
            ]);
            app(UtilityImporter::class)->import($this->easypayFile([
                ['ref' => 1, 'date' => '2026-12-05 10:00:00', 'number' => 999, 'provider' => 'DRUGO EL', 'amount' => 70],
            ]), 'f.xlsx');

            return [PaidUtilityBill::query()->sole(), $property];
        });

        $this->assertSame(['decide' => [], 'waiting' => []], $this->items());
        $this->assertRejected(fn () => $this->decisions->toManager($foreign[0], $foreign[1]->id, 'опит'), 'decision');
        $this->assertSame(0, UtilityDecision::query()->withoutGlobalScopes()->count());
    }

    private function row(LeaseContract $lease, string $month = '2026-12'): ChargeDraft
    {
        return ChargeDraft::query()->whereDate('month', "{$month}-01")->where('type', ChargeType::Utility)->where('lease_contract_id', $lease->id)->sole();
    }
}
