<?php

namespace Tests\Feature\Payments;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Enums\OwnerContractType;
use App\Models\BankAccount;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserProperty;
use App\Services\Charges\Issuer;
use App\Services\Charges\Ledger;
use App\Services\Charges\MonthWorksheet;
use App\Services\Export\ExportBatches;
use App\Services\Export\ExportSettings;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use Carbon\CarbonImmutable;
use Tests\Feature\Screens\ScreenTestCase;

/**
 * Плащане от наемател (PROJECT.md, „Плащания и салда“, 26.11.2026): ръчно,
 * към един договор; в книгата 418 − по имот, разделено по правилото за
 * разнасяне (първо най-старият месец; в известие наем → битови и такси →
 * неустойка и „Друго“ → депозит); надплатеното — към първия имот на договора.
 * Сторно с обратен запис и причина, само веднъж. Само управляващият
 * начисленията. Плащанията не влизат в пакетите за експорт.
 *
 * Пример: договор № … за имоти № 201 (наем 300) и № 202 (наем 200) от 01.12.2026;
 * декември е издаден (500), януари е издаден с „Издай сега“ (500). „Днес“ е 10.01.2027.
 */
class TenantPaymentTest extends ScreenTestCase
{
    private User $user;

    private Property $first;

    private Property $second;

    private LeaseContract $lease;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00'));
        $this->user = $this->staff(['is_company_admin' => true]);
        $this->first = $this->record(Property::class, ['number' => 201]);
        $this->second = $this->record(Property::class, ['number' => 202]);
        $tenant = $this->record(Tenant::class, ['name' => 'Борис Измислен']);
        $this->actingAs($this->user);

        app(OwnerContractService::class)->create(['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01',
            'lines' => [['property_id' => $this->first->id], ['property_id' => $this->second->id]]]);
        $this->lease = app(LeaseContractService::class)->create(['tenant_id' => $tenant->id, 'signed_on' => '2026-12-01', 'lines' => [
            ['property_id' => $this->first->id, 'rent' => '300'], ['property_id' => $this->second->id, 'rent' => '200'],
        ]]);
        app(MonthWorksheet::class)->generate('2026-12');
        app(Issuer::class)->issueMonth('2026-12');
        app(Issuer::class)->issueNow($this->lease, '2027-01');

