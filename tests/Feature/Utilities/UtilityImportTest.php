<?php

namespace Tests\Feature\Utilities;

use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\LedgerEntry;
use App\Models\PaidUtilityBill;
use App\Models\PaidUtilityBillPart;
use App\Models\Property;
use App\Models\Provider;
use App\Models\User;
use App\Models\UtilityAccount;
use App\Models\UtilityImportIssue;
use App\Services\Charges\Reconciliation;
use App\Services\Utilities\UtilityAccountService;
use App\Services\Utilities\UtilityImporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Feature\Contracts\ContractTestCase;
use Tests\Support\Funds;
use Tests\Support\UtilityFiles;

/**
 * Внос на платените битови сметки от EasyPay и ePay (PROJECT.md, т. 5.3
 * „Внос на платените сметки“, „Внос, уточнения“, „Внос, допълнения“,
 * 21–22.11.2026). Само измислени номера, имена и суми.
 */
class UtilityImportTest extends ContractTestCase
{
    use Funds, UtilityFiles;

    private UtilityImporter $importer;

    private Provider $power;

    private Provider $water;

    private Property $a;

    private Property $b;

    private Property $c;

    private UtilityAccount $single;

    private UtilityAccount $shared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = app(UtilityImporter::class);
        $this->power = Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL', 'epay_name' => 'Измислено Електро АД']);
        $this->water = Provider::query()->create(['name' => 'Измислена Вода', 'easypay_name' => 'IZM VODA', 'epay_name' => 'Измислена Вода ЕАД']);
        [$this->a, $this->b, $this->c] = [$this->property(), $this->property(), $this->property()];

