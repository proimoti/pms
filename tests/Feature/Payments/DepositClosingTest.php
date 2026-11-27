<?php

namespace Tests\Feature\Payments;

use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Enums\OwnerContractType;
use App\Models\BankAccount;
use App\Models\DepositClosing;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\Property;
use App\Models\Statement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserProperty;
use App\Services\Charges\Issuer;
use App\Services\Charges\MonthWorksheet;
use App\Services\Charges\Reconciliation;
use App\Services\Charges\UpcomingChargeService;
use App\Services\Export\ExportBatches;
use App\Services\Export\ExportSettings;
use App\Services\Export\MicroinvestRows;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Services\Payments\PaymentService;
use Carbon\CarbonImmutable;
use Tests\Feature\Screens\ScreenTestCase;

/**
 * Приключване на депозит (PROJECT.md, „Приключване на депозит“, 26.11.2026):
 * винаги ръчно, от картата на договора, с един или няколко начина наведнъж —
 * прихващане срещу дълга по 418, връщане на наемателя (банка или в брой),
 * приход на фирмата (сметката от „Настройки за експорта“). По всеки имот сборът
 * е до държания депозит (491); прихващането е до дълга по договора. В експорта:
 * прихващане 491 → 418 и приход 491 → сметката за приход; връщането — не.
 * Сторно с причина. Само управляващият начисленията.
 *
 * Пример: договор за № 601 (наем 300, депозит 1000) и № 602 (наем 200,
 * депозит 400) от 01.12.2026. Декември е издаден (наем 500 + депозити 1400) и
 * платен; януари е издаден (500, неплатен). „Днес“ е 10.01.2027. Само измислени данни.
 */
