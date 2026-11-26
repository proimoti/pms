<?php

namespace Tests\Feature\Charges;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\ExportBatch;
use App\Models\ExportBatchEntry;
use App\Models\Fund;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Charges\Ledger;
use App\Services\Export\ExportSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Screens\ScreenTestCase;
use Tests\Support\DossierScenario;

/**
 * Пакети за експорта и защита от двоен експорт (PROJECT.md, „Експорт към
 * Микроинвест“, 26.11.2026): в пакет влизат записите от книгата за издаден
 * месец, които още не са експортирани; един запис е само в един пакет (пази го
 * и самата база); книгата не се пипа; корекциите след пакета влизат в
 * следващия. Без попълнени настройки и аналитики на фондовете пакет не се прави.
 * Прави го само управляващият начисленията. Декември 2026 е издаден.
 */
class ExportBatchTest extends ScreenTestCase
{
    use DossierScenario;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00'));
        $this->user = $this->staff(['is_company_admin' => true]);
        $this->buildDossierScenario($this->user);
        $this->fillSettings();
    }

    private function fillSettings(array $override = []): void
    {
        app(ExportSettings::class)->save($override + [
            'tenant' => '418', 'deposit' => '491', 'owner' => '408', 'utility' => '407', 'fund' => '406',
            'revenue_representative_percent' => '703/1', 'revenue_representative_fixed' => '703/2', 'revenue_sublease_individual' => '703/3',
            'revenue_sublease_legal' => '703/4', 'revenue_own' => '417/1', 'revenue_penalty' => '703/9', 'revenue_other' => '709',
            'manager_cost' => '609/2', 'include_paid_bills' => '0', 'kind_electricity' => '1',
        ]);
    }

    private function decemberEntries()
    {
        return LedgerEntry::query()->whereDate('month', '2026-12-01')->orderBy('id')->pluck('id')->all();
    }

    public function test_export_screen_lists_issued_months_with_pending_entries(): void
    {
        $count = count($this->decemberEntries());
        $this->assertGreaterThan(0, $count);

        $this->get('/export')->assertOk()
            ->assertSee(__('export.batches.title'))
            ->assertSeeInOrder(['Декември 2026', __('export.batches.pending', ['count' => $count])])
            ->assertSee('name="month" value="2026-12"', false);
        $this->get('/charges?month=2026-12')->assertSee('href="/export"', false);
    }

    public function test_a_batch_takes_all_unexported_entries_of_the_month_and_the_ledger_is_untouched(): void
    {
        $before = DB::table('ledger_entries')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->post('/export/batches', ['month' => '2026-12'])->assertRedirect('/export')->assertSessionHas('status');

        $batch = ExportBatch::query()->sole();
        $this->assertSame(1, $batch->number);
        $this->assertSame('2026-12-01', $batch->month->format('Y-m-d'));
        $this->assertSame($this->user->id, $batch->created_by);
        $this->assertSame($this->decemberEntries(), $batch->entries()->orderBy('ledger_entry_id')->pluck('ledger_entry_id')->all());
        $this->assertSame(count($this->decemberEntries()), $batch->entries_count);
        $this->assertSame($before, DB::table('ledger_entries')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());

        $this->get('/export')->assertSee(__('export.batches.pending', ['count' => 0]))->assertSee('№ 1');
    }

    public function test_nothing_new_means_no_second_batch(): void
    {
        $this->post('/export/batches', ['month' => '2026-12']);
        $this->post('/export/batches', ['month' => '2026-12'])->assertSessionHasErrors('month');

        $this->assertSame(1, ExportBatch::query()->count());
    }

    public function test_a_correction_after_the_batch_goes_into_the_next_one(): void
    {
        $this->post('/export/batches', ['month' => '2026-12']);
        $correction = Ledger::book(CarbonImmutable::parse('2026-12-01'), LedgerKind::Manual, ['account' => LedgerAccount::Tenant, 'type' => ChargeType::Rent,
            'lease_contract_id' => $this->lease->id, 'tenant_id' => $this->boris->id, 'property_id' => $this->flat->id, 'reason' => 'Отстъпка (измислено)'], -5000);

        $this->get('/export')->assertSee(__('export.batches.pending', ['count' => 1]));
        $this->post('/export/batches', ['month' => '2026-12'])->assertSessionHasNoErrors();

        $second = ExportBatch::query()->where('number', 2)->sole();
        $this->assertSame([$correction->id], $second->entries()->pluck('ledger_entry_id')->all());
    }

    public function test_the_database_does_not_allow_an_entry_in_two_batches(): void
    {
        $this->post('/export/batches', ['month' => '2026-12']);
        $entry = ExportBatchEntry::query()->first();

        $this->expectException(QueryException::class);
        DB::table('export_batch_entries')->insert(['company_id' => $entry->company_id, 'export_batch_id' => $entry->export_batch_id, 'ledger_entry_id' => $entry->ledger_entry_id]);
    }

    public function test_batches_are_append_only(): void
    {
        $this->post('/export/batches', ['month' => '2026-12']);
        $batch = ExportBatch::query()->sole();

        try {
            $batch->delete();
            $this->fail('Пакетът не трябва да се трие.');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $batch->entries()->first()->delete();
    }

    public function test_month_that_is_not_issued_cannot_be_exported(): void
    {
        // Януари има записи от „Издай сега“, но месецът не е издаден.
        app(\App\Services\Charges\Issuer::class)->issueNow($this->lease, '2027-01');
        $this->assertGreaterThan(0, LedgerEntry::query()->whereDate('month', '2027-01-01')->count());

        $this->post('/export/batches', ['month' => '2027-01'])->assertSessionHasErrors(['month' => __('export.batches.not_issued')]);
        $this->assertSame(0, ExportBatch::query()->count());
    }

    public function test_missing_settings_or_fund_analytics_stop_the_batch(): void
    {
        $this->fillSettings(['revenue_own' => '']);
        $this->post('/export/batches', ['month' => '2026-12'])->assertSessionHasErrors('month');
        $this->get('/export')->assertSee(__('export.settings.keys.revenue_own'))->assertSee('href="/company/export"', false);

        $this->fillSettings();
        DB::table('funds')->update(['analytic' => null]);
        $this->post('/export/batches', ['month' => '2026-12'])->assertSessionHasErrors('month');
        $this->get('/export')->assertSee(__('export.batches.fund_without_analytic', ['name' => 'Вход (измислен)']));

        $this->assertSame(0, ExportBatch::query()->count());
    }

    public function test_only_the_charges_manager(): void
    {
        auth()->logout();
        $this->actingAs($this->readOnlyStaff());

        $this->get('/export')->assertForbidden();
        $this->post('/export/batches', ['month' => '2026-12'])->assertForbidden();
        $this->get('/properties')->assertDontSee('href="/export"', false);
        $this->assertSame(0, ExportBatch::query()->withoutGlobalScopes()->count());
    }

    public function test_company_b_does_not_see_or_take_the_entries_of_company_a(): void
    {
        $this->post('/export/batches', ['month' => '2026-12']);
        auth()->logout();
        $foreign = $this->staff([], $this->otherCompany);
        $this->assertSame($this->otherCompany->id, $foreign->company_id);
        $this->flushSession(); // без съобщението от предишната заявка на служителя на фирма A
        $this->actingAs($foreign);

        $this->get('/export')->assertOk()->assertDontSee('№ 1')->assertDontSee('Декември 2026');
        $this->assertSame(0, ExportBatch::query()->count());
        $this->post('/export/batches', ['month' => '2026-12'])->assertSessionHasErrors('month');
        $this->assertSame(1, ExportBatch::query()->withoutGlobalScopes()->count());
    }
}
