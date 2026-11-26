<?php

namespace Tests\Feature\Payments;

use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\BankAccount;
use App\Models\Beneficiary;
use App\Models\LedgerEntry;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\OwnerPayout;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserProperty;
use App\Services\Charges\Issuer;
use App\Services\Charges\MonthWorksheet;
use App\Services\Export\ExportBatches;
use App\Services\Export\ExportSettings;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Services\Reports\Settlements;
use Carbon\CarbonImmutable;
use Tests\Feature\Screens\ScreenTestCase;

/**
 * Изплащане към собственик или бенефициент (PROJECT.md, „Плащания и салда“,
 * 26.11.2026): 408 −, по договор за управление; погасява най-старите месеци
 * първи (по имот, разделено като при плащанията; надплатеното — към първия
 * имот). Въвежда се и от справката „За изплащане“ (бутон „Изплатено“), където
 * има „Изплатено“ и „Остава“; сумата „За изплащане“ е само начисленото.
 * Сторно с причина, само веднъж. Само управляващият. Не влиза в експорта и не
 * обърква издаването, сверката и „Разчети за период“.
 *
 * Пример: Петя, „Представител, %“ 10 % за № 401 и № 402; Борис плаща наем 500 и
 * 300 от 01.12.2026 → към Петя 450 + 270 = 720 на месец. Декември е издаден, а
 * януари — с „Издай сега“. „Днес“ е 10.01.2027.
 */
class OwnerPayoutTest extends ScreenTestCase
{
    private User $user;

    private Property $first;

    private Property $second;

    private OwnerContract $management;

    private BankAccount $bank;

    private Beneficiary $beneficiary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00'));
        $this->user = $this->staff(['is_company_admin' => true, 'can_view_sensitive' => true]);
        $this->first = $this->record(Property::class, ['number' => 401]);
        $this->second = $this->record(Property::class, ['number' => 402]);
        $owner = $this->record(Owner::class, ['name' => 'Петя Измислена']);
        $tenant = $this->record(Tenant::class, ['name' => 'Борис Измислен']);
        $this->beneficiary = Beneficiary::factory()->create(['company_id' => $this->company->id, 'name' => 'Иван Получател', 'iban' => 'BG18RZBB91550123456789']);
        $this->actingAs($this->user);

        $this->management = app(OwnerContractService::class)->create(['type' => OwnerContractType::RepresentativePercent, 'owner_id' => $owner->id,
            'signed_on' => '2026-01-01', 'commission_percent' => '10', 'beneficiary_id' => $this->beneficiary->id, 'payment_method' => PaymentMethod::Bank,
            'lines' => [['property_id' => $this->first->id], ['property_id' => $this->second->id]]]);
        $lease = app(LeaseContractService::class)->create(['tenant_id' => $tenant->id, 'signed_on' => '2026-12-01', 'lines' => [
            ['property_id' => $this->first->id, 'rent' => '500'], ['property_id' => $this->second->id, 'rent' => '300'],
        ]]);
        app(MonthWorksheet::class)->generate('2026-12');
        app(Issuer::class)->issueMonth('2026-12');
        app(Issuer::class)->issueNow($lease, '2027-01');

