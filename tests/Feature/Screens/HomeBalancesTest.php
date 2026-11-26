<?php

namespace Tests\Feature\Screens;

use App\Models\Payment;
use App\Models\User;
use App\Models\UserProperty;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PayoutService;
use Carbon\CarbonImmutable;
use Tests\Support\DossierScenario;

/**
 * Салдата на Начало (PROJECT.md, „Плащания и салда“, 26.11.2026):
 * „Получени плащания“ за текущия месец в лентата (сторната се приспадат);
 * „Дължат наемателите общо“ и „Неизплатено към собствениците“ в „За
 * проследяване“ — по договор, като надплатеното от един не намалява дълга на
 * друг. Нулите не се показват. Само видимото за служителя; само своята фирма.
 *
 * Пример: декември е издаден — Борис дължи 500 (418), към Петя 450 (408).
 * „Днес“ е 24.11.2026. Само измислени данни.
 */
class HomeBalancesTest extends ScreenTestCase
{
    use DossierScenario;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00'));
        $this->user = $this->staff();
        $this->buildDossierScenario($this->user);
    }

    private function section(string $id): string
    {
        $html = $this->get('/dashboard')->assertOk()->getContent();
        $start = strpos($html, 'id="'.$id.'"');
        $this->assertNotFalse($start, "Няма секция {$id}.");

        return substr($html, $start, strpos($html, '</section>', $start) - $start);
    }

    /** Сумата в плочката „Получени плащания“. */
    private function received(): string
    {
        preg_match('#'.preg_quote(__('home.received', ['month' => 'ноември']), '#').'</span>\s*<span class="big">([^<]+)</span>#', $this->section('home-month'), $m);

        return $m[1] ?? '';
    }

    private function row(string $label, string $value, string $href): string
    {
        return '#<a class="home-row" href="'.preg_quote($href, '#').'"><span>'.preg_quote($label, '#').'</span><span[^>]*>'.preg_quote($value, '#').'</span></a>#';
    }

    public function test_debts_and_unpaid_to_owners_are_followed(): void
    {
        $follow = $this->section('home-follow');

        $this->assertMatchesRegularExpression($this->row(__('home.follow.tenants_owe'), '500,00 €', '/tenants'), $follow);
        $this->assertMatchesRegularExpression($this->row(__('home.follow.owners_unpaid'), '450,00 €', '/reports/payouts'), $follow);
    }

    public function test_payments_received_this_month_with_reversals(): void
    {
        $payments = app(PaymentService::class);
        $payments->record($this->lease, ['amount' => '200', 'paid_on' => '20.11.2026', 'method' => 'cash']);
        $wrong = $payments->record($this->lease, ['amount' => '50', 'paid_on' => '21.11.2026', 'method' => 'cash']);
        $payments->reverse($wrong, 'Грешка (измислено)');
        $payments->record($this->lease, ['amount' => '30', 'paid_on' => '31.10.2026', 'method' => 'cash']); // друг месец

        $band = $this->section('home-month');
        $this->assertStringContainsString(__('home.received', ['month' => 'ноември']), $band);
        $this->assertSame('200,00 €', $this->received());
        $this->assertStringContainsString('href="/payments"', $band);

        // Дължи: 500 − 200 − 30 = 270.
        $this->assertMatchesRegularExpression($this->row(__('home.follow.tenants_owe'), '270,00 €', '/tenants'), $this->section('home-follow'));
    }

    public function test_overpayment_of_one_tenant_does_not_lower_the_debt_of_another(): void
    {
        // Анна (стар договор, без начисления) плаща 100 → надплатено; Борис пак дължи 500.
        app(PaymentService::class)->record($this->oldLease, ['amount' => '100', 'paid_on' => '20.11.2026', 'method' => 'cash']);

        $this->assertMatchesRegularExpression($this->row(__('home.follow.tenants_owe'), '500,00 €', '/tenants'), $this->section('home-follow'));
    }

    public function test_zero_balances_are_not_shown(): void
    {
        app(PaymentService::class)->record($this->lease, ['amount' => '500', 'paid_on' => '20.11.2026', 'method' => 'cash']);
        app(PayoutService::class)->record($this->management, ['amount' => '450', 'paid_on' => '22.11.2026', 'method' => 'cash']);

        $follow = $this->section('home-follow');
        $this->assertStringNotContainsString(__('home.follow.tenants_owe'), $follow);
        $this->assertStringNotContainsString(__('home.follow.owners_unpaid'), $follow);
        $this->assertSame('500,00 €', $this->received());
    }

    public function test_no_payments_shows_zero_received(): void
    {
        $this->assertSame('0,00 €', $this->received());
    }

    public function test_assigned_staff_sees_only_their_properties(): void
    {
        app(PaymentService::class)->record($this->lease, ['amount' => '200', 'paid_on' => '20.11.2026', 'method' => 'cash']);
        auth()->logout();
        $assigned = $this->assignedOnlyStaff();
        (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $assigned->id, 'property_id' => $this->empty->id])->save();
        $this->actingAs($assigned);

        $follow = $this->section('home-follow');
        $this->assertStringNotContainsString(__('home.follow.tenants_owe'), $follow);
        $this->assertStringNotContainsString(__('home.follow.owners_unpaid'), $follow);
        $this->assertSame('0,00 €', $this->received());
    }

    public function test_company_b_sees_nothing_of_company_a(): void
    {
        app(PaymentService::class)->record($this->lease, ['amount' => '200', 'paid_on' => '20.11.2026', 'method' => 'cash']);
        auth()->logout();
        $this->actingAs($this->staff([], $this->otherCompany));

        $this->assertStringNotContainsString(__('home.follow.tenants_owe'), $this->section('home-follow'));
        $this->assertSame('0,00 €', $this->received());
        $this->assertSame(0, Payment::query()->count());
    }
}
