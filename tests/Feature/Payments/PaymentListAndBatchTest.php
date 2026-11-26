<?php

namespace Tests\Feature\Payments;

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
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Services\Payments\PaymentService;
use Carbon\CarbonImmutable;
use Tests\Feature\Screens\ScreenTestCase;

/**
 * „Плащания“ в менюто „Начисления“ (26.11.2026): списък на всички плащания с
 * филтри по период, начин и банкова сметка и сбор (справката „Плащания за
 * период“); въвеждане на много плащания наведнъж като таблица — общи дата,
 * начин и сметка, които всеки ред може да смени; договор по номер или по име
 * на наемателя. Ако един ред е грешен, не се записва нищо, а грешките са до
 * всеки ред. Всеки ред е отделно плащане по същите правила. Само
 * управляващият въвежда. „Днес“ е 10.01.2027. Само измислени данни.
 */
class PaymentListAndBatchTest extends ScreenTestCase
{
    private User $user;

    private LeaseContract $boris;

    private LeaseContract $anna;

    private Property $annaFlat;

    private BankAccount $bank;

    private BankAccount $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00'));
        $this->user = $this->staff(['is_company_admin' => true]);
        $first = $this->record(Property::class, ['number' => 301]);
        $this->annaFlat = $this->record(Property::class, ['number' => 302]);
        $borisTenant = $this->record(Tenant::class, ['name' => 'Борис Измислен']);
        $annaTenant = $this->record(Tenant::class, ['name' => 'Анна Измислена']);
        $this->actingAs($this->user);

        app(OwnerContractService::class)->create(['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01',
            'lines' => [['property_id' => $first->id], ['property_id' => $this->annaFlat->id]]]);
        $leases = app(LeaseContractService::class);
        $this->boris = $leases->create(['tenant_id' => $borisTenant->id, 'signed_on' => '2026-12-01', 'lines' => [['property_id' => $first->id, 'rent' => '500']]]);
        $this->anna = $leases->create(['tenant_id' => $annaTenant->id, 'signed_on' => '2026-12-01', 'lines' => [['property_id' => $this->annaFlat->id, 'rent' => '400']]]);
        app(Issuer::class)->issueNow($this->boris, '2027-01');
        app(Issuer::class)->issueNow($this->anna, '2027-01');

