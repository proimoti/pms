<?php

namespace Tests\Feature\Screens;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Statement;
use App\Models\Tenant;
use App\Services\Charges\Reconciliation;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;

/**
 * Екраните за издаване (PROJECT.md, „Издаване и заключване“): „Издай месеца“
 * с преглед и отметка „Прегледах предупрежденията“, „Издай сега“ от договора
 * за наем, известията (сметките), разликите спрямо издаденото и корекциите.
 */
class IssueScreensTest extends ScreenTestCase
{
    private function rented(string $tenant = 'Петър Измислен', ?Company $company = null, string $from = '2026-01-10'): array
    {
        $company ??= $this->company;
        $property = $this->record(Property::class, [], $company);
        app(OwnerContractService::class)->create([
            'company_id' => $company->id, 'type' => OwnerContractType::RepresentativePercent,
            'owner_id' => $this->record(Owner::class, ['name' => 'Георги Измислен'], $company)->id,
            'signed_on' => '2026-01-01', 'commission_percent' => '10', 'payment_method' => PaymentMethod::Cash,
            'lines' => [['property_id' => $property->id]],
        ]);
        $lease = app(LeaseContractService::class)->create([
            'company_id' => $company->id, 'tenant_id' => $this->record(Tenant::class, ['name' => $tenant], $company)->id,
            'signed_on' => $from, 'lines' => [['property_id' => $property->id, 'rent' => '600']],
        ]);

        return [$property, $lease];
    }

    private function issued(): void
    {
        $this->post('/charges/generate', ['month' => '2026-12'])->assertRedirect('/charges?month=2026-12');
        $this->post('/charges/issue', ['month' => '2026-12', 'reviewed' => '1'])->assertRedirect('/charges?month=2026-12');
    }

    public function test_issue_month_preview_needs_the_reviewed_checkbox(): void
    {
        $this->rented();
        $this->actingAs($this->staff());
        $this->post('/charges/generate', ['month' => '2026-12']);

        $this->get('/charges?month=2026-12')->assertSee('href="/charges/issue?month=2026-12"', false);
        $this->get('/charges/issue?month=2026-12')->assertOk()
            ->assertSee('Петър Измислен')->assertSee('600,00')
            ->assertSee('name="reviewed"', false);

        $this->post('/charges/issue', ['month' => '2026-12'])->assertSessionHasErrors('reviewed');
        $this->assertSame(0, Statement::query()->count());

        $this->post('/charges/issue', ['month' => '2026-12', 'reviewed' => '1'])->assertRedirect('/charges?month=2026-12');
        $this->assertSame(1, Statement::query()->count());
    }

    public function test_issued_month_is_read_only_on_the_screen(): void
    {
        $this->rented();
        $this->actingAs($this->staff());
        $this->issued();

        $this->get('/charges?month=2026-12')->assertOk()
            ->assertSee(__('charges.issue.issued_status', ['date' => now()->format('d.m.Y')]), false)
            ->assertDontSee('action="/charges/generate"', false)
            ->assertDontSee('/charges/create?month=2026-12', false)
            ->assertDontSee('/edit"', false)
            ->assertSee('href="/statements?month=2026-12"', false);

        // Второ натискане: отказ, без нов запис.
        $entries = LedgerEntry::query()->count();
        $this->post('/charges/issue', ['month' => '2026-12', 'reviewed' => '1'])->assertSessionHasErrors('month');
        $this->assertSame($entries, LedgerEntry::query()->count());
    }

    public function test_only_staff_with_write_and_all_properties_can_issue(): void
    {
        $this->rented();
        $this->actingAs($this->staff());
        $this->post('/charges/generate', ['month' => '2026-12']);
        [, $lease] = [null, LeaseContract::query()->sole()];

        foreach ([$this->readOnlyStaff(), $this->assignedOnlyStaff()] as $user) {
            $this->actingAs($user);
            $this->get('/charges/issue?month=2026-12')->assertForbidden();
            $this->post('/charges/issue', ['month' => '2026-12', 'reviewed' => '1'])->assertForbidden();
            // „Само прикачените“ не вижда договора изобщо (няма такава страница); „само четене“ няма право.
            $status = $this->post("/lease-contracts/{$lease->id}/issue", ['month' => '12.2026', 'reviewed' => '1'])->status();
            $this->assertContains($status, [403, 404]);
        }
        $this->assertSame(0, Statement::query()->count());
    }