        $this->bank = BankAccount::query()->create(['bank_name' => 'Измислена банка', 'iban' => 'BG80BNBG96611020345678']);
        $this->bank->forceFill(['for_statements' => true])->save();
    }

    private function payout(string $amount, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post("/owner-contracts/{$this->management->id}/payouts", $extra + [
            'amount' => $amount, 'paid_on' => '09.01.2027', 'method' => 'bank', 'bank_account_id' => $this->bank->id, 'note' => 'Изплащане (измислено)',
        ]);
    }

    /** 408 от изплащанията по имот: [№ 401, № 402]. */
    private function paidOut(): array
    {
        $sum = fn (Property $p) => (float) LedgerEntry::query()->where('account', LedgerAccount::Owner->value)
            ->whereIn('kind', [LedgerKind::Payout->value, LedgerKind::PayoutReversal->value])->where('property_id', $p->id)->sum('amount');

        return [$sum($this->first), $sum($this->second)];
    }

    public function test_payout_pays_the_oldest_months_first(): void
    {
        $this->get("/owner-contracts/{$this->management->id}/payouts/create")->assertOk()->assertSee('Иван Получател')->assertSee('1 440,00');
        $this->payout('900')->assertRedirect("/owner-contracts/{$this->management->id}")->assertSessionHasNoErrors();

        $payout = OwnerPayout::query()->sole();
        $this->assertSame(['900.00', '2027-01-09', 'bank', $this->bank->id, $this->beneficiary->id, $this->management->owner_id],
            [$payout->amount, $payout->paid_on->format('Y-m-d'), $payout->method, $payout->bank_account_id, $payout->beneficiary_id, $payout->owner_id]);
        // Декември 450 + 270, после януари 180 за № 401.
        $this->assertSame([-630.0, -270.0], $this->paidOut());
        $this->get("/owner-contracts/{$this->management->id}")
            ->assertSee(__('payouts.section'))->assertSeeInOrder([__('payouts.summary.accrued'), '1 440,00', __('payouts.summary.paid'), '900,00', __('payouts.summary.left'), '540,00']);
    }

    public function test_overpayment_goes_to_the_first_property(): void
    {
        $this->payout('1500');

        $this->assertSame([-960.0, -540.0], $this->paidOut());
        $this->get("/owner-contracts/{$this->management->id}")->assertSee(__('dossiers.overpaid', ['amount' => '60,00']));
    }

    public function test_report_shows_paid_and_left_and_counts_only_the_accrued(): void
    {
        $this->payout('900');

        $december = $this->get('/reports/payouts?month=12.2026')->assertOk()->getContent();
        $this->assertStringContainsString(__('payouts.report.paid'), $december);
        $this->assertMatchesRegularExpression('#720,00.*?720,00.*?0,00#s', $this->row($december));

        $january = $this->get('/reports/payouts?month=01.2027')->getContent();
        $this->assertMatchesRegularExpression('#720,00.*?180,00.*?540,00#s', $this->row($january));
        $this->assertStringContainsString('href="/owner-contracts/'.$this->management->id.'/payouts/create?amount=540.00"', $january);
        $this->assertStringNotContainsString('/payouts/create', $this->row($december));
    }

    private function row(string $html): string
    {
        $start = strpos($html, 'href="/owner-contracts/'.$this->management->id.'"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</tr>', $start) - $start);
    }

    public function test_reversal_with_a_reason_only_once(): void
    {
        $this->payout('900');
        $payout = OwnerPayout::query()->sole();

        $this->post("/payouts/{$payout->id}/reverse", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/payouts/{$payout->id}/reverse", ['reason' => 'Върнат превод (измислено)'])->assertRedirect("/owner-contracts/{$this->management->id}");
        $this->assertSame([0.0, 0.0], $this->paidOut());
        $this->post("/payouts/{$payout->id}/reverse", ['reason' => 'Пак'])->assertSessionHasErrors('reason');

        $reversal = OwnerPayout::query()->where('reversal_of_id', $payout->id)->sole();
        $this->post("/payouts/{$reversal->id}/reverse", ['reason' => 'Пак'])->assertNotFound();
        $this->get("/owner-contracts/{$this->management->id}")->assertSee(__('payments.reversed'))->assertSee('Върнат превод (измислено)');
    }

    public function test_validation(): void
    {
        $this->payout('0')->assertSessionHasErrors('amount');
        $this->payout('100', ['paid_on' => '32.01.2027'])->assertSessionHasErrors('paid_on');
        $this->payout('100', ['method' => 'card'])->assertSessionHasErrors('method');
        $this->payout('100', ['bank_account_id' => ''])->assertSessionHasErrors('bank_account_id');
        $this->payout('100', ['method' => 'cash', 'bank_account_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull(OwnerPayout::query()->sole()->bank_account_id);
    }

    public function test_payouts_are_not_exported_and_do_not_change_accruals_or_settlements(): void
    {
        app(ExportSettings::class)->save(['tenant' => '418', 'deposit' => '491', 'owner' => '408', 'utility' => '407', 'fund' => '406',
            'revenue_representative_percent' => '703/1', 'revenue_representative_fixed' => '703/2', 'revenue_sublease_individual' => '703/3',
            'revenue_sublease_legal' => '703/4', 'revenue_own' => '417/1', 'revenue_penalty' => '703/9', 'revenue_other' => '709', 'manager_cost' => '609/2']);
        $this->payout('300', ['paid_on' => '20.12.2026']);
        $this->post('/payouts/'.OwnerPayout::query()->sole()->id.'/reverse', ['reason' => 'Проба (измислено)']);
        $this->payout('500', ['paid_on' => '21.12.2026']);
        $payoutEntries = LedgerEntry::query()->whereIn('kind', [LedgerKind::Payout->value, LedgerKind::PayoutReversal->value])->pluck('id');

        $batch = app(ExportBatches::class)->create(CarbonImmutable::parse('2026-12-01'));
        $this->assertEmpty(array_intersect($payoutEntries->all(), $batch->entries()->pluck('ledger_entry_id')->all()));
        $this->assertTrue(app(\App\Services\Export\MicroinvestRows::class)->build($batch)['ok']);

        // „Разчети за период“: към собственика пак е начисленото (720), без изплащанията.
        $rows = app(Settlements::class)->build(CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-01'))['rows'];
        $this->assertSame(['450.00', '270.00'], array_map(fn ($r) => $r['owner'], $rows));
    }

    public function test_issuing_the_month_after_a_payout_books_the_same_owner_share(): void
    {
        // Януари: „Издай сега“ е записал 450 + 270; изплащане в януари; после се издава целият месец.
        $this->payout('500');
        app(MonthWorksheet::class)->generate('2027-01');
        app(Issuer::class)->issueMonth('2027-01');

        $accrued = (float) LedgerEntry::query()->where('account', LedgerAccount::Owner->value)->whereDate('month', '2027-01-01')
            ->whereNotIn('kind', [LedgerKind::Payout->value, LedgerKind::PayoutReversal->value])->sum('amount');
        $this->assertSame(720.0, $accrued);
    }

    public function test_only_the_charges_manager(): void
    {
        $this->payout('100');
        $payout = OwnerPayout::query()->sole();

        foreach ([$this->readOnlyStaff(), $this->assignedOnlyStaff()] as $staff) {
            auth()->logout();
            $this->actingAs($staff);
            if ($staff->property_scope?->value === 'assigned') {
                (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $staff->id, 'property_id' => $this->first->id])->save();
            }
            $this->get("/owner-contracts/{$this->management->id}/payouts/create")->assertForbidden();
            $this->payout('100')->assertForbidden();
            $this->post("/payouts/{$payout->id}/reverse", ['reason' => 'Х'])->assertForbidden();
            $this->get('/reports/payouts?month=12.2026')->assertDontSee('/payouts/create', false);
        }
        $this->assertSame(1, OwnerPayout::query()->withoutGlobalScopes()->count());
    }

    public function test_company_b_cannot_pay_out_or_reverse(): void
    {
        $this->payout('100');
        $payout = OwnerPayout::query()->sole();
        auth()->logout();
        $this->actingAs($this->staff([], $this->otherCompany));

        $this->payout('100')->assertNotFound();
        $this->post("/payouts/{$payout->id}/reverse", ['reason' => 'Х'])->assertNotFound();
        $this->assertSame(0, OwnerPayout::query()->count());
    }
}