        $this->bank = BankAccount::query()->create(['bank_name' => 'Измислена банка', 'iban' => 'BG80BNBG96611020345678']);
        $this->bank->forceFill(['for_statements' => true])->save();
        $this->other = BankAccount::query()->create(['bank_name' => 'Друга измислена', 'iban' => 'BG18RZBB91550123456789']);
    }

    private function batch(array $rows, array $common = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/payments/batch', $common + ['paid_on' => '08.01.2027', 'method' => 'bank', 'bank_account_id' => $this->bank->id, 'rows' => $rows]);
    }

    // Въвеждане на много плащания наведнъж

    public function test_batch_saves_every_row_as_its_own_payment(): void
    {
        $this->get('/payments/batch')->assertOk()->assertSee('name="rows[0][contract]"', false)->assertSee(__('payments.batch.add_row'))
            ->assertSee('value="'.$this->boris->number.' — Борис Измислен"', false);

        $this->batch([
            ['contract' => (string) $this->boris->number, 'amount' => '300', 'reference' => 'Превод 1'],
            ['contract' => $this->anna->number.' — Анна Измислена', 'amount' => '400'],
            ['contract' => 'Борис Измислен', 'amount' => '200', 'method' => 'cash', 'paid_on' => '09.01.2027'],
            ['contract' => '', 'amount' => '', 'reference' => ''], // празен ред — пропуска се
        ])->assertRedirect('/payments')->assertSessionHas('status', __('payments.batch.saved', ['count' => 3]));

        $payments = Payment::query()->orderBy('id')->get();
        $this->assertCount(3, $payments);
        $this->assertSame([$this->boris->id, $this->anna->id, $this->boris->id], $payments->pluck('lease_contract_id')->all());
        $this->assertSame(['bank', 'bank', 'cash'], $payments->pluck('method')->all());
        $this->assertSame([$this->bank->id, $this->bank->id, null], $payments->pluck('bank_account_id')->all());
        $this->assertSame(['2027-01-08', '2027-01-08', '2027-01-09'], $payments->map(fn ($p) => $p->paid_on->format('Y-m-d'))->all());
        $this->assertSame('Превод 1', $payments[0]->reference);
        // В книгата — всеки по своя договор.
        $this->assertSame(-500.0, (float) LedgerEntry::query()->where('kind', LedgerKind::Payment->value)->where('lease_contract_id', $this->boris->id)->sum('amount'));
        $this->assertSame(-400.0, (float) LedgerEntry::query()->where('kind', LedgerKind::Payment->value)->where('lease_contract_id', $this->anna->id)->sum('amount'));
    }

    public function test_a_row_may_use_its_own_bank_account(): void
    {
        $this->batch([['contract' => (string) $this->boris->number, 'amount' => '100', 'bank_account_id' => $this->other->id]])->assertSessionHasNoErrors();

        $this->assertSame($this->other->id, Payment::query()->sole()->bank_account_id);
    }

    public function test_one_wrong_row_saves_nothing_and_all_errors_are_shown_at_their_rows(): void
    {
        $this->batch([
            ['contract' => (string) $this->boris->number, 'amount' => '300'],
            ['contract' => '9999', 'amount' => '100'],
            ['contract' => (string) $this->anna->number, 'amount' => '-5'],
            ['contract' => 'Никой Измислен', 'amount' => '50', 'paid_on' => '31.02.2027'],
        ])->assertSessionHasErrors(['rows.1.contract', 'rows.2.amount', 'rows.3.contract', 'rows.3.paid_on'])
            ->assertSessionDoesntHaveErrors(['rows.0.contract', 'rows.0.amount']);

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, LedgerEntry::query()->where('kind', LedgerKind::Payment->value)->count());

        // Въведеното остава във формата.
        $this->get('/payments/batch')->assertSee('value="9999"', false);
    }

    public function test_at_least_one_row_is_needed(): void
    {
        $this->batch([['contract' => '', 'amount' => '']])->assertSessionHasErrors('rows');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_ambiguous_tenant_name_is_an_error(): void
    {
        $second = $this->record(Property::class, ['number' => 303]);
        app(OwnerContractService::class)->create(['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01', 'lines' => [['property_id' => $second->id]]]);
        app(LeaseContractService::class)->create(['tenant_id' => $this->boris->tenant_id, 'signed_on' => '2026-12-01', 'lines' => [['property_id' => $second->id, 'rent' => '100']]]);

        $this->batch([['contract' => 'Борис Измислен', 'amount' => '100']])->assertSessionHasErrors(['rows.0.contract' => __('payments.batch.errors.ambiguous')]);
    }

    // Списъкът „Плащания“ (и справката за период)

    private function seedPayments(): void
    {
        $service = app(PaymentService::class);
        $service->record($this->boris, ['amount' => '300', 'paid_on' => '05.01.2027', 'method' => 'bank', 'bank_account_id' => $this->bank->id, 'reference' => 'Превод Б']);
        $service->record($this->anna, ['amount' => '400', 'paid_on' => '06.01.2027', 'method' => 'cash', 'reference' => 'В брой А']);
        $service->record($this->boris, ['amount' => '50', 'paid_on' => '20.12.2026', 'method' => 'bank', 'bank_account_id' => $this->other->id]);
        $wrong = $service->record($this->anna, ['amount' => '99', 'paid_on' => '07.01.2027', 'method' => 'bank', 'bank_account_id' => $this->bank->id]);
        $service->reverse($wrong, 'Грешна сума (измислено)');
    }

    /** Сборът под списъка. */
    private function total(string $url): string
    {
        $html = $this->get($url)->assertOk()->getContent();
        preg_match('#<tfoot>.*?<td class="num">([^<]+)</td>#s', $html, $m);

        return trim($m[1] ?? '');
    }

    public function test_list_shows_all_payments_with_reversals_and_a_total(): void
    {
        $this->seedPayments();

        $this->get('/payments?from=01.01.2027&to=31.01.2027')->assertOk()
            ->assertSee(__('registry.sections.payments'))
            ->assertSeeInOrder(['07.01.2027', '-99,00', __('payments.list.reversal')])
            ->assertSee('Превод Б')->assertSee('Борис Измислен')->assertSee('Анна Измислена')
            ->assertSee('href="/lease-contracts/'.$this->boris->id.'"', false)
            ->assertSee('BG80 BNBG 9661 1020 3456 78')
            ->assertDontSee('20.12.2026');
        // 300 + 400 + 99 − 99
        $this->assertSame('700,00', $this->total('/payments?from=01.01.2027&to=31.01.2027'));
    }

    public function test_filters_by_period_method_and_bank_account(): void
    {
        $this->seedPayments();

        $this->assertSame('400,00', $this->total('/payments?from=01.01.2027&to=31.01.2027&method=cash'));
        $this->assertSame('300,00', $this->total('/payments?from=01.01.2027&to=31.01.2027&method=bank&bank_account_id='.$this->bank->id));
        $this->assertSame('50,00', $this->total('/payments?from=01.12.2026&to=31.12.2026'));
        $this->assertSame('50,00', $this->total('/payments?from=01.12.2026&to=31.01.2027&bank_account_id='.$this->other->id));
        // По подразбиране: текущият месец.
        $this->get('/payments')->assertSee('value="01.01.2027"', false)->assertSee('value="31.01.2027"', false);
    }

    public function test_assigned_staff_sees_only_payments_of_their_contracts(): void
    {
        $this->seedPayments();
        auth()->logout();
        $assigned = $this->assignedOnlyStaff();
        (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $assigned->id, 'property_id' => $this->annaFlat->id])->save();
        $this->actingAs($assigned);

        $this->get('/payments?from=01.01.2027&to=31.01.2027')->assertOk()->assertSee('Анна Измислена')->assertDontSee('Борис Измислен')
            ->assertDontSee('href="/payments/batch"', false);
        $this->assertSame('400,00', $this->total('/payments?from=01.01.2027&to=31.01.2027'));
    }

    public function test_only_the_manager_enters_and_the_menu_has_the_list(): void
    {
        $this->get('/charges?month=2027-01')->assertSee('href="/payments"', false);
        $this->get('/payments')->assertSee('href="/payments/batch"', false);

        auth()->logout();
        $this->actingAs($this->readOnlyStaff());
        $this->get('/payments')->assertOk()->assertDontSee('href="/payments/batch"', false);
        $this->get('/payments/batch')->assertForbidden();
        $this->batch([['contract' => (string) $this->boris->number, 'amount' => '100']])->assertForbidden();
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_company_b_sees_and_pays_only_its_own(): void
    {
        $this->seedPayments();
        auth()->logout();
        $this->actingAs($this->staff([], $this->otherCompany));

        $this->get('/payments?from=01.12.2026&to=31.01.2027')->assertOk()->assertDontSee('Борис Измислен');
        $this->assertSame('0,00', $this->total('/payments?from=01.12.2026&to=31.01.2027'));
        $this->batch([['contract' => (string) $this->boris->number, 'amount' => '100']], ['method' => 'cash'])->assertSessionHasErrors('rows.0.contract');
    }
}