    public function test_statements_list_and_one_statement(): void
    {
        $this->rented();
        $this->actingAs($this->staff());
        $this->issued();
        $statement = Statement::query()->sole();

        $this->get('/statements?month=2026-12')->assertOk()
            ->assertSee('Петър Измислен')->assertSee('600,00')->assertSee("/statements/{$statement->id}", false);
        $this->get("/statements/{$statement->id}")->assertOk()
            ->assertSee(__('charges.statements.notice'))
            ->assertSee('№ '.$statement->number)
            ->assertSee(__('charges.types.rent'));
    }

    public function test_statement_of_another_company_is_not_found(): void
    {
        $this->rented('Чужд Измислен', $this->otherCompany);
        $mine = $this->staff();
        $this->actingAs($this->staff([], $this->otherCompany));
        $this->issued();
        $foreign = Statement::query()->withoutGlobalScopes()->sole();

        $this->actingAs($mine);
        $this->get('/statements?month=2026-12')->assertDontSee('Чужд Измислен');
        $this->get("/statements/{$foreign->id}")->assertNotFound();
    }

    public function test_issue_now_from_the_lease_contract(): void
    {
        [, $lease] = $this->rented('Нов Измислен', from: '2026-12-16');
        $this->actingAs($this->staff());

        $this->get("/lease-contracts/{$lease->id}")->assertSee(__('charges.issue.now'));
        $this->get("/lease-contracts/{$lease->id}/issue?month=12.2026")->assertOk()
            ->assertSee('309,68')->assertSee('name="reviewed"', false);
        $this->post("/lease-contracts/{$lease->id}/issue", ['month' => '12.2026', 'reviewed' => '1'])
            ->assertRedirect('/statements/'.Statement::query()->sole()->id);

        $this->post("/lease-contracts/{$lease->id}/issue", ['month' => '12.2026', 'reviewed' => '1'])->assertSessionHasErrors('month');
        $this->assertSame(1, Statement::query()->count());
        $this->get("/lease-contracts/{$lease->id}")->assertSee('309,68'); // записите в книгата
    }

    public function test_differences_and_correction_on_the_issued_month(): void
    {
        [, $lease] = $this->rented();
        $this->actingAs($this->staff());
        $this->issued();

        $this->put("/lease-contracts/{$lease->id}", [
            'number' => $lease->number, 'signed_on' => '10.01.2026', 'terminated_on' => '16.12.2026',
        ])->assertSessionHas('warning');

        $this->get('/charges?month=2026-12')->assertOk()
            ->assertSee(__('charges.issue.differences'))->assertSee('-309,68');

        $key = collect(app(Reconciliation::class)->differences('2026-12'))->first()->key;
        $this->post('/charges/corrections', ['month' => '2026-12', 'key' => $key, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post('/charges/corrections', ['month' => '2026-12', 'key' => $key, 'reason' => 'Прекратен'])->assertRedirect('/charges?month=2026-12');
        $this->assertSame(1, LedgerEntry::query()->where('kind', 'correction')->count());
    }

    public function test_manual_correction_form(): void
    {
        [$property, $lease] = $this->rented();
        $this->actingAs($this->staff());
        $this->issued();

        $this->get('/charges/corrections/create?month=2026-12')->assertOk()->assertSee('name="reason"', false);
        $this->post('/charges/corrections/manual', ['month' => '2026-12', 'type' => 'penalty', 'lease_contract_id' => $lease->id,
            'property_id' => $property->id, 'amount' => '-20', 'reason' => 'Сторно'])->assertRedirect('/charges?month=2026-12');
        $this->assertSame(1, LedgerEntry::query()->where('kind', 'manual')->count());
    }
}