class DepositClosingTest extends ScreenTestCase
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
        $this->first = $this->record(Property::class, ['number' => 601]);
        $this->second = $this->record(Property::class, ['number' => 602]);
        $tenant = $this->record(Tenant::class, ['name' => 'Борис Измислен']);
        $this->actingAs($this->user);

        app(OwnerContractService::class)->create(['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01',
            'lines' => [['property_id' => $this->first->id], ['property_id' => $this->second->id]]]);
        $this->lease = app(LeaseContractService::class)->create(['tenant_id' => $tenant->id, 'signed_on' => '2026-12-01', 'lines' => [
            ['property_id' => $this->first->id, 'rent' => '300', 'deposit' => '1000'],
            ['property_id' => $this->second->id, 'rent' => '200', 'deposit' => '400'],
        ]]);
        foreach ([[$this->first, '1000'], [$this->second, '400']] as [$property, $amount]) {
            app(UpcomingChargeService::class)->create(['lease_contract_id' => $this->lease->id, 'property_id' => $property->id,
                'type' => 'deposit', 'month' => '2026-12', 'amount' => $amount]);
        }
        app(MonthWorksheet::class)->generate('2026-12');
        app(Issuer::class)->issueMonth('2026-12');
        app(PaymentService::class)->record($this->lease, ['amount' => '1900', 'paid_on' => '05.12.2026', 'method' => 'cash']);
        app(MonthWorksheet::class)->generate('2027-01');
        app(Issuer::class)->issueMonth('2027-01');

        $this->bank = BankAccount::query()->create(['bank_name' => 'Измислена банка', 'iban' => 'BG80BNBG96611020345678']);
    }

    private function close(array $lines, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $input = [];
        foreach ($lines as $propertyId => $values) {
            $input[$propertyId] = $values;
        }

        return $this->post("/lease-contracts/{$this->lease->id}/deposit-closings", $extra + [
            'closed_on' => '10.01.2027', 'lines' => $input, 'refund_method' => 'bank', 'bank_account_id' => $this->bank->id,
            'note' => 'Край на договора (измислено)',
        ]);
    }

    /** Прихващане 500 и връщане 300 и приход 200 от № 601; връщане 400 от № 602. */
    private function closeAll(): \Illuminate\Testing\TestResponse
    {
        return $this->close([
            $this->first->id => ['offset' => '500', 'refund' => '300', 'income' => '200'],
            $this->second->id => ['offset' => '', 'refund' => '400', 'income' => ''],
        ]);
    }

    private function held(?Property $property = null): float
    {
        return (float) LedgerEntry::query()->where('account', LedgerAccount::Deposit->value)->where('lease_contract_id', $this->lease->id)
            ->when($property, fn ($q) => $q->where('property_id', $property->id))->sum('amount');
    }

    private function owes(): float
    {
        return (float) LedgerEntry::query()->where('account', LedgerAccount::Tenant->value)->where('lease_contract_id', $this->lease->id)->sum('amount');
    }

    private function settings(array $extra = []): void
    {
        app(ExportSettings::class)->save($extra + ['tenant' => '418', 'deposit' => '491', 'owner' => '408', 'utility' => '407', 'fund' => '406',
            'revenue_representative_percent' => '703/1', 'revenue_representative_fixed' => '703/2', 'revenue_sublease_individual' => '703/3',
            'revenue_sublease_legal' => '703/4', 'revenue_own' => '417/1', 'revenue_penalty' => '703/9', 'revenue_other' => '709',
            'manager_cost' => '609/2', 'deposit_income' => '709/5']);
    }

    public function test_lease_card_shows_the_held_deposit_and_the_form(): void
    {
        $this->get("/lease-contracts/{$this->lease->id}")->assertOk()
            ->assertSeeInOrder([__('deposits.held'), '1 400,00'])
            ->assertSee('href="/lease-contracts/'.$this->lease->id.'/deposit-closings/create"', false);

        $this->get("/lease-contracts/{$this->lease->id}/deposit-closings/create")->assertOk()
            ->assertSeeInOrder(['№ 601', '1 000,00', '№ 602', '400,00'])
            ->assertSee('name="lines['.$this->first->id.'][offset]"', false)
            ->assertSee('name="lines['.$this->first->id.'][refund]"', false)
            ->assertSee('name="lines['.$this->first->id.'][income]"', false)
            ->assertSeeInOrder([__('dossiers.owes_on_contract'), '500,00']);
    }

    public function test_closing_with_several_methods_at_once(): void
    {
        $this->closeAll()->assertRedirect("/lease-contracts/{$this->lease->id}")->assertSessionHasNoErrors()->assertSessionHas('status');

        $closing = DepositClosing::query()->sole();
        $this->assertSame(['2027-01-10', $this->user->id, 'bank', $this->bank->id],
            [$closing->closed_on->format('Y-m-d'), $closing->created_by, $closing->refund_method, $closing->bank_account_id]);

        // Депозитът е приключен; прихванатото погасява януари.
        $this->assertSame([0.0, 0.0, 0.0], [$this->held(), $this->held($this->first), $this->held($this->second)]);
        $this->assertSame(0.0, $this->owes());
        $kinds = fn (LedgerKind $kind, string $account) => (float) LedgerEntry::query()->where('kind', $kind->value)->where('account', $account)->sum('amount');
        $this->assertSame([-500.0, -500.0], [$kinds(LedgerKind::DepositOffset, '491'), $kinds(LedgerKind::DepositOffset, '418')]);
        $this->assertSame(-700.0, $kinds(LedgerKind::DepositRefund, '491'));
        $this->assertSame(-200.0, $kinds(LedgerKind::DepositIncome, '491'));
        $this->assertSame(0.0, $kinds(LedgerKind::DepositRefund, '418') + $kinds(LedgerKind::DepositIncome, '418'));
        $this->assertSame('2027-01-01', LedgerEntry::query()->where('kind', LedgerKind::DepositOffset->value)->first()->month->format('Y-m-d'));

        $january = Statement::query()->where('lease_contract_id', $this->lease->id)->whereDate('month', '2027-01-01')->sole();
        $this->get("/statements/{$january->id}")->assertSee(__('payments.status.paid'));

        // Картата: държан 0; на наемателя начисленият и внесеният депозит остават 1 400.
        $this->get("/lease-contracts/{$this->lease->id}")->assertSeeInOrder([__('deposits.held'), '0,00'])
            ->assertSee(__('deposits.methods.offset'))->assertSee('Край на договора (измислено)');
        $html = $this->get("/tenants/{$this->lease->tenant_id}")->getContent();
        $this->assertMatchesRegularExpression('#<td class="num">\s*1 400,00\s*</td>\s*<td class="num paid">\s*1 400,00\s*</td>#', substr($html, strpos($html, 'id="deposits"')));
    }

    /** Част от картата на договора: „tenant-ledger“ (418) или „deposit-ledger“ (491). */
    private function cardSection(string $id): string
    {
        $html = $this->get("/lease-contracts/{$this->lease->id}")->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<section id="'.$id.'">(.*?)</section>#s', $html, $match), $id);

        return $match[1];
    }

    public function test_lease_card_deposit_table_shows_every_movement(): void
    {
        $deposit = $this->cardSection('deposit-ledger');
        $this->assertStringContainsString(__('deposits.movements.issue'), $deposit);
        $this->assertMatchesRegularExpression('#'.preg_quote(__('deposits.held')).':\s*<strong>1 400,00 €#u', $deposit);
        $this->assertStringContainsString('href="/lease-contracts/'.$this->lease->id.'/deposit-closings/create"', $deposit);

        $this->closeAll();
        $deposit = $this->cardSection('deposit-ledger');
        foreach (['offset', 'refund', 'income'] as $method) {
            $this->assertStringContainsString(__('deposits.movements.deposit_'.$method), $deposit);
        }
        foreach (['-500,00', '-300,00', '-200,00', '-400,00'] as $amount) {
            $this->assertMatchesRegularExpression('#<td class="num">\s*'.$amount.'\s*</td>#', $deposit);
        }
        $this->assertMatchesRegularExpression('#'.preg_quote(__('deposits.held')).':\s*<strong>0,00 €#u', $deposit);
        $this->assertStringContainsString('action="/deposit-closings/', $deposit); // приключванията са в същата част

        // 418: прихванатото е в сметката на наемателя; връщането и приходът не са.
        $tenant = $this->cardSection('tenant-ledger');
        $this->assertStringContainsString(__('charges.issue.kinds.deposit_offset'), $tenant);
        $this->assertStringNotContainsString(__('charges.issue.kinds.deposit_refund'), $tenant);
        $this->assertStringNotContainsString('-300,00', $tenant);

        // Сторното: редове „Сторно: …“ и държан пак 1 400.
        $this->post('/deposit-closings/'.DepositClosing::query()->sole()->id.'/reverse', ['reason' => 'Грешна сума (измислено)']);
        $deposit = $this->cardSection('deposit-ledger');
        $this->assertStringContainsString(__('deposits.movements.reversal', ['movement' => __('deposits.movements.deposit_refund')]), $deposit);
        $this->assertMatchesRegularExpression('#'.preg_quote(__('deposits.held')).':\s*<strong>1 400,00 €#u', $deposit);
    }

    public function test_closing_is_not_an_accrual_for_the_month(): void
    {
        $this->closeAll();

        $this->assertSame([], app(Reconciliation::class)->differences('2027-01'));
    }

    public function test_validation_and_nothing_is_saved(): void
    {
        // Повече от държания депозит за имота.
        $this->close([$this->second->id => ['refund' => '400,01']])->assertSessionHasErrors('lines.'.$this->second->id);
        // Прихващане повече от дълга по договора (500).
        $this->close([$this->first->id => ['offset' => '600']])->assertSessionHasErrors('offset');
        // Нищо не е въведено.
        $this->close([$this->first->id => ['offset' => '', 'refund' => '0', 'income' => '']])->assertSessionHasErrors('lines');
        // Сумата не е число.
        $this->close([$this->first->id => ['income' => 'сто']])->assertSessionHasErrors('lines.'.$this->first->id);
        // Връщане по банка без сметка; без начин.
        $this->close([$this->first->id => ['refund' => '100']], ['bank_account_id' => ''])->assertSessionHasErrors('bank_account_id');
        $this->close([$this->first->id => ['refund' => '100']], ['refund_method' => ''])->assertSessionHasErrors('refund_method');
        // Имот извън договора; дата.
        $other = $this->record(Property::class, ['number' => 699]);
        $this->close([$other->id => ['income' => '10']])->assertSessionHasErrors('lines.'.$other->id);
        $this->close([$this->first->id => ['income' => '10']], ['closed_on' => '31.02.2027'])->assertSessionHasErrors('closed_on');

        $this->assertSame(0, DepositClosing::query()->count());
        $this->assertSame(1400.0, $this->held());
    }

    public function test_cash_refund_needs_no_bank_account_and_only_offset_needs_no_method(): void
    {
        $this->close([$this->first->id => ['refund' => '100']], ['refund_method' => 'cash', 'bank_account_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull(DepositClosing::query()->sole()->bank_account_id);

        $this->close([$this->first->id => ['offset' => '100']], ['refund_method' => '', 'bank_account_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull(DepositClosing::query()->latest('id')->first()->refund_method);
        $this->assertSame(1200.0, $this->held());
    }

    public function test_export_has_offset_and_income_but_not_refund(): void
    {
        $this->settings();
        $this->closeAll();
        $n = $this->lease->number;

        $batch = app(ExportBatches::class)->create(CarbonImmutable::parse('2027-01-01'));
        $result = app(MicroinvestRows::class)->build($batch);
        $rows = array_values(array_filter($result['rows'], fn ($row) => str_starts_with($row['debit'], '491')));

        $this->assertSame([
            [50000, "491/{$n}/601", "418/{$n}/601", __('export.file.basis.deposit')],
            [20000, "491/{$n}/601", '709/5', __('export.file.basis.deposit')],
        ], array_map(fn ($row) => [$row['cents'], $row['debit'], $row['credit'], $row['basis']], $rows));
        $this->assertTrue($result['ok']);

        $refunds = LedgerEntry::query()->where('kind', LedgerKind::DepositRefund->value)->pluck('id')->all();
        $this->assertEmpty(array_intersect($refunds, $batch->entries()->pluck('ledger_entry_id')->all()));
        $this->assertSame(0, app(ExportBatches::class)->pending(CarbonImmutable::parse('2027-01-01'))->count());
    }

    public function test_income_account_is_a_setting_needed_only_when_used(): void
    {
        $this->settings(['deposit_income' => '']);
        $this->assertNotContains('deposit_income', app(ExportSettings::class)->missing());

        $this->close([$this->first->id => ['income' => '50']]);
        $this->assertContains('deposit_income', app(ExportSettings::class)->missing());

        $this->get('/company/export')->assertSee('name="deposit_income"', false);
    }

    public function test_reversal_with_a_reason_only_once(): void
    {
        $this->closeAll();
        $closing = DepositClosing::query()->sole();

        $this->post("/deposit-closings/{$closing->id}/reverse", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/deposit-closings/{$closing->id}/reverse", ['reason' => 'Грешна сума (измислено)'])
            ->assertRedirect("/lease-contracts/{$this->lease->id}")->assertSessionHasNoErrors();

        $this->assertSame([1400.0, 500.0], [$this->held(), $this->owes()]);
        $reversal = DepositClosing::query()->whereNotNull('reversal_of_id')->sole();
        $this->assertSame([$closing->id, 'Грешна сума (измислено)'], [$reversal->reversal_of_id, $reversal->reason]);

        $this->post("/deposit-closings/{$closing->id}/reverse", ['reason' => 'Пак (измислено)'])->assertSessionHasErrors('reason');
        $this->post("/deposit-closings/{$reversal->id}/reverse", ['reason' => 'Пак (измислено)'])->assertSessionHasErrors('reason');
        $this->assertSame(2, DepositClosing::query()->count());

        // Сторното влиза в експорта с обратен знак; сверката е наред.
        $this->settings();
        $this->assertTrue(app(MicroinvestRows::class)->build(app(ExportBatches::class)->create(CarbonImmutable::parse('2027-01-01')))['ok']);
        $this->get("/lease-contracts/{$this->lease->id}")->assertSee(__('deposits.reversed'));
    }

    public function test_closings_are_append_only(): void
    {
        $this->closeAll();
        $closing = DepositClosing::query()->sole();

        $this->expectException(\LogicException::class);
        $closing->forceFill(['note' => 'Х'])->save();
    }

    public function test_only_the_charges_manager(): void
    {
        $this->closeAll();
        $closing = DepositClosing::query()->sole();

        foreach ([$this->readOnlyStaff(), $this->assignedOnlyStaff()] as $staff) {
            auth()->logout();
            $this->actingAs($staff);
            if ($staff->property_scope?->value === 'assigned') {
                (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $staff->id, 'property_id' => $this->first->id])->save();
            }
            $this->get("/lease-contracts/{$this->lease->id}/deposit-closings/create")->assertForbidden();
            $this->close([$this->first->id => ['income' => '10']])->assertForbidden();
            $this->post("/deposit-closings/{$closing->id}/reverse", ['reason' => 'Х'])->assertForbidden();
            $this->get("/lease-contracts/{$this->lease->id}")->assertOk()->assertDontSee('/deposit-closings/create', false);
        }
        $this->assertSame(1, DepositClosing::query()->withoutGlobalScopes()->count());
    }

    public function test_company_b_cannot_close_or_reverse(): void
    {
        $this->closeAll();
        $closing = DepositClosing::query()->sole();
        auth()->logout();
        $this->actingAs($this->staff(['is_company_admin' => true], $this->otherCompany));

        $this->get("/lease-contracts/{$this->lease->id}/deposit-closings/create")->assertNotFound();
        $this->close([$this->first->id => ['income' => '10']])->assertNotFound();
        $this->post("/deposit-closings/{$closing->id}/reverse", ['reason' => 'Х'])->assertNotFound();
        $this->assertSame(1, DepositClosing::query()->withoutGlobalScopes()->count());
    }
}
