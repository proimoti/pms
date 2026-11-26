<?php

namespace Tests\Feature\Screens;

use App\Enums\ChargeType;
use App\Enums\OwnerContractType;
use App\Models\ChargeDraft;
use App\Models\LeaseContract;
use App\Models\Property;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UtilityAccount;
use App\Services\Charges\MonthWorksheet;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Services\Utilities\UtilityAccountService;
use App\Services\Utilities\UtilityImporter;
use Tests\Support\UtilityFiles;

/**
 * Битовите сметки на екрана „Начисления“ и в прегледа на известията (PROJECT.md,
 * „Битови сметки в месеца“, 22.11.2026; част 4а). Редът показва вид, доставчик,
 * номер, период, дни и сума; в известието е и „Подробно описание“ от ePay;
 * битов ред със сума 0 не е в известието (наем 0 за гараж остава).
 * Само измислени данни.
 */
class UtilityWorksheetScreensTest extends ScreenTestCase
{
    use UtilityFiles;

    private User $user;

    private Property $flat;

    private Provider $power;

    private UtilityAccount $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->staff();
        $this->actingAs($this->user);

        $this->flat = $this->record(Property::class, ['number' => 101]);
        $this->managed($this->flat);
        $this->power = Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL', 'epay_name' => 'Измислено Електро АД']);
        $this->meter = app(UtilityAccountService::class)->create([
            'provider_id' => $this->power->id, 'number' => '0012345', 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => [['property_id' => $this->flat->id]],
        ]);
    }

    /** Договор за управление „Собствен“, за да се начислява наемът. */
    private function managed(Property $property): void
    {
        app(OwnerContractService::class)->create([
            'type' => OwnerContractType::Own, 'signed_on' => '2026-01-01', 'lines' => [['property_id' => $property->id]],
        ]);
    }

    private function lease(string $tenant, string $from, ?string $terminated = null, array $lines = []): LeaseContract
    {
        $lease = app(LeaseContractService::class)->create([
            'tenant_id' => $this->record(Tenant::class, ['name' => $tenant])->id,
            'signed_on' => $from,
            'lines' => $lines ?: [['property_id' => $this->flat->id, 'rent' => '500']],
        ]);
        if ($terminated !== null) {
            $lease->update(['terminated_on' => $terminated]);
        }

        return $lease;
    }

    private function epayBill(float $amount, string $details = ''): void
    {
        app(UtilityImporter::class)->import($this->epayFile([[
            'date' => '05.12.2026 10:15:00', 'out' => number_format($amount, 2, ',', ''), 'provider' => 'Измислено Електро АД',
            'code' => '1234567890123456', 'description' => '127(0012345)', 'details' => $details,
        ]]), 'epay.xlsx');
    }

    public function test_worksheet_shows_the_utility_row_with_provider_number_period_and_days(): void
    {
        $this->lease('Анна Измислена', '2026-01-10', '2026-11-11');
        $this->lease('Борис Измислен', '2026-11-11');
        $this->epayBill(90.00);
        $this->post('/charges/generate', ['month' => '2026-12']);

        $this->get('/charges?month=2026-12')->assertOk()
            ->assertSee(__('charges.types.utility'))
            ->assertSee('Измислено Електро')->assertSee('№ 0012345')->assertSee('01.11–30.11.2026')
            ->assertSee(__('utilities.kinds.electricity'))
            ->assertSee('30,00')->assertSee('60,00')
            ->assertSee(__('charges.screens.only_utilities'), false);
    }

    public function test_worksheet_shows_the_fixed_fee_for_the_previous_month(): void
    {
        app(UtilityAccountService::class)->create([
            'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => '15', 'valid_from' => '2026-01-01',
            'note' => 'Такса вход (измислена)', 'properties' => [['property_id' => $this->flat->id]],
            'fund_id' => app(\App\Services\Utilities\FundService::class)->create([
                'company_id' => $this->company->id, 'name' => 'Вход (измислен)', 'properties' => [$this->flat->id],
            ])->id,
        ]);
        $this->lease('Анна Измислена', '2026-01-10');
        $this->post('/charges/generate', ['month' => '2026-12']);

        $this->get('/charges?month=2026-12')->assertOk()
            ->assertSee(__('charges.types.fixed_fee'))->assertSee(__('utilities.kinds.common'))
            ->assertSee(__('charges.screens.fee_for_month', ['month' => 'Ноември 2026']))->assertSee('15,00');
    }

    public function test_for_decision_section_on_the_worksheet(): void
    {
        $this->epayBill(48.20); // празен имот през ноември
        $this->post('/charges/generate', ['month' => '2026-12']);

        $this->get('/charges?month=2026-12')->assertOk()
            ->assertSee(__('charges.screens.utilities_for_decision'))
            ->assertSee('48,20')->assertSee(__('charges.utility_reasons.vacant'))->assertSee('№ 0012345');
    }

    public function test_notice_preview_has_the_details_and_hides_zero_utility_rows_but_not_zero_rent(): void
    {
        // Наемател с апартамент (500 €) и гараж с наем 0; ток 90 € с „Подробно описание“ от ePay.
        $garage = $this->record(Property::class, ['number' => 102]);
        $this->managed($garage);
        $lease = $this->lease('Анна Измислена', '2026-01-10', null, [
            ['property_id' => $this->flat->id, 'rent' => '500'], ['property_id' => $garage->id, 'rent' => '0'],
        ]);
        $this->epayBill(90.00, "Период: 01.11.2026 - 30.11.2026\nИзмислен абонат");
        $this->post('/charges/generate', ['month' => '2026-12']);

        $preview = $this->get('/charges/issue?month=2026-12')->assertOk();
        $preview->assertSee('Анна Измислена')->assertSee('№ 0012345')->assertSee('90,00')
            ->assertSee('Период: 01.11.2026 - 30.11.2026')->assertSee('№ 102');

        // Битовият ред намален на 0 (с причина): вече не е в известието; гаражът с наем 0 остава.
        $row = ChargeDraft::query()->where('type', ChargeType::Utility)->where('lease_contract_id', $lease->id)->sole();
        app(MonthWorksheet::class)->change($row, ['amount' => '0', 'reason' => 'платено на място']);

        $this->get('/charges/issue?month=2026-12')->assertOk()
            ->assertDontSee('№ 0012345')->assertDontSee('Период: 01.11.2026 - 30.11.2026')->assertSee('№ 102');
    }

    public function test_issued_notice_shows_the_utility_line_with_provider_number_period_and_details(): void
    {
        $lease = $this->lease("Анна Измислена", "2026-01-10");
        $this->epayBill(90.00, "Период: 01.11.2026 - 30.11.2026
Измислен абонат");
        $this->post("/charges/generate", ["month" => "2026-12"]);
        $this->post("/charges/issue", ["month" => "2026-12", "reviewed" => "1"])->assertSessionHasNoErrors();

        $statement = \App\Models\Statement::query()->where("lease_contract_id", $lease->id)->sole();
        $this->get("/statements/{$statement->id}")->assertOk()
            ->assertSee("Измислено Електро")->assertSee("№ 0012345")->assertSee("01.11–30.11.2026")
            ->assertSee("Период: 01.11.2026 - 30.11.2026")->assertSee("590,00");
    }

    public function test_issue_now_preview_shows_the_utility_line(): void
    {
        $lease = $this->lease("Анна Измислена", "2026-01-10");
        $this->epayBill(90.00);
        $this->post("/charges/generate", ["month" => "2026-12"]);

        $this->get("/lease-contracts/{$lease->id}/issue?month=2026-12")->assertOk()
            ->assertSee("№ 0012345")->assertSee("01.11–30.11.2026")->assertSee("90,00");
    }
}
