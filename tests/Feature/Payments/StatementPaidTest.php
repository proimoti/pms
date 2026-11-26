<?php

namespace Tests\Feature\Payments;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Enums\OwnerContractType;
use App\Models\BankAccount;
use App\Models\LeaseContract;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Statement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Charges\Issuer;
use App\Services\Charges\Ledger;
use App\Services\Charges\MonthWorksheet;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use Carbon\CarbonImmutable;
use Tests\Feature\Screens\ScreenTestCase;

/**
 * Разнасяне по известия (PROJECT.md, „Плащания и салда“, 26.11.2026): за
 * всяко известие „Платено“, „Частично“ или „Неплатено“ — смята се от книгата
 * по правилото (първо най-старият месец; наем → битови и такси → неустойка и
 * „Друго“ → депозит); „Депозити → Внесен“ по същото разнасяне; колона
 * „Платено“ в списъка с известия, на картата на известието и на договора.
 *
 * Пример: договор за № 201 (300) и № 202 (200) от 01.12.2026; декември
 * (500) и януари (500) са издадени. „Днес“ е 10.01.2027.
 */
class StatementPaidTest extends ScreenTestCase
{
    private User $user;

    private Property $first;

    private LeaseContract $lease;

    private Statement $december;

    private Statement $january;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00'));
        $this->user = $this->staff();
        $this->first = $this->record(Property::class, ['number' => 201]);
        $second = $this->record(Property::class, ['number' => 202]);
        $tenant = $this->record(Tenant::class, ['name' => 'Борис Измислен']);
        $this->actingAs($this->user);

        app(OwnerContractService::class)->create(['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01',
            'lines' => [['property_id' => $this->first->id], ['property_id' => $second->id]]]);
        $this->lease = app(LeaseContractService::class)->create(['tenant_id' => $tenant->id, 'signed_on' => '2026-12-01', 'lines' => [
            ['property_id' => $this->first->id, 'rent' => '300', 'deposit' => '1000'], ['property_id' => $second->id, 'rent' => '200'],
        ]]);
        app(MonthWorksheet::class)->generate('2026-12');
        app(Issuer::class)->issueMonth('2026-12');
        app(Issuer::class)->issueNow($this->lease, '2027-01');
        $this->december = Statement::query()->whereDate('month', '2026-12-01')->sole();
        $this->january = Statement::query()->whereDate('month', '2027-01-01')->sole();
    }

    private function pay(string $amount): void
    {
        $this->post("/lease-contracts/{$this->lease->id}/payments", ['amount' => $amount, 'paid_on' => '08.01.2027', 'method' => 'cash'])
            ->assertSessionHasNoErrors();
    }

    /** Етикетът за известието в списъка с известия. */
    private function listStatus(Statement $statement): string
    {
        $html = $this->get('/statements?month=&number='.$statement->number)->assertOk()->getContent();
        $start = strpos($html, '<tr data-href="/statements/'.$statement->id.'">');
        $this->assertNotFalse($start);
        $row = substr($html, $start, strpos($html, '</tr>', $start) - $start);
        preg_match('#<td class="nowrap paid-status">(.*?)</td>#s', $row, $m);

        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1] ?? '')));
    }

    public function test_unpaid_paid_and_partly_paid(): void
    {
        $this->assertSame(__('payments.status.unpaid'), $this->listStatus($this->december));
        $this->assertSame(__('payments.status.unpaid'), $this->listStatus($this->january));

        $this->pay('500');
        $this->assertSame(__('payments.status.paid'), $this->listStatus($this->december));
        $this->assertSame(__('payments.status.unpaid'), $this->listStatus($this->january));

        $this->pay('200');
        $this->assertSame(__('payments.status.partly', ['paid' => '200,00', 'amount' => '500,00']), $this->listStatus($this->january));

        $this->get('/statements?month=2027-01')->assertSee(__('payments.column'));
    }

    public function test_reversal_brings_the_debt_back(): void
    {
        $this->pay('500');
        $this->post('/payments/'.Payment::query()->sole()->id.'/reverse', ['reason' => 'Грешка (измислено)']);

        $this->assertSame(__('payments.status.unpaid'), $this->listStatus($this->december));
    }

    public function test_correction_in_favour_of_the_tenant_counts_as_covered(): void
    {
        Ledger::book(CarbonImmutable::parse('2026-12-01'), LedgerKind::Manual, ['account' => LedgerAccount::Tenant, 'type' => ChargeType::Rent,
            'lease_contract_id' => $this->lease->id, 'tenant_id' => $this->lease->tenant_id, 'property_id' => $this->first->id, 'reason' => 'Отстъпка (измислено)'], -10000);

        $this->assertSame(__('payments.status.partly', ['paid' => '100,00', 'amount' => '500,00']), $this->listStatus($this->december));
    }

    public function test_statement_card_and_lease_card_show_the_status(): void
    {
        $this->pay('700');

        $this->get("/statements/{$this->january->id}")->assertOk()
            ->assertSee(__('payments.status.partly', ['paid' => '200,00', 'amount' => '500,00']));
        $this->get("/lease-contracts/{$this->lease->id}")
            ->assertSeeInOrder(['№ '.$this->january->number, __('payments.status.partly', ['paid' => '200,00', 'amount' => '500,00'])])
            ->assertSeeInOrder(['№ '.$this->december->number, __('payments.status.paid')]);
    }

    public function test_deposit_paid_follows_the_allocation(): void
    {
        // Депозит 1000 в декемврийското известие, след наемите.
        Ledger::book(CarbonImmutable::parse('2026-12-01'), LedgerKind::Issue, ['account' => LedgerAccount::Tenant, 'type' => ChargeType::Deposit,
            'lease_contract_id' => $this->lease->id, 'tenant_id' => $this->lease->tenant_id, 'property_id' => $this->first->id, 'statement_id' => $this->december->id], 100000);
        Ledger::book(CarbonImmutable::parse('2026-12-01'), LedgerKind::Issue, ['account' => LedgerAccount::Deposit, 'type' => ChargeType::Deposit,
            'lease_contract_id' => $this->lease->id, 'tenant_id' => $this->lease->tenant_id, 'property_id' => $this->first->id, 'statement_id' => $this->december->id], 100000);

        $this->pay('800');

        $deposits = $this->depositsSection();
        $this->assertStringContainsString('1 000,00', $deposits); // договорен и начислен
        $this->assertMatchesRegularExpression('#<td class="num paid">\s*300,00\s*</td>#', $deposits);
        $this->assertSame(__('payments.status.partly', ['paid' => '800,00', 'amount' => '1 500,00']), $this->listStatus($this->december));
    }

    public function test_deposit_paid_is_zero_without_payments(): void
    {
        $this->assertMatchesRegularExpression('#<td class="num paid">\s*0,00\s*</td>#', $this->depositsSection());
    }

    public function test_assigned_staff_sees_the_status_of_their_statements(): void
    {
        $this->pay('500');
        auth()->logout();
        $assigned = $this->assignedOnlyStaff();
        (new \App\Models\UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $assigned->id, 'property_id' => $this->first->id])->save();
        $this->actingAs($assigned);

        $this->assertSame(__('payments.status.paid'), $this->listStatus($this->december));
    }

    private function depositsSection(): string
    {
        $html = $this->get("/tenants/{$this->lease->tenant_id}")->assertOk()->getContent();

        return substr($html, strpos($html, 'id="deposits"'));
    }
}
