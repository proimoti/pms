<?php

namespace Tests\Feature\Screens;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\LeaseContract;
use App\Models\LeaseContractAnnex;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\OwnerContractAnnex;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use Illuminate\Support\MessageBag;

/**
 * При запис формите на договорите показват всички грешки наведнъж (PROJECT.md,
 * „Дати в интерфейса“, 20.11.2026): и грешните полета, и по-сложните проверки
 * (застъпване с друг договор и др.). Изключение: за ред със собствена грешна
 * дата застъпването не може да се провери. При грешка не се записва нищо.
 */
class ContractErrorsTogetherTest extends ScreenTestCase
{
    private function lease(array $properties, array $overrides = []): LeaseContract
    {
        return app(LeaseContractService::class)->create(array_merge([
            'company_id' => $this->company->id,
            'tenant_id' => $this->record(Tenant::class)->id,
            'signed_on' => '2026-01-10',
            'lines' => array_map(fn (Property $p) => ['property_id' => $p->id, 'rent' => '600'], $properties),
        ], $overrides));
    }

    private function management(array $properties, array $overrides = []): OwnerContract
    {
        return app(OwnerContractService::class)->create(array_merge([
            'company_id' => $this->company->id, 'type' => OwnerContractType::RepresentativePercent,
            'owner_id' => $this->record(Owner::class)->id, 'signed_on' => '2026-01-01', 'commission_percent' => '10',
            'payment_method' => PaymentMethod::Cash,
            'lines' => array_map(fn (Property $p) => ['property_id' => $p->id], $properties),
        ], $overrides));
    }

    private function errors(): MessageBag
    {
        return session('errors')->getBag('default');
    }

    private function leaseBusy(Property $property, int $contract): string
    {
        return __('contracts.messages.lease_property_busy', ['property' => $property->number, 'contract' => $contract]);
    }

    private function managementBusy(Property $property, int $contract): string
    {
        return __('contracts.messages.property_busy', ['property' => $property->number, 'contract' => $contract]);
    }

    // Договор за наем, нов

    public function test_new_lease_shows_date_field_and_busy_property_and_bad_rent_together(): void
    {
        $busy = $this->record(Property::class);
        $free = $this->record(Property::class);
        $other = $this->lease([$busy], ['number' => 7]);
        $this->actingAs($this->staff());

        $this->post('/lease-contracts', [
            'tenant_id' => $this->record(Tenant::class)->id,
            'signed_on' => '31.02.2026',
            'lines' => [
                ['property_id' => $busy->id, 'start_on' => '01.03.2026', 'rent' => '600'],
                ['property_id' => $free->id, 'start_on' => '01.03.2026', 'rent' => 'abc'],
            ],
        ])->assertSessionHasErrors(['signed_on', 'lines']);

        $this->assertContains($this->leaseBusy($busy, $other->number), $this->errors()->get('lines'));
        $this->assertContains(__('contracts.messages.rent_required'), $this->errors()->get('lines'));
        $this->assertSame(1, LeaseContract::query()->count());
    }

    public function test_new_lease_shows_missing_tenant_with_busy_property(): void
    {
        $busy = $this->record(Property::class);
        $other = $this->lease([$busy], ['number' => 7]);
        $this->actingAs($this->staff());

        $this->post('/lease-contracts', [
            'signed_on' => '10.01.2026',
            'lines' => [['property_id' => $busy->id, 'rent' => '600']],
        ])->assertSessionHasErrors(['tenant_id', 'lines']);

        $this->assertContains($this->leaseBusy($busy, $other->number), $this->errors()->get('lines'));
    }

    public function test_row_with_its_own_wrong_date_is_not_checked_for_overlap(): void
    {
        $busy = $this->record(Property::class);
        $this->lease([$busy], ['number' => 7]);
        $this->actingAs($this->staff());

        // Своя грешна начална дата.
        $this->post('/lease-contracts', [
            'tenant_id' => $this->record(Tenant::class)->id, 'signed_on' => '10.01.2026',
            'lines' => [['property_id' => $busy->id, 'start_on' => '32.01.2026', 'rent' => '600']],
        ])->assertSessionHasErrors('lines.0.start_on')->assertSessionDoesntHaveErrors('lines');

        // Без своя дата редът започва с датата на договора, а тя е грешна.
        $this->post('/lease-contracts', [
            'tenant_id' => $this->record(Tenant::class)->id, 'signed_on' => '32.01.2026',
            'lines' => [['property_id' => $busy->id, 'rent' => '600']],
        ])->assertSessionHasErrors('signed_on')->assertSessionDoesntHaveErrors('lines');

        $this->assertSame(1, LeaseContract::query()->count());
    }

