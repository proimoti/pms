<?php

namespace Tests\Feature\Screens;

use App\Models\Property;
use App\Models\Statement;
use App\Models\User;
use App\Models\UserProperty;
use App\Services\Dossiers\Dossier;
use Tests\Support\DossierScenario;

/**
 * Досиетата (PROJECT.md, „Досиета и първи справки“, 23.11.2026): същата карта
 * с добавени раздели; всеки номер е връзка към своето досие. Салдото по 418
 * е на картата на наемателя (общо и по договор), на договора за наем е само
 * „Дължи по този договор“. Пише „Дължи“. Само измислени данни.
 */
class DossierScreensTest extends ScreenTestCase
{
    use DossierScenario;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->staff();
        $this->buildDossierScenario($this->user);
    }

    public function test_property_dossier(): void
    {
        $page = $this->get("/properties/{$this->flat->id}")->assertOk();

        // Собственик и договор за управление
        $page->assertSee(__('dossiers.sections.management'))
            ->assertSee('href="/owner-contracts/'.$this->management->id.'"', false)
            ->assertSee('href="/owners/'.$this->owner->id.'"', false)->assertSee('Петя Измислена');
        // Договори за наем: сегашен и минал
        $page->assertSee(__('dossiers.sections.leases'))
            ->assertSee('href="/lease-contracts/'.$this->lease->id.'"', false)->assertSee('Борис Измислен')
            ->assertSee('href="/lease-contracts/'.$this->oldLease->id.'"', false)->assertSee('Анна Измислена')
            ->assertSeeInOrder([__('dossiers.current'), 'Борис Измислен', __('dossiers.past'), 'Анна Измислена']);
        // Абонати и фонд
        $page->assertSee(__('dossiers.sections.accounts'))->assertSee('href="/utility-accounts/'.$this->meter->id.'"', false)->assertSee('№ 0012345')
            ->assertSee('Вход (измислен)'); // фондът — при абонатите (т. 2.2, 24.11.2026)
        // Неразпределени сметки и салда
        $page->assertSee(__('dossiers.sections.unallocated'))->assertSee('90,00')
            ->assertSee(__('dossiers.sections.balances'))
            ->assertSeeInOrder([__('dossiers.balances.tenant'), '500,00'])
            ->assertSeeInOrder([__('dossiers.balances.owner'), '450,00'])
            ->assertSeeInOrder([__('dossiers.balances.utility'), '90,00']);
    }

    public function test_owner_dossier(): void
    {
        $this->get("/owners/{$this->owner->id}")->assertOk()
            ->assertSee(__('dossiers.sections.properties'))->assertSee('href="/properties/'.$this->flat->id.'"', false)
            ->assertSee(__('dossiers.sections.owner_contracts'))->assertSee('href="/owner-contracts/'.$this->management->id.'"', false)
            ->assertSee(__('dossiers.sections.annexes'))->assertSee(__('contracts.screens.annex', ['number' => 1]))->assertSee('01.03.2026')
            ->assertSee(__('dossiers.sections.beneficiaries'))->assertSee('href="/beneficiaries/'.$this->beneficiary->id.'"', false)
            ->assertDontSee('BG18RZBB91550123456789')->assertDontSee('BG18 RZBB');
    }

    public function test_beneficiary_dossier(): void
    {
        $this->get("/beneficiaries/{$this->beneficiary->id}")->assertOk()
            ->assertSee(__('dossiers.sections.payee_contracts'))
            ->assertSee('href="/owner-contracts/'.$this->management->id.'"', false)->assertSee('Петя Измислена')
            ->assertSee(__('contracts.payment_methods.bank'))
            ->assertSee(__('dossiers.sections.properties'))->assertSee('href="/properties/'.$this->flat->id.'"', false)
            ->assertSee(__('dossiers.sections.annexes'))->assertSee(__('contracts.screens.annex', ['number' => 1]));
    }

    public function test_tenant_dossier_has_the_418_balance_in_total_and_per_contract(): void
    {
        $statement = Statement::query()->where('lease_contract_id', $this->lease->id)->sole();

        $this->get("/tenants/{$this->boris->id}")->assertOk()
            ->assertSee(__('dossiers.sections.leases'))->assertSee('href="/lease-contracts/'.$this->lease->id.'"', false)
            // Известията са в картата на договора за наем (т. 2.6, 24.11.2026).
            ->assertDontSee('<h2>'.__('dossiers.sections.statements').'</h2>', false)->assertDontSee('href="/statements/'.$statement->id.'"', false)
            ->assertSee(__('dossiers.sections.owes'))->assertSeeInOrder([__('dossiers.owes_total'), '500,00'])
            ->assertDontSee(__('dossiers.charged'));

        $this->get("/tenants/{$this->anna->id}")->assertOk()->assertSee('href="/lease-contracts/'.$this->oldLease->id.'"', false)
            ->assertSeeInOrder([__('dossiers.owes_total'), '0,00']);
    }

    public function test_lease_contract_shows_only_the_owes_line_and_its_statements(): void
    {
        $statement = Statement::query()->where('lease_contract_id', $this->lease->id)->sole();

        $this->get("/lease-contracts/{$this->lease->id}")->assertOk()
            ->assertSeeInOrder([__('dossiers.owes_on_contract'), '500,00'])
            ->assertSee('href="/tenants/'.$this->boris->id.'"', false)
            ->assertSee(__('dossiers.sections.statements'))->assertSee('href="/statements/'.$statement->id.'"', false);
    }

    public function test_lease_card_layout_owes_under_the_state_and_issue_now_in_the_statements_section(): void
    {
        $this->get("/lease-contracts/{$this->lease->id}")->assertOk()
            ->assertSeeInOrder([__('contracts.screens.fields.state'), __('dossiers.owes_on_contract'), '500,00', __('contracts.screens.fields.tenant')])
            ->assertSeeInOrder([__('contracts.screens.sections.annexes'), __('dossiers.sections.statements'), __('charges.fields.month'), __('charges.issue.now'), __('charges.issue.ledger')])
            ->assertSee('<label for="issue-month">'.__('charges.fields.month').'</label>', false);
    }

    public function test_owner_contract_shows_its_408_entries(): void
    {
        $this->get("/owner-contracts/{$this->management->id}")->assertOk()
            ->assertSee(__('dossiers.sections.ledger_408'))->assertSee('450,00')->assertSee('Декември 2026');
    }

    public function test_balances_are_computed_from_the_ledger(): void
    {
        $dossier = app(Dossier::class);

        $this->assertSame(['500.00', [$this->lease->id => '500.00']], [$dossier->tenantOwes($this->boris)['total'], $dossier->tenantOwes($this->boris)['contracts']]);
        $this->assertSame('500.00', $dossier->leaseOwes($this->lease));
        // Друг имот със свой издаден наем (300 €) не влиза в салдата на № 101.
        $other = Property::factory()->create(['company_id' => $this->company->id, 'number' => 104]);
        app(\App\Services\OwnerContractService::class)->create(['type' => \App\Enums\OwnerContractType::Own, 'signed_on' => '2026-01-01', 'lines' => [['property_id' => $other->id]]]);
        $late = app(\App\Services\LeaseContractService::class)->create(['tenant_id' => $this->anna->id, 'signed_on' => '2026-12-01', 'lines' => [['property_id' => $other->id, 'rent' => '300']]]);
        app(\App\Services\Charges\Issuer::class)->issueNow($late, '2026-12');

        $balances = $dossier->propertyBalances($this->flat);
        $this->assertSame(['500.00', '450.00', '90.00'], [$balances['tenant'], $balances['owner'], $balances['utility']]);
        $this->assertSame('300.00', $dossier->propertyBalances($other)['tenant']);
    }

    // Достъп и фирми

    public function test_assigned_only_staff_sees_only_what_belongs_to_their_properties(): void
    {
        $other = Property::factory()->create(['company_id' => $this->company->id, 'number' => 103]);
        app(\App\Services\LeaseContractService::class)->create([
            'tenant_id' => $this->boris->id, 'signed_on' => '2026-06-01', 'lines' => [['property_id' => $other->id, 'rent' => '300']],
        ]);
        $assigned = $this->assignedOnlyStaff();
        (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $assigned->id, 'property_id' => $this->flat->id])->save();
        $this->actingAs($assigned);

        $this->get("/tenants/{$this->boris->id}")->assertOk()->assertSee('href="/lease-contracts/'.$this->lease->id.'"', false)
            ->assertDontSee('№ 103');
        $this->get("/properties/{$other->id}")->assertNotFound();
    }

    public function test_company_b_does_not_see_the_dossiers_of_company_a(): void
    {
        auth()->logout();
        $foreign = $this->staff([], $this->otherCompany);
        $this->assertSame($this->otherCompany->id, $foreign->company_id);
        $this->actingAs($foreign);

        foreach (["/properties/{$this->flat->id}", "/owners/{$this->owner->id}", "/tenants/{$this->boris->id}",
            "/beneficiaries/{$this->beneficiary->id}", "/lease-contracts/{$this->lease->id}", "/owner-contracts/{$this->management->id}"] as $url) {
            $this->get($url)->assertNotFound();
        }

        // И в своите досиета не вижда нищо от фирма A.
        $own = Property::factory()->create(['company_id' => $this->otherCompany->id, 'number' => 101]);
        $this->get("/properties/{$own->id}")->assertOk()->assertDontSee('Петя Измислена')->assertDontSee('Борис Измислен')->assertDontSee('0012345');
    }
}