        $accounts = app(UtilityAccountService::class);
        $this->single = $accounts->create([
            'provider_id' => $this->power->id, 'number' => '0012345', 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => [['property_id' => $this->a->id]],
        ]);
        $this->shared = $accounts->create([
            'provider_id' => $this->water->id, 'number' => '1000071718', 'kind' => 'water', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => [
                ['property_id' => $this->a->id, 'share' => '33.33'],
                ['property_id' => $this->b->id, 'share' => '33.33'],
                ['property_id' => $this->c->id, 'share' => '33.34'],
            ],
        ]);
    }

    private function easypayRow(array $overrides = []): array
    {
        return array_merge(['ref' => 900001, 'date' => '2026-12-05 10:15:00', 'number' => 12345, 'provider' => 'IZM EL', 'amount' => 48.2], $overrides);
    }

    private function epayRow(array $overrides = []): array
    {
        return array_merge([
            'date' => '05.12.2026 11:20:00', 'out' => '100.00', 'provider' => 'Измислена Вода ЕАД', 'code' => '1234567890123401',
            'description' => '127(1000071718)', 'details' => "Измислена Вода ЕАД\nПериод 01.11.2026 - 30.11.2026\nКлиент 1000071718",
        ], $overrides);
    }

    /** Частите на сметката по имоти: [property_id => сума]. */
    private function partsOf(PaidUtilityBill $bill): array
    {
        return $bill->parts()->get()->mapWithKeys(fn ($part) => [$part->property_id => $part->amount])->all();
    }

    /** Записите по 407: [property_id => сума]. */
    private function utilityLedger(): array
    {
        return LedgerEntry::query()->where('account', LedgerAccount::Utility)->orderBy('id')->get()
            ->map(fn ($entry) => [$entry->property_id, $entry->amount])->all();
    }

    // Четене на файла

    public function test_recognises_the_file_by_its_columns(): void
    {
        $this->assertSame('easypay', $this->importer->preview($this->easypayFile([$this->easypayRow()]))->source);
        $this->assertSame('epay', $this->importer->preview($this->epayFile([$this->epayRow()]))->source);
    }

    public function test_unknown_or_broken_file_is_rejected(): void
    {
        foreach ([$this->writeXlsx([['Колона 1', 'Колона 2'], ['a', 'b']]), tempnam(sys_get_temp_dir(), 'pms-test-')] as $path) {
            try {
                $this->importer->preview($path);
                $this->fail('Непознатият файл е приет.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('file', $e->errors());
            }
        }
    }

    // EasyPay

    public function test_easypay_number_without_leading_zeros_finds_the_account(): void
    {
        $batch = $this->importer->import($this->easypayFile([$this->easypayRow()]), 'easypay-dekemvri.xlsx');

        $bill = PaidUtilityBill::query()->sole();
        $this->assertSame($this->single->id, $bill->utility_account_id);
        $this->assertSame('easypay', $bill->source);
        $this->assertSame('900001', $bill->reference);
        $this->assertSame('48.20', $bill->amount);
        $this->assertSame('2026-12-05 10:15:00', $bill->paid_at->format('Y-m-d H:i:s'));
        $this->assertSame('12345', $bill->file_number, 'номерът, както е във файла');
        $this->assertSame($batch->id, $bill->import_batch_id);
        $this->assertSame('easypay-dekemvri.xlsx', $batch->file_name);
        $this->assertSame([$this->a->id => '48.20'], $this->partsOf($bill));
    }

    public function test_paid_bill_goes_to_407_for_the_property_in_the_month_of_payment(): void
    {
        $this->importer->import($this->easypayFile([$this->easypayRow()]), 'f.xlsx');

        $entry = LedgerEntry::query()->sole();
        $this->assertSame(LedgerAccount::Utility, $entry->account);
        $this->assertSame(LedgerKind::UtilityPaid, $entry->kind);
        $this->assertSame('utility', $entry->type->value);
        $this->assertSame($this->a->id, $entry->property_id);
        $this->assertSame('48.20', $entry->amount);
        $this->assertSame('2026-12-01', $entry->month->toDateString());
        $this->assertSame(PaidUtilityBill::query()->sole()->id, $entry->paid_utility_bill_id);
        $this->assertNull($entry->lease_contract_id);
    }

    // Общата сметка

    public function test_shared_bill_33_33_33_remainder_goes_to_the_last_property(): void
    {
        // 100,00 € при 33,33/33,33/33,34 → 33,33 + 33,33 + 33,34 (PROJECT.md, 22.11.2026).
        $this->importer->import($this->epayFile([$this->epayRow()]), 'f.xlsx');

        $bill = PaidUtilityBill::query()->sole();
        $this->assertSame([$this->a->id => '33.33', $this->b->id => '33.33', $this->c->id => '33.34'], $this->partsOf($bill));
        $this->assertSame([[$this->a->id, '33.33'], [$this->b->id, '33.33'], [$this->c->id, '33.34']], $this->utilityLedger());
        $this->assertTrue($bill->isShared(), 'после при експорта общият абонат е една аналитика 407');
    }

    public function test_shared_bill_rounding_also_goes_to_the_last_property(): void
    {
        // 10,00 × 33,33 % = 3,333 → 3,33; последният: 10,00 − 6,66 = 3,34 (стотинката от закръглянето).
        $this->importer->import($this->epayFile([$this->epayRow(['out' => '10,00'])]), 'f.xlsx');

        $this->assertSame(
            [$this->a->id => '3.33', $this->b->id => '3.33', $this->c->id => '3.34'],
            $this->partsOf(PaidUtilityBill::query()->sole()),
        );
    }

    public function test_shared_bill_40_60(): void
    {
        $account = app(UtilityAccountService::class)->create([
            'provider_id' => $this->power->id, 'number' => '777', 'kind' => 'common', 'billing' => 'actual', 'valid_from' => '2026-01-01',
            'properties' => [['property_id' => $this->b->id, 'share' => '40'], ['property_id' => $this->c->id, 'share' => '60']],
        ]);

        // 55,55 × 40 % = 22,22; последният 33,33.
        $this->importer->import($this->easypayFile([$this->easypayRow(['number' => 777, 'amount' => 55.55])]), 'f.xlsx');

        $bill = PaidUtilityBill::query()->sole();
        $this->assertSame($account->id, $bill->utility_account_id);
        $this->assertSame([$this->b->id => '22.22', $this->c->id => '33.33'], $this->partsOf($bill));
    }

    // ePay

    public function test_epay_number_in_brackets_provider_in_correspondent_details_kept_as_they_are(): void
    {
        $row = $this->epayRow();
        $this->importer->import($this->epayFile([$row]), 'epay.xlsx');

        $bill = PaidUtilityBill::query()->sole();
        $this->assertSame($this->shared->id, $bill->utility_account_id);
        $this->assertSame('epay', $bill->source);
        $this->assertSame('1234567890123401', $bill->reference);
        $this->assertSame('100.00', $bill->amount);
        $this->assertSame('2026-12-05 11:20:00', $bill->paid_at->format('Y-m-d H:i:s'));
        $this->assertSame($row['details'], $bill->details, '„Подробно описание“ се пази непроменено');
        $this->assertSame('127(1000071718)', $bill->description);
    }

    public function test_epay_income_rows_are_skipped_silently(): void
    {
        $preview = $this->importer->preview($this->epayFile([
            $this->epayRow(['out' => null, 'in' => '600.00', 'provider' => 'Измислен Наемател', 'code' => '1234567890123402', 'description' => 'ДОГ. НАЕМ 123']),
            $this->epayRow(),
        ]));

        $this->assertCount(1, $preview->bills);
        $this->assertSame([], $preview->issues, 'приходите не са „за решение“');
        $this->assertSame(0, $preview->skippedFees);

        $this->importer->import($this->epayFile([
            $this->epayRow(['out' => null, 'in' => '600.00', 'code' => '1234567890123402', 'description' => 'ДОГ. НАЕМ 123']),
        ]), 'f.xlsx');
        $this->assertSame(0, UtilityImportIssue::query()->count());
        $this->assertSame(0, PaidUtilityBill::query()->count());
    }

    public function test_epay_bank_fees_are_skipped_and_only_counted(): void
    {
        $file = $this->epayFile([
            $this->epayRow(['out' => '0.60', 'provider' => 'Измислена Банка', 'code' => '1234567890123403', 'description' => 'Такса банков превод']),
            $this->epayRow(['out' => '0.60', 'provider' => 'Измислена Банка', 'code' => '1234567890123404', 'description' => 'Такса банков превод']),
            $this->epayRow(),
        ]);

        $preview = $this->importer->preview($file);
        $this->assertSame(2, $preview->skippedFees);
        $this->assertSame([], $preview->issues);

        $batch = $this->importer->import($file, 'f.xlsx');
        $this->assertSame(2, $batch->skipped_fees);
        $this->assertSame(1, $batch->bills_count);
        $this->assertSame(0, UtilityImportIssue::query()->count());
    }

    // Защита от двоен внос

    public function test_the_same_file_twice_adds_nothing(): void
    {
        $file = $this->epayFile([$this->epayRow(), $this->epayRow(['code' => '1234567890123405', 'description' => '127(99999)'])]);
        $this->importer->import($file, 'f.xlsx');
        $ledger = $this->utilityLedger();

        $preview = $this->importer->preview($file);
        $this->assertSame([], $preview->bills);
        $this->assertSame([], $preview->issues, 'и „за решение“ не се повтаря');
        $this->assertCount(2, $preview->duplicates);

        $batch = $this->importer->import($file, 'f.xlsx');
        $this->assertSame(0, $batch->bills_count);
        $this->assertSame(2, $batch->duplicates);
        $this->assertSame(1, PaidUtilityBill::query()->count());
        $this->assertSame(1, UtilityImportIssue::query()->count());
        $this->assertSame($ledger, $this->utilityLedger());
    }

    public function test_the_same_reference_twice_in_one_file_is_taken_once(): void
    {
        $batch = $this->importer->import($this->easypayFile([$this->easypayRow(), $this->easypayRow()]), 'f.xlsx');

        $this->assertSame(1, $batch->bills_count);
        $this->assertSame(1, $batch->duplicates);
        $this->assertSame(1, PaidUtilityBill::query()->count());
    }

    public function test_database_refuses_the_same_reference_twice(): void
    {
        $this->importer->import($this->easypayFile([$this->easypayRow()]), 'f.xlsx');
        $bill = PaidUtilityBill::query()->sole();

        $this->expectException(QueryException::class);
        DB::table('paid_utility_bills')->insert(array_merge(
            collect($bill->getAttributes())->except(['id'])->all(),
            ['created_at' => now(), 'updated_at' => now()],
        ));
    }

    public function test_same_reference_in_another_company_is_not_a_duplicate(): void
    {
        $this->importer->import($this->easypayFile([$this->easypayRow()]), 'f.xlsx');

        $this->actingAs($this->otherStaff);
        Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL']);
        $preview = $this->importer->preview($this->easypayFile([$this->easypayRow()]));

        $this->assertSame([], $preview->duplicates);
        $this->assertSame('unknown_number', $preview->issues[0]->reason);
    }

    // „За решение“

    public function test_unrecognised_rows_are_for_decision_with_a_reason(): void
    {
        $fixed = app(UtilityAccountService::class)->create([
            'provider_id' => $this->power->id, 'number' => '555', 'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => '10',
            'valid_from' => '2026-01-01', 'fund_id' => $this->fund([$this->a])->id, 'properties' => [['property_id' => $this->a->id]],
        ]);
        $ended = app(UtilityAccountService::class)->create([
            'provider_id' => $this->power->id, 'number' => '666', 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'valid_to' => '2026-11-30', 'properties' => [['property_id' => $this->b->id]],
        ]);
        $archived = app(UtilityAccountService::class)->create([
            'provider_id' => $this->power->id, 'number' => '888', 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => [['property_id' => $this->c->id]],
        ]);
        $archived->archive();

        $batch = $this->importer->import($this->easypayFile([
            $this->easypayRow(['ref' => 1, 'number' => 4444]),
            $this->easypayRow(['ref' => 2, 'provider' => 'NEPOZNAT']),
            $this->easypayRow(['ref' => 3, 'number' => 555]),
            $this->easypayRow(['ref' => 4, 'number' => 666]),
            $this->easypayRow(['ref' => 5, 'number' => 888]),
            $this->easypayRow(['ref' => 6, 'amount' => 0]),
            $this->easypayRow(['ref' => 7, 'amount' => -12.5]),
        ]), 'f.xlsx');

        $this->assertSame(0, PaidUtilityBill::query()->count());
        $this->assertSame(7, $batch->for_decision);
        $this->assertSame(
            ['1' => 'unknown_number', '2' => 'unknown_provider', '3' => 'fixed_account', '4' => 'inactive_account', '5' => 'inactive_account', '6' => 'bad_amount', '7' => 'bad_amount'],
            UtilityImportIssue::query()->orderBy('reference')->pluck('reason', 'reference')->all(),
        );

        $issue = UtilityImportIssue::query()->where('reference', '1')->sole();
        $this->assertSame('open', $issue->status);
        $this->assertSame('IZM EL', $issue->provider_name);
        $this->assertSame('4444', $issue->number);
        $this->assertSame('48.20', $issue->amount);
        $this->assertSame($this->power->id, $issue->provider_id, 'доставчикът е познат');
        $this->assertSame(0, LedgerEntry::query()->count(), 'нищо в книгата');
        unset($fixed, $ended);
    }

    public function test_ignored_row_needs_a_reason_and_does_not_come_back(): void
    {
        $file = $this->easypayFile([$this->easypayRow(['number' => 4444])]);
        $this->importer->import($file, 'f.xlsx');
        $issue = UtilityImportIssue::query()->sole();

        try {
            $this->importer->ignore($issue, '  ');
            $this->fail('Игнориране без причина е прието.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $this->importer->ignore($issue, 'платено по грешка, върнато от доставчика');
        $issue->refresh();
        $this->assertSame('ignored', $issue->status);
        $this->assertSame('платено по грешка, върнато от доставчика', $issue->ignore_reason);
        $this->assertSame($this->staff->id, $issue->decided_by);

        $preview = $this->importer->preview($file);
        $this->assertSame([], $preview->issues);
        $this->assertCount(1, $preview->duplicates);
    }

    public function test_unignore_deletes_the_rule_with_an_audit_entry_and_the_row_comes_back_as_new(): void
    {
        // PROJECT.md, „Игнорирани редове“ (22.11.2026): правилото се изтрива, с запис
        // в журнала, и при следващия внос редът се чете отново като нов.
        $file = $this->easypayFile([$this->easypayRow(['number' => 4444])]);
        $this->importer->import($file, 'f.xlsx');
        $issue = UtilityImportIssue::query()->sole();
        $this->importer->ignore($issue, 'грешно платено');

        $this->importer->unignore($issue->fresh());

        $this->assertSame(0, UtilityImportIssue::query()->count());
        $audit = AuditLog::query()->where('auditable_type', 'utility_import_issues')->where('auditable_id', $issue->id)->where('event', 'deleted')->sole();
        $this->assertSame($this->staff->id, $audit->user_id);
        $this->assertSame('грешно платено', $audit->changes['ignore_reason']['old']);

        $preview = $this->importer->preview($file);
        $this->assertSame([], $preview->duplicates, 'вече не е „вече внесен“');
        $this->assertSame(['unknown_number'], array_map(fn ($i) => $i->reason, $preview->issues));

        $this->importer->import($file, 'f.xlsx');
        $this->assertSame('open', UtilityImportIssue::query()->sole()->status, 'отново „за решение“, като нов ред');
    }

    public function test_only_ignored_rows_can_be_unignored_and_issues_are_not_deleted_otherwise(): void
    {
        $this->importer->import($this->easypayFile([$this->easypayRow(['number' => 4444])]), 'f.xlsx');
        $issue = UtilityImportIssue::query()->sole();

        try {
            $this->importer->unignore($issue);
            $this->fail('Отворен ред е изтрит.');
        } catch (ValidationException) {
        }
        try {
            $issue->delete();
            $this->fail('Ред „за решение“ е изтрит направо.');
        } catch (LogicException) {
        }
        $this->assertSame(1, UtilityImportIssue::query()->count());
    }

    public function test_missing_account_added_later_imports_the_open_rows(): void
    {
        $this->importer->import($this->easypayFile([$this->easypayRow(['number' => 4444, 'amount' => 30])]), 'f.xlsx');

        $account = app(UtilityAccountService::class)->create([
            'provider_id' => $this->power->id, 'number' => '004444', 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => [['property_id' => $this->b->id]],
        ]);
        $this->assertSame(1, $this->importer->retryOpen());

        $issue = UtilityImportIssue::query()->sole();
        $this->assertSame('imported', $issue->status);
        $bill = PaidUtilityBill::query()->sole();
        $this->assertSame($account->id, $bill->utility_account_id);
        $this->assertSame($issue->paid_utility_bill_id, $bill->id);
        $this->assertSame([[$this->b->id, '30.00']], $this->utilityLedger());

        $this->assertSame(0, $this->importer->retryOpen(), 'вече внесеното не се внася втори път');
        $this->assertSame(1, PaidUtilityBill::query()->count());
    }

    public function test_unknown_provider_name_is_linked_to_a_provider(): void
    {
        $this->importer->import($this->easypayFile([$this->easypayRow(['provider' => 'IZM EL 2'])]), 'f.xlsx');
        $this->power->update(['easypay_name' => null]);
        $issue = UtilityImportIssue::query()->sole();

        $this->importer->linkProvider($issue, $this->power);

        $this->assertSame('IZM EL 2', $this->power->fresh()->easypay_name);
        $this->assertSame('imported', $issue->fresh()->status);
        $this->assertSame($this->single->id, PaidUtilityBill::query()->sole()->utility_account_id);
    }

    public function test_provider_with_another_file_name_is_not_overwritten(): void
    {
        $this->importer->import($this->easypayFile([$this->easypayRow(['provider' => 'IZM EL 2'])]), 'f.xlsx');

        try {
            $this->importer->linkProvider(UtilityImportIssue::query()->sole(), $this->power);
            $this->fail('Името на доставчика е сменено тихо.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('provider_id', $e->errors());
        }
        $this->assertSame('IZM EL', $this->power->fresh()->easypay_name);
    }

    // Абонатът се заключва

    public function test_number_and_provider_lock_after_the_first_bill(): void
    {
        $service = app(UtilityAccountService::class);
        $data = [
            'provider_id' => $this->power->id, 'number' => '0012345', 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => [['property_id' => $this->a->id]],
        ];
        $this->importer->import($this->easypayFile([$this->easypayRow()]), 'f.xlsx');

        foreach (['number' => '0012346', 'provider_id' => $this->water->id] as $field => $value) {
            try {
                $service->update($this->single, array_merge($data, [$field => $value]));
                $this->fail("$field е сменено след внесена сметка.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $service->update($this->single, array_merge($data, ['note' => 'бележка', 'valid_to' => '2027-12-31']));
        $this->assertSame('бележка', $this->single->fresh()->note);
    }

    // Преглед, права, книга

    public function test_preview_writes_nothing(): void
    {
        $preview = $this->importer->preview($this->epayFile([$this->epayRow(), $this->epayRow(['code' => '1234567890123406', 'description' => '127(4444)'])]));

        $this->assertCount(1, $preview->bills);
        $this->assertSame(['33.33', '33.33', '33.34'], array_values($preview->bills[0]->parts));
        $this->assertCount(1, $preview->issues);
        $this->assertSame(0, PaidUtilityBill::query()->count() + UtilityImportIssue::query()->count() + ImportBatch::query()->count() + LedgerEntry::query()->count());
    }

    public function test_only_staff_who_manage_charges_can_import(): void
    {
        $file = $this->easypayFile([$this->easypayRow()]);

        foreach ([['access_level' => 'read'], ['property_scope' => 'assigned']] as $attributes) {
            $this->actingAs(User::factory()->create(['company_id' => $this->staff->company_id] + $attributes));
            try {
                $this->importer->import($file, 'f.xlsx');
                $this->fail('Вносът е разрешен без право.');
            } catch (AuthorizationException) {
            }
        }

        $this->actingAs($this->staff);
        $this->assertSame(0, PaidUtilityBill::query()->count());
    }

    public function test_paid_bills_are_append_only(): void
    {
        $this->importer->import($this->easypayFile([$this->easypayRow()]), 'f.xlsx');
        $bill = PaidUtilityBill::query()->sole();

        foreach ([fn () => $bill->update(['amount' => '1.00']), fn () => $bill->delete(), fn () => PaidUtilityBillPart::query()->delete()] as $change) {
            try {
                $change();
                $this->fail('Платена сметка е променена.');
            } catch (LogicException) {
            }
        }
        $this->assertSame('48.20', $bill->fresh()->amount);
    }

    public function test_company_a_does_not_see_imports_of_company_b(): void
    {
        $this->importer->import($this->easypayFile([$this->easypayRow(), $this->easypayRow(['ref' => 2, 'number' => 4444])]), 'f.xlsx');

        $this->actingAs($this->otherStaff);

        $this->assertSame(0, PaidUtilityBill::query()->count());
        $this->assertSame(0, PaidUtilityBillPart::query()->count());
        $this->assertSame(0, UtilityImportIssue::query()->count());
        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_paid_bill_does_not_make_the_month_look_issued(): void
    {
        // 407 при вноса не е издаване: сверката на наемите не бива да вижда декември като издаден.
        $this->percentContract([$this->a]);
        $this->leaseContract([], ['signed_on' => '2026-11-01', 'lines' => [['property_id' => $this->a->id, 'rent' => '500']]]);

        $this->importer->import($this->easypayFile([$this->easypayRow()]), 'f.xlsx');

        $this->assertSame([], app(Reconciliation::class)->differences('2026-12'));
    }
}
