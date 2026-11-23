<?php

namespace Tests\Support;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Beneficiary;
use App\Models\LeaseContract;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Property;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UtilityAccount;
use App\Services\Charges\Issuer;
use App\Services\Charges\MonthWorksheet;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Services\Utilities\FundService;
use App\Services\Utilities\UtilityAccountService;
use App\Services\Utilities\UtilityImporter;

/**
 * Измислен пример за досиетата и справките (PROJECT.md, „Досиета и първи
 * справки“, 23.11.2026). Създава се като влязъл служител на фирмата.
 *
 * - имот № 101: собственик Петя (договор „Представител, %“ 10 % от 01.01.2026,
 *   бенефициент Иван по банка, с анекс № 1); наематели Анна (10.01–01.06.2026)
 *   и Борис (от 01.06.2026, наем 500 €); абонат за ток № 0012345 и такса
 *   вход към фонд „Вход (измислен)“;
 * - имот № 102: свободен, никога не е отдаван;
 * - декември 2026 е издаден: Борис дължи 500,00 (418), към собственика 450,00 (408);
 * - сметка за ток 90 € от 20.12.2026 чака януари (407 на имота 90,00).
 */
trait DossierScenario
{
    use UtilityFiles;

    protected Property $flat;

    protected Property $empty;

    protected Owner $owner;

    protected Beneficiary $beneficiary;

    protected OwnerContract $management;

    protected Tenant $anna;

    protected Tenant $boris;

    protected LeaseContract $oldLease;

    protected LeaseContract $lease;

    protected UtilityAccount $meter;

    protected function buildDossierScenario(User $staff): void
    {
        // Записите на регистъра — без влязъл потребител (IBAN се въвежда само с право), после служителят.
        auth()->logout();
        $company = ['company_id' => $staff->company_id];
        $this->flat = Property::factory()->create($company + ['number' => 101]);
        $this->empty = Property::factory()->create($company + ['number' => 102]);
        $this->owner = Owner::factory()->create($company + ['name' => 'Петя Измислена', 'phone' => '0888 100 101', 'email' => 'petya@example.test']);
        $this->beneficiary = Beneficiary::factory()->create($company + ['name' => 'Иван Получател', 'iban' => 'BG18RZBB91550123456789', 'owner_id' => $this->owner->id]);
        $this->anna = Tenant::factory()->create($company + ['name' => 'Анна Измислена', 'phone' => '0888 200 201', 'email' => 'anna@example.test']);
        $this->boris = Tenant::factory()->create($company + ['name' => 'Борис Измислен', 'phone' => '0888 300 301', 'email' => 'boris@example.test']);
        $this->actingAs($staff);

        $owners = app(OwnerContractService::class);
        $this->management = $owners->create([
            'type' => OwnerContractType::RepresentativePercent, 'owner_id' => $this->owner->id, 'signed_on' => '2026-01-01',
            'commission_percent' => '10.00', 'beneficiary_id' => $this->beneficiary->id, 'payment_method' => PaymentMethod::Bank,
            'lines' => [['property_id' => $this->flat->id]],
        ]);
        $owners->addAnnex($this->management, 1, '2026-03-01', 'Измислен анекс за досието');
        $owners->create(['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01', 'lines' => [['property_id' => $this->empty->id]]]);

        $leases = app(LeaseContractService::class);
        $this->oldLease = $leases->create(['tenant_id' => $this->anna->id, 'signed_on' => '2026-01-10', 'lines' => [['property_id' => $this->flat->id, 'rent' => '450']]]);
        $this->oldLease->update(['terminated_on' => '2026-06-01']);
        $this->lease = $leases->create(['tenant_id' => $this->boris->id, 'signed_on' => '2026-06-01', 'lines' => [['property_id' => $this->flat->id, 'rent' => '500']]]);

        $power = Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL']);
        $accounts = app(UtilityAccountService::class);
        $this->meter = $accounts->create([
            'provider_id' => $power->id, 'number' => '0012345', 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-12-01', 'properties' => [['property_id' => $this->flat->id]],
        ]);
        $fund = app(FundService::class)->create(['name' => 'Вход (измислен)', 'analytic' => 'ВХ-1', 'properties' => [$this->flat->id]]);
        $accounts->create([
            'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => '15', 'valid_from' => '2026-12-01', 'note' => 'Такса вход',
            'fund_id' => $fund->id, 'properties' => [['property_id' => $this->flat->id]],
        ]);

        app(MonthWorksheet::class)->generate('2026-12');
        app(Issuer::class)->issueMonth('2026-12');

        app(UtilityImporter::class)->import($this->easypayFile([
            ['ref' => 990001, 'date' => '2026-12-20 10:00:00', 'number' => 12345, 'provider' => 'IZM EL', 'amount' => 90.00],
        ]), 'f.xlsx');
    }
}