    public function test_new_lease_shows_both_busy_properties(): void
    {
        $a = $this->record(Property::class);
        $b = $this->record(Property::class);
        $first = $this->lease([$a], ['number' => 7]);
        $second = $this->lease([$b], ['number' => 8]);
        $this->actingAs($this->staff());

        $this->post('/lease-contracts', [
            'tenant_id' => $this->record(Tenant::class)->id, 'signed_on' => '10.01.2026',
            'lines' => [['property_id' => $a->id, 'rent' => '600'], ['property_id' => $b->id, 'rent' => '600']],
        ])->assertSessionHasErrors('lines');

        $this->assertContains($this->leaseBusy($a, $first->number), $this->errors()->get('lines'));
        $this->assertContains($this->leaseBusy($b, $second->number), $this->errors()->get('lines'));
    }

    // Договор за управление, нов

    public function test_new_management_contract_shows_all_errors(): void
    {
        $busy = $this->record(Property::class);
        $other = $this->management([$busy], ['number' => 5]);
        $this->actingAs($this->staff());

        $this->post('/owner-contracts', [
            'type' => OwnerContractType::RepresentativePercent->value,
            'number' => 5,
            'owner_id' => $this->record(Owner::class)->id,
            'signed_on' => '10.01.2026',
            'end_on' => '31.13.2026',
            'payment_method' => PaymentMethod::Cash->value,
            'lines' => [['property_id' => $busy->id, 'start_on' => '01.02.2026']],
        ])->assertSessionHasErrors(['number', 'end_on', 'commission_percent', 'lines']);

        $this->assertContains($this->managementBusy($busy, $other->number), $this->errors()->get('lines'));
        $this->assertSame(1, OwnerContract::query()->count());
    }

    // Анекси

    public function test_lease_annex_shows_all_errors(): void
    {
        $mine = $this->record(Property::class);
        $busy = $this->record(Property::class);
        $outside = $this->record(Property::class);
        $contract = $this->lease([$mine], ['end_on' => '2026-12-31']);
        $other = $this->lease([$busy], ['number' => 9]);
        $this->actingAs($this->staff());

        $this->post("/lease-contracts/{$contract->id}/annexes", [
            'signed_on' => '30.02.2026',
            'from' => '01.06.2026',
            'end_on' => '99.99.2027',
            'lines' => [
                ['action' => 'change', 'property_id' => $outside->id, 'rent' => '700'],
                ['action' => 'add', 'property_id' => $busy->id, 'from' => '01.07.2026', 'rent' => '300'],
            ],
        ])->assertSessionHasErrors(['signed_on', 'end_on', 'lines']);

        $this->assertContains(__('contracts.messages.property_not_in_contract', ['property' => $outside->number]), $this->errors()->get('lines'));
        $this->assertContains($this->leaseBusy($busy, $other->number), $this->errors()->get('lines'));
        $this->assertSame(0, LeaseContractAnnex::query()->count());
        $this->assertSame(1, $contract->lines()->count());
    }

    public function test_annex_with_taken_number_still_checks_the_changes(): void
    {
        $mine = $this->record(Property::class);
        $busy = $this->record(Property::class);
        $contract = $this->lease([$mine]);
        $other = $this->lease([$busy], ['number' => 9]);
        app(LeaseContractService::class)->addAnnex($contract, 1, '2026-03-01');
        $this->actingAs($this->staff());

        $this->post("/lease-contracts/{$contract->id}/annexes", [
            'number' => 1, 'signed_on' => '20.05.2026', 'from' => '01.06.2026',
            'lines' => [['action' => 'add', 'property_id' => $busy->id, 'rent' => '300']],
        ])->assertSessionHasErrors(['number', 'lines']);

        $this->assertContains($this->leaseBusy($busy, $other->number), $this->errors()->get('lines'));
        $this->assertSame(1, LeaseContractAnnex::query()->count());
    }