        $this->bank = BankAccount::query()->create(['bank_name' => 'Измислена банка', 'iban' => 'BG80BNBG96611020345678']);
        $this->bank->forceFill(['for_statements' => true])->save();
    }

    private function pay(string $amount, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post("/lease-contracts/{$this->lease->id}/payments", $extra + [
            'amount' => $amount, 'paid_on' => '08.01.2027', 'method' => 'bank', 'bank_account_id' => $this->bank->id, 'reference' => 'Превод (измислен)',
        ]);
    }

    /** 418 от плащанията по имот: [№ 201, № 202] в евро. */
    private function paidByProperty(): array
    {
        $sum = fn (Property $p) => (float) LedgerEntry::query()->where('account', LedgerAccount::Tenant->value)
            ->whereIn('kind', [LedgerKind::Payment->value, LedgerKind::PaymentReversal->value])->where('property_id', $p->id)->sum('amount');

        return [$sum($this->first), $sum($this->second)];
    }

    public function test_payment_is_recorded_and_split_oldest_month_first(): void
    {
        $this->pay('800')->assertRedirect("/lease-contracts/{$this->lease->id}")->assertSessionHasNoErrors();

        $payment = Payment::query()->sole();
        $this->assertSame(['800.00', '2027-01-08', 'bank', $this->bank->id, 'Превод (измислен)', $this->user->id],
            [$payment->amount, $payment->paid_on->format('Y-m-d'), $payment->method, $payment->bank_account_id, $payment->reference, $payment->created_by]);
        // Декември 300 + 200, после януари 300 за № 201.
        $this->assertSame([-600.0, -200.0], $this->paidByProperty());
        $entry = LedgerEntry::query()->where('kind', LedgerKind::Payment->value)->first();
        $this->assertSame([$payment->id, $this->lease->id, '2027-01-01'], [$entry->payment_id, $entry->lease_contract_id, $entry->month->format('Y-m-d')]);

        $this->get("/lease-contracts/{$this->lease->id}")->assertSeeInOrder([__('dossiers.owes_on_contract'), '200,00']);
    }

    public function test_second_payment_continues_where_the_first_stopped(): void
    {
        $this->pay('250');
        $this->pay('300');

        // 250 → № 201 (декември); 300 → № 201 50 и № 202 200 (декември), № 201 50 (януари).
        $this->assertSame([-350.0, -200.0], $this->paidByProperty());
    }

    public function test_deposit_is_last_in_the_statement(): void
    {
        Ledger::book(CarbonImmutable::parse('2026-12-01'), LedgerKind::Manual, ['account' => LedgerAccount::Tenant, 'type' => ChargeType::Deposit,
            'lease_contract_id' => $this->lease->id, 'tenant_id' => $this->lease->tenant_id, 'property_id' => $this->first->id, 'reason' => 'Депозит (измислен)',
            // В декемврийското известие, при наемите.
            'statement_id' => \App\Models\Statement::query()->where('lease_contract_id', $this->lease->id)->whereDate('month', '2026-12-01')->value('id')], 100000);

        $this->pay('600');

        // Наемите за декември (500) са преди депозита; остатъкът 100 е за депозита на № 201.
        $this->assertSame([-400.0, -200.0], $this->paidByProperty());
    }

    public function test_overpayment_goes_to_the_first_property_and_shows_as_overpaid(): void
    {
        $this->pay('1100');

        $this->assertSame([-700.0, -400.0], $this->paidByProperty());
        $this->get("/lease-contracts/{$this->lease->id}")->assertSee(__('dossiers.overpaid', ['amount' => '100,00']));
    }

    public function test_cash_payment_has_no_bank_account_and_bank_needs_one(): void
    {
        $this->pay('100', ['method' => 'cash', 'bank_account_id' => $this->bank->id])->assertSessionHasNoErrors();
        $this->assertNull(Payment::query()->sole()->bank_account_id);

        $this->pay('100', ['bank_account_id' => ''])->assertSessionHasErrors('bank_account_id');
    }

    public function test_validation(): void
    {
        $this->pay('0')->assertSessionHasErrors('amount');
        $this->pay('-5')->assertSessionHasErrors('amount');
        $this->pay('10,555')->assertSessionHasErrors('amount');
        $this->pay('100', ['paid_on' => '31.02.2027'])->assertSessionHasErrors('paid_on');
        $this->pay('100', ['method' => 'card'])->assertSessionHasErrors('method');
        $this->bank->forceFill(['archived_at' => now(), 'for_statements' => false])->save();
        $this->pay('100')->assertSessionHasErrors('bank_account_id');

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, LedgerEntry::query()->where('kind', LedgerKind::Payment->value)->count());
    }

    public function test_amount_with_comma_is_accepted(): void
    {
        $this->pay('150,50')->assertSessionHasNoErrors();
        $this->assertSame('150.50', Payment::query()->sole()->amount);
    }

    public function test_reversal_with_a_reason_only_once(): void
    {
        $this->pay('800');
        $payment = Payment::query()->sole();

        $this->post("/payments/{$payment->id}/reverse", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/payments/{$payment->id}/reverse", ['reason' => 'Грешна сума (измислено)'])->assertRedirect("/lease-contracts/{$this->lease->id}");

        $this->assertSame([0.0, 0.0], $this->paidByProperty());
        $reversal = Payment::query()->where('reversal_of_id', $payment->id)->sole();
        $this->assertSame(['-800.00', 'Грешна сума (измислено)'], [$reversal->amount, $reversal->reason]);
        $this->get("/lease-contracts/{$this->lease->id}")->assertSeeInOrder([__('dossiers.owes_on_contract'), '1 000,00'])
            ->assertSee(__('payments.reversed'))->assertSee('Грешна сума (измислено)');

        $this->post("/payments/{$payment->id}/reverse", ['reason' => 'Пак'])->assertSessionHasErrors('reason');
        $this->post("/payments/{$reversal->id}/reverse", ['reason' => 'Пак'])->assertNotFound();
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_payments_are_append_only(): void
    {
        $this->pay('100');
        $payment = Payment::query()->sole();

        $this->expectException(\LogicException::class);
        $payment->update(['amount' => '200']);
    }

    public function test_payments_never_go_into_an_export_batch(): void
    {
        app(ExportSettings::class)->save(['tenant' => '418', 'deposit' => '491', 'owner' => '408', 'utility' => '407', 'fund' => '406',
            'revenue_representative_percent' => '703/1', 'revenue_representative_fixed' => '703/2', 'revenue_sublease_individual' => '703/3',
            'revenue_sublease_legal' => '703/4', 'revenue_own' => '417/1', 'revenue_penalty' => '703/9', 'revenue_other' => '709', 'manager_cost' => '609/2']);
        $this->pay('300', ['paid_on' => '15.12.2026']);
        $this->post('/payments/'.Payment::query()->sole()->id.'/reverse', ['reason' => 'Проба (измислено)']);
        $paymentEntries = LedgerEntry::query()->whereIn('kind', [LedgerKind::Payment->value, LedgerKind::PaymentReversal->value])->pluck('id');
        $this->assertCount(2, $paymentEntries);

        $batch = app(ExportBatches::class)->create(CarbonImmutable::parse('2026-12-01'));

        $this->assertEmpty(array_intersect($paymentEntries->all(), $batch->entries()->pluck('ledger_entry_id')->all()));
        $this->assertSame(0, app(ExportBatches::class)->pending(CarbonImmutable::parse('2026-12-01'))->count());
        $this->assertTrue(app(\App\Services\Export\MicroinvestRows::class)->build($batch)['ok']);
    }

    public function test_only_the_charges_manager(): void
    {
        $this->pay('100');
        $payment = Payment::query()->sole();

        foreach ([$this->readOnlyStaff(), $this->assignedOnlyStaff()] as $staff) {
            auth()->logout();
            $this->actingAs($staff);
            if ($staff->property_scope?->value === 'assigned') {
                (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $staff->id, 'property_id' => $this->first->id])->save();
            }
            $this->get("/lease-contracts/{$this->lease->id}/payments/create")->assertForbidden();
            $this->pay('100')->assertForbidden();
            $this->post("/payments/{$payment->id}/reverse", ['reason' => 'Х'])->assertForbidden();
            $this->get("/lease-contracts/{$this->lease->id}")->assertOk()->assertDontSee('/payments/create', false)->assertSee('100,00');
        }
        $this->assertSame(1, Payment::query()->withoutGlobalScopes()->count());
    }

    public function test_company_b_cannot_pay_or_reverse(): void
    {
        $this->pay('100');
        $payment = Payment::query()->sole();
        auth()->logout();
        $this->actingAs($this->staff([], $this->otherCompany));

        $this->pay('100')->assertNotFound();
        $this->post("/payments/{$payment->id}/reverse", ['reason' => 'Х'])->assertNotFound();
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_screens_lease_card_and_tenant_card(): void
    {
        $this->get("/lease-contracts/{$this->lease->id}/payments/create")->assertOk()
            ->assertSee('name="amount"', false)->assertSee('name="paid_on"', false)->assertSee('BG80 BNBG 9661 1020 3456 78');
        $this->pay('800');

        $this->get("/lease-contracts/{$this->lease->id}")->assertSee(__('payments.section'))
            ->assertSeeInOrder(['08.01.2027', '800,00', __('payments.methods.bank'), 'Превод (измислен)'])
            ->assertSee('href="/lease-contracts/'.$this->lease->id.'/payments/create"', false);
        $this->get("/tenants/{$this->lease->tenant_id}")->assertSee('href="/lease-contracts/'.$this->lease->id.'/payments/create"', false);
    }
}
