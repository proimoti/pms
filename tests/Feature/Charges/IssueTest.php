<?php

namespace Tests\Feature\Charges;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\ChargeDraft;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\Property;
use App\Models\Statement;
use App\Services\Charges\Issuer;
use App\Services\Charges\MonthWorksheet;
use App\Services\Charges\Reconciliation;
use App\Services\Charges\UpcomingChargeService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Contracts\ContractTestCase;

/**
 * Издаване и заключване (PROJECT.md, т. 2 „Издаване и заключване“, „Начисления,
 * правила“, „Дял на собственика“): известие (сметка) за всеки договор за наем,
 * записи в книгата (418 наемател, 491 депозит, 408 собственик), заключен месец,
 * „Издай сега“, сверка и корекции за същия месец. Всичко или нищо; без двойни
 * записи в книгата.
 */
class IssueTest extends ContractTestCase
{
    private MonthWorksheet $sheet;

    private Issuer $issuer;

    private Reconciliation $reconciliation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sheet = app(MonthWorksheet::class);
        $this->issuer = app(Issuer::class);
        $this->reconciliation = app(Reconciliation::class);
    }

    /** Имот с договор за управление 10% и наем 600 € (депозит 1200 €) от 10.01.2026. */
    private function rented(array $line = [], array $lease = []): array
    {
        $property = $this->property();
        $management = $this->percentContract([$property]);
        $contract = $this->leaseContract([], array_merge([
            'lines' => [array_merge(['property_id' => $property->id, 'rent' => '600', 'deposit' => '1200'], $line)],
        ], $lease));

        return [$property, $contract, $management];
    }

    private function sum(array $where): string
    {
        $cents = LedgerEntry::query()->where($where)->get()->sum(fn ($e) => (int) round((float) $e->amount * 100));

        return number_format($cents / 100, 2, '.', '');
    }

    private function draft(Property $property, ChargeType $type, string $month = '2026-12'): ChargeDraft
    {
        return ChargeDraft::query()->where('property_id', $property->id)->where('type', $type)->whereDate('month', $month.'-01')->sole();
    }

    // Издаване на месеца

    public function test_issuing_the_month_writes_statements_and_the_ledger_and_locks_the_month(): void
    {
        [$a, $leaseA, $management] = $this->rented();
        [$garage, $leaseG] = $this->rented(['rent' => '0']);
        app(UpcomingChargeService::class)->create(['lease_contract_id' => $leaseA->id, 'property_id' => $a->id,
            'type' => 'deposit', 'month' => '2026-12', 'amount' => '600']);
        $this->sheet->generate('2026-12');
        $this->sheet->addManual('2026-12', ['type' => 'penalty', 'lease_contract_id' => $leaseA->id, 'property_id' => $a->id,
            'amount' => '50', 'reason' => 'По договора']);

        $this->issuer->issueMonth('2026-12');

        $statements = Statement::query()->orderBy('number')->get();
        $this->assertSame([1, 2], $statements->pluck('number')->all());
        $first = $statements->firstWhere('lease_contract_id', $leaseA->id);
        $this->assertSame('1250.00', $first->total);
        $this->assertSame($leaseA->tenant_id, $first->tenant_id);
        $this->assertCount(3, $first->lines);
        // Редът със сума 0 е в известието, но не и в книгата.
        $this->assertCount(1, $statements->firstWhere('lease_contract_id', $leaseG->id)->lines);
        $this->assertSame(0, LedgerEntry::query()->where('lease_contract_id', $leaseG->id)->count());

        $this->assertSame('1250.00', $this->sum(['account' => LedgerAccount::Tenant, 'lease_contract_id' => $leaseA->id]));
        $this->assertSame('600.00', $this->sum(['account' => LedgerAccount::Deposit, 'lease_contract_id' => $leaseA->id]));
        $this->assertSame('540.00', $this->sum(['account' => LedgerAccount::Owner, 'owner_contract_id' => $management->id]));

        $entry = LedgerEntry::query()->where('type', ChargeType::Rent)->where('lease_contract_id', $leaseA->id)->sole();
        $this->assertSame('2026-12-01', $entry->month->toDateString());
        $this->assertSame(now()->toDateString(), $entry->entry_date->toDateString());
        $this->assertSame(LedgerKind::Issue, $entry->kind);
        $this->assertSame($first->id, $entry->statement_id);
        $this->assertSame($this->staff->id, $entry->created_by);

        $this->assertTrue($this->issuer->isLocked('2026-12'));
        $this->assertSame(1, PeriodLock::query()->count());
        $this->assertSame($first->id, $this->draft($a, ChargeType::Rent)->statement_id);
    }

    /** т. 3.6: свободен имот при фиксирана сума — собственикът получава сумата, известие към наемател няма. */
    public function test_free_property_with_fixed_amount_is_booked_for_the_owner_without_a_statement(): void
    {
        $property = $this->property();
        $management = $this->fixedContract([$property]);
        $this->sheet->generate('2026-12');

        $this->issuer->issueMonth('2026-12');

        $this->assertSame(0, Statement::query()->count());
        $this->assertSame('460.00', $this->sum(['account' => LedgerAccount::Owner, 'owner_contract_id' => $management->id]));
        $entry = LedgerEntry::query()->sole();
        $this->assertSame(LedgerAccount::Owner, $entry->account);
        $this->assertSame($management->owner_id, $entry->owner_id);
        $this->assertNull($entry->statement_id);
        $this->assertNull($entry->lease_contract_id);
        $this->assertSame(0, LedgerEntry::query()->where('account', LedgerAccount::Tenant)->count());
    }

    /** т. 3.6: нает имот при фиксирана сума — към собственика е фиксираната сума, не наемът. */
    public function test_rented_property_with_fixed_amount_books_the_fixed_amount_for_the_owner(): void
    {
        $property = $this->property();
        $management = $this->fixedContract([$property]);
        $lease = $this->leaseContract([], ['lines' => [['property_id' => $property->id, 'rent' => '500']]]);
        $this->sheet->generate('2026-12');

        $this->issuer->issueMonth('2026-12');

        $this->assertSame('500.00', $this->sum(['account' => LedgerAccount::Tenant, 'lease_contract_id' => $lease->id]));
        $this->assertSame('460.00', $this->sum(['account' => LedgerAccount::Owner, 'owner_contract_id' => $management->id]));
        $this->assertSame([], $this->reconciliation->differences('2026-12'));
    }

    /**
     * PROJECT.md, „Начисления, уточнения“: изтекъл срок (дата край) без дата на
     * прекратяване не е „без договор за управление“ — договорът продължава да
     * действа, наемът се начислява нормално и делът на собственика се записва.
     */
    public function test_management_contract_with_expired_term_but_no_termination_still_applies(): void
    {
        $property = $this->property();
        $management = $this->percentContract([$property], ['end_on' => '2026-06-30']);
        $lease = $this->leaseContract([$property]);
        $this->assertNull($management->fresh()->terminated_on);

        $this->assertSame([], app(\App\Services\Charges\ChargeCalculator::class)->forMonth($this->staff->company_id, '2026-12')->forDecision());
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');

        $this->assertSame('600.00', $this->sum(['account' => LedgerAccount::Tenant, 'lease_contract_id' => $lease->id]));
        $this->assertSame('540.00', $this->sum(['account' => LedgerAccount::Owner, 'owner_contract_id' => $management->id]));
        $this->assertSame(1, Statement::query()->count());
    }

    public function test_numbers_continue_across_months(): void
    {
        $this->rented();
        $this->sheet->generate('2026-11');
        $this->issuer->issueMonth('2026-11');
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');

        $this->assertSame([1, 2], Statement::query()->orderBy('number')->pluck('number')->all());
    }

    public function test_month_must_be_generated_before_issuing(): void
    {
        $this->rented();

        $this->assertRejected(fn () => $this->issuer->issueMonth('2026-12'), 'month');
        $this->assertSame(0, Statement::query()->count());
    }

    // Двойно издаване и всичко или нищо

    public function test_issuing_twice_is_refused_and_writes_nothing_more(): void
    {
        $this->rented();
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');
        $entries = LedgerEntry::query()->count();

        $this->assertRejected(fn () => $this->issuer->issueMonth('2026-12'), 'month');

        $this->assertSame($entries, LedgerEntry::query()->count());
        $this->assertSame(1, Statement::query()->count());
        $this->assertSame(1, PeriodLock::query()->count());
    }

    public function test_issue_now_twice_is_refused(): void
    {
        [, $lease] = $this->rented();

        $this->issuer->issueNow($lease, '2026-12');
        $entries = LedgerEntry::query()->count();

        $this->assertRejected(fn () => $this->issuer->issueNow($lease, '2026-12'), 'month');
        $this->assertSame($entries, LedgerEntry::query()->count());
        $this->assertSame(1, Statement::query()->count());
    }

    public function test_the_database_refuses_a_second_statement_for_the_same_contract_and_month(): void
    {
        [, $lease] = $this->rented();
        $statement = $this->issuer->issueNow($lease, '2026-12');

        $this->expectException(QueryException::class);
        DB::table('statements')->insert(array_merge(
            collect($statement->getAttributes())->except(['id'])->all(), ['number' => 99]));
    }

    public function test_issuing_is_all_or_nothing(): void
    {
        $this->rented();
        $this->rented();
        $this->sheet->generate('2026-12');

        // Третият запис в книгата се проваля: нищо не трябва да остане.
        $count = 0;
        LedgerEntry::creating(function () use (&$count) {
            if (++$count === 3) {
                throw new \RuntimeException('Измислена грешка');
            }
        });

        try {
            $this->issuer->issueMonth('2026-12');
            $this->fail('Очаквах грешка.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame(0, Statement::query()->count());
        $this->assertSame(0, PeriodLock::query()->count());
        $this->assertSame(0, ChargeDraft::query()->whereNotNull('statement_id')->count());
    }

    // Заключеният месец

    public function test_issued_month_cannot_be_changed(): void
    {
        [$property, $lease] = $this->rented();
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');
        $rent = $this->draft($property, ChargeType::Rent);

        $this->assertRejected(fn () => $this->sheet->generate('2026-12'), 'month');
        $this->assertRejected(fn () => $this->sheet->change($rent, ['amount' => '1', 'reason' => 'x']), 'month');
        $this->assertRejected(fn () => $this->sheet->remove($rent, 'x'), 'month');
        $this->assertRejected(fn () => $this->sheet->addManual('2026-12', ['type' => 'penalty', 'lease_contract_id' => $lease->id,
            'property_id' => $property->id, 'amount' => '5', 'reason' => 'x']), 'month');
        $this->assertRejected(fn () => app(UpcomingChargeService::class)->create(['lease_contract_id' => $lease->id,
            'property_id' => $property->id, 'type' => 'penalty', 'month' => '2026-12', 'amount' => '5']), 'month');
        $this->assertSame('600.00', $rent->fresh()->amount);
    }

    public function test_charges_lock_numbers_and_amount_fields_of_contracts(): void
    {
        [$property, $lease, $management] = $this->rented();
        $this->assertFalse($lease->hasCharges());
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');

        $this->assertTrue($lease->fresh()->hasCharges());
        $this->assertTrue($management->fresh()->hasCharges());
        foreach ([$property, $lease->tenant, $management->owner, $lease, $management] as $record) {
            $this->assertTrue($record->fresh()->isNumberLocked(), $record::class);
        }

        $this->assertRejected(fn () => $property->fresh()->update(['number' => 999]), 'number');
        $this->assertRejected(fn () => $this->leases->correct($lease->fresh(), ['lines' => [$lease->lines()->sole()->id => ['rent' => '700']]]), 'changes');
        $this->assertRejected(fn () => $lease->fresh()->archive(), 'archived_at');

        // Информационните полета и прекратяването остават.
        $lease->fresh()->update(['note' => 'Измислена бележка', 'terminated_on' => '2027-03-01']);
        $this->assertSame('Измислена бележка', $lease->fresh()->note);
    }

    // „Издай сега“

    public function test_issue_now_for_a_new_contract_and_then_the_month_does_not_double_it(): void
    {
        $property = $this->property();
        $management = $this->percentContract([$property]);
        $lease = $this->leaseContract([], ['signed_on' => '2026-12-16',
            'lines' => [['property_id' => $property->id, 'rent' => '600', 'deposit' => '1200']]]);
        app(UpcomingChargeService::class)->create(['lease_contract_id' => $lease->id, 'property_id' => $property->id,
            'type' => 'deposit', 'month' => '2026-12', 'amount' => '1200']);

        $statement = $this->issuer->issueNow($lease, '2026-12');

        $this->assertSame('1509.68', $statement->total); // 600 × 16 ÷ 31 + депозит
        $this->assertSame('278.71', $this->sum(['account' => LedgerAccount::Owner, 'owner_contract_id' => $management->id])); // 309,68 − 30,97
        $this->assertFalse($this->issuer->isLocked('2026-12'));

        // Друг договор в същия месец, после „Издай месеца“: договорът от „Издай сега“ не се издава втори път.
        $this->rented();
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');

        $this->assertSame(1, Statement::query()->where('lease_contract_id', $lease->id)->count());
        $this->assertSame('309.68', $this->sum(['account' => LedgerAccount::Tenant, 'lease_contract_id' => $lease->id, 'type' => ChargeType::Rent]));
        $this->assertSame('278.71', $this->sum(['account' => LedgerAccount::Owner, 'owner_contract_id' => $management->id]));
    }

    public function test_issue_now_for_a_missed_contract_in_an_issued_month(): void
    {
        $this->rented();
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');

        // Договорът е въведен след издаването, а започва в декември.
        $property = $this->property();
        $this->percentContract([$property]);
        $late = $this->leaseContract([], ['signed_on' => '2026-12-20', 'lines' => [['property_id' => $property->id, 'rent' => '310']]]);

        $this->assertContains(Issuer::MISSED, array_map(fn ($w) => $w->code, $this->issuer->warnings('2027-01')));

        $statement = $this->issuer->issueNow($late, '2026-12');
        $this->assertSame('120.00', $statement->total); // 310 × 12 ÷ 31
        $this->assertNotContains(Issuer::MISSED, array_map(fn ($w) => $w->code, $this->issuer->warnings('2027-01')));
    }

    public function test_issue_now_needs_something_to_issue(): void
    {
        $property = $this->property();
        $this->percentContract([$property], ['signed_on' => '2026-12-05']);
        $lease = $this->leaseContract([$property]); // ден без договор за управление: „за решение“

        $this->assertRejected(fn () => $this->issuer->issueNow($lease, '2026-12'), 'month');
        $this->assertSame(0, Statement::query()->count());
    }

    public function test_for_decision_contracts_are_not_issued_with_the_month(): void
    {
        $this->rented();
        $property = $this->property();
        $this->percentContract([$property], ['signed_on' => '2026-12-05']);
        $waiting = $this->leaseContract([$property]);
        $this->sheet->generate('2026-12');

        $this->issuer->issueMonth('2026-12');

        $this->assertSame(0, Statement::query()->where('lease_contract_id', $waiting->id)->count());
        $this->assertSame(1, Statement::query()->count());
    }

    // Сверка и корекции за същия месец

    public function test_termination_after_issuing_shows_a_difference_and_the_correction_fixes_it(): void
    {
        [$property, $lease, $management] = $this->rented();
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');
        $statement = Statement::query()->sole();

        $lease->fresh()->update(['terminated_on' => '2026-12-16']);

        $differences = $this->reconciliation->differences('2026-12');
        $this->assertCount(2, $differences);
        $tenant = collect($differences)->firstWhere('account', LedgerAccount::Tenant);
        $this->assertSame('600.00', $tenant->booked);
        $this->assertSame('290.32', $tenant->target); // 600 × 15 ÷ 31
        $this->assertSame('-309.68', $tenant->difference);
        $owner = collect($differences)->firstWhere('account', LedgerAccount::Owner);
        $this->assertSame('-278.71', $owner->difference); // 261,29 − 540,00

        $this->assertRejected(fn () => $this->reconciliation->correct('2026-12', $tenant->key, ''), 'reason');
        $this->reconciliation->correct('2026-12', $tenant->key, 'Прекратен на 16.12');
        $this->reconciliation->correct('2026-12', $owner->key, 'Прекратен на 16.12');

        $this->assertSame([], $this->reconciliation->differences('2026-12'));
        $this->assertSame('290.32', $this->sum(['account' => LedgerAccount::Tenant, 'lease_contract_id' => $lease->id]));
        $this->assertSame('261.29', $this->sum(['account' => LedgerAccount::Owner, 'owner_contract_id' => $management->id]));
        $correction = LedgerEntry::query()->where('kind', LedgerKind::Correction)->where('account', LedgerAccount::Tenant)->sole();
        $this->assertSame('2026-12-01', $correction->month->toDateString());
        $this->assertSame('Прекратен на 16.12', $correction->reason);
        // Издаденото известие не се променя.
        $this->assertSame('600.00', $statement->fresh()->total);
    }

    public function test_rent_changed_by_hand_at_issuing_is_not_a_difference(): void
    {
        [$property] = $this->rented();
        $this->sheet->generate('2026-12');
        $this->sheet->change($this->draft($property, ChargeType::Rent), ['amount' => '500', 'reason' => 'Отстъпка']);
        $this->issuer->issueMonth('2026-12');

        $this->assertSame('450.00', $this->sum(['account' => LedgerAccount::Owner]));
        $this->assertSame([], $this->reconciliation->differences('2026-12'));
    }

    public function test_manual_correction_in_an_issued_month(): void
    {
        [$property, $lease, $management] = $this->rented();
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');

        $this->assertRejected(fn () => $this->reconciliation->manual('2026-12', ['type' => 'penalty', 'lease_contract_id' => $lease->id,
            'property_id' => $property->id, 'amount' => '-20', 'reason' => '']), 'reason');
        $this->reconciliation->manual('2026-12', ['type' => 'penalty', 'lease_contract_id' => $lease->id,
            'property_id' => $property->id, 'amount' => '-20', 'reason' => 'Сторно на неустойка']);
        // Отстъпка от наема след издаването: делът на собственика при процент се смята сам.
        $this->reconciliation->manual('2026-12', ['type' => 'rent', 'lease_contract_id' => $lease->id,
            'property_id' => $property->id, 'amount' => '-100', 'reason' => 'Отстъпка']);

        $this->assertSame('480.00', $this->sum(['account' => LedgerAccount::Tenant, 'lease_contract_id' => $lease->id]));
        $this->assertSame('450.00', $this->sum(['account' => LedgerAccount::Owner, 'owner_contract_id' => $management->id]));
        // Ръчни са двата въведени записа; делът на собственика е корекция, сметната сама.
        $this->assertSame(2, LedgerEntry::query()->where('kind', LedgerKind::Manual)->count());
        $this->assertSame('-90.00', LedgerEntry::query()->where('kind', LedgerKind::Correction)->where('account', LedgerAccount::Owner)->sole()->amount);
        $this->assertSame([], $this->reconciliation->differences('2026-12'));
        $this->assertRejected(fn () => $this->reconciliation->manual('2026-11', ['type' => 'penalty', 'lease_contract_id' => $lease->id,
            'property_id' => $property->id, 'amount' => '5', 'reason' => 'x']), 'month');
    }

    public function test_generating_the_next_month_warns_about_differences_in_issued_months(): void
    {
        [, $lease] = $this->rented();
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');
        $lease->fresh()->update(['terminated_on' => '2026-12-16']);

        $codes = array_map(fn ($w) => $w->code, $this->sheet->warnings('2027-01'));

        $this->assertContains(Reconciliation::ISSUED_DIFFERENCE, $codes);
    }

    // Книгата: само добавяне

    public function test_ledger_entries_cannot_be_changed_or_deleted(): void
    {
        $this->rented();
        $this->sheet->generate('2026-12');
        $this->issuer->issueMonth('2026-12');
        $entry = LedgerEntry::query()->first();

        foreach ([
            fn () => $entry->update(['amount' => '1.00']),
            fn () => $entry->delete(),
            fn () => LedgerEntry::query()->whereKey($entry->id)->update(['amount' => '1.00']),
            fn () => LedgerEntry::query()->whereKey($entry->id)->delete(),
            fn () => Statement::query()->first()->update(['total' => '1.00']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Промяна или изтриване трябваше да се откаже.');
            } catch (LogicException) {
            }
        }

        $this->assertNotSame('1.00', $entry->fresh()->amount);
    }
}