    public function test_management_annex_shows_missing_date_and_busy_property(): void
    {
        $mine = $this->record(Property::class);
        $busy = $this->record(Property::class);
        $contract = $this->management([$mine]);
        $other = $this->management([$busy], ['number' => 9]);
        $this->actingAs($this->staff());

        $this->post("/owner-contracts/{$contract->id}/annexes", [
            'signed_on' => '20.05.2026',
            'commission_percent' => '150',
            'lines' => [['action' => 'add', 'property_id' => $busy->id, 'from' => '01.07.2026']],
        ])->assertSessionHasErrors(['from', 'commission_percent', 'lines']);

        $this->assertContains($this->managementBusy($busy, $other->number), $this->errors()->get('lines'));
        $this->assertSame(0, OwnerContractAnnex::query()->count());
    }

    // Поправка и „Промени“

    public function test_lease_correction_shows_all_errors(): void
    {
        $a = $this->record(Property::class);
        $b = $this->record(Property::class);
        $busy = $this->record(Property::class);
        $contract = $this->lease([$a, $b]);
        $other = $this->lease([$busy], ['number' => 9]);
        [$first, $second] = $contract->lines()->orderBy('id')->get()->all();
        $this->actingAs($this->staff());

        $this->put("/lease-contracts/{$contract->id}/correct", [
            'tenant_id' => '',
            'lines' => [
                $first->id => ['valid_from' => '31.04.2026', 'rent' => '600'],
                $second->id => ['property_id' => $busy->id, 'rent' => '-5'],
            ],
        ])->assertSessionHasErrors(['tenant_id', "lines.{$first->id}.valid_from", 'lines']);

        $this->assertContains(__('contracts.messages.rent_required'), $this->errors()->get('lines'));
        $this->assertSame($b->id, $second->fresh()->property_id);
        $this->assertNotNull($contract->fresh()->tenant_id);
    }

    public function test_correction_checks_overlap_of_a_row_with_valid_date(): void
    {
        $a = $this->record(Property::class);
        $busy = $this->record(Property::class);
        $contract = $this->lease([$a]);
        $other = $this->lease([$busy], ['number' => 9]);
        $line = $contract->lines()->sole();
        $this->actingAs($this->staff());

        $this->put("/lease-contracts/{$contract->id}/correct", [
            'tenant_id' => '999999',
            'lines' => [$line->id => ['property_id' => $busy->id, 'valid_from' => '01.02.2026']],
        ])->assertSessionHasErrors(['tenant_id', 'lines']);

        $this->assertContains($this->leaseBusy($busy, $other->number), $this->errors()->get('lines'));
        $this->assertSame($a->id, $line->fresh()->property_id);
    }

    public function test_edit_shows_wrong_date_and_taken_number_together(): void
    {
        $contract = $this->lease([$this->record(Property::class)], ['number' => 1]);
        $this->lease([$this->record(Property::class)], ['number' => 2]);
        $this->actingAs($this->staff());

        $this->put("/lease-contracts/{$contract->id}", [
            'number' => 2, 'signed_on' => '10.01.2026', 'end_on' => '31.06.2026', 'note' => 'Измислена бележка',
        ])->assertSessionHasErrors(['number', 'end_on']);

        $this->assertSame(1, $contract->fresh()->number);
        $this->assertNull($contract->fresh()->note);
    }

    public function test_edit_shows_taken_number_and_termination_before_start_together(): void
    {
        $contract = $this->management([$this->record(Property::class)], ['number' => 1]);
        $this->management([$this->record(Property::class)], ['number' => 2]);
        $this->actingAs($this->staff());

        $this->put("/owner-contracts/{$contract->id}", [
            'number' => 2, 'signed_on' => '01.01.2026', 'terminated_on' => '01.12.2025',
        ])->assertSessionHasErrors(['number', 'terminated_on']);

        $this->assertSame(1, $contract->fresh()->number);
        $this->assertNull($contract->fresh()->terminated_on);
    }
}
