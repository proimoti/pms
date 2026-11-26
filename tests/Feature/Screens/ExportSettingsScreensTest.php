<?php

namespace Tests\Feature\Screens;

use App\Models\AuditLog;
use App\Models\ExportRule;
use App\Models\Property;
use App\Models\User;
use App\Services\Export\ExportSettings;
use App\Services\Utilities\UtilityAccountService;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * „Настройки за експорта“ (PROJECT.md, „Експорт към Микроинвест“, 26.11.2026):
 * всички сметки ги въвежда администраторът на фирмата; в кода няма твърдо
 * записани номера и няма начални стойности. Сметките на разчетите (418, 491,
 * 408, 407, фонд) са само синтетични — аналитиките се добавят при експорта;
 * сметките за приход, разход и банка са с аналитиката си. Видът услуга има код
 * (средната аналитика на 407/вид/имот). Всяка промяна е в журнала.
 * Номерата в теста са измислени.
 */
class ExportSettingsScreensTest extends ScreenTestCase
{
    private User $admin;

    private const FULL = [
        'tenant' => '418', 'deposit' => '491', 'owner' => '408', 'utility' => '407', 'fund' => '406',
        'revenue_representative_percent' => '703/1', 'revenue_representative_fixed' => '703/2',
        'revenue_sublease_individual' => '703/3', 'revenue_sublease_legal' => '703/4', 'revenue_own' => '417/1',
        'revenue_penalty' => '703/9', 'revenue_other' => '709', 'manager_cost' => '609/2',
        'include_paid_bills' => '1', 'bank_epay' => '503/2', 'bank_easypay' => '503/3',
        'kind_electricity' => '1', 'kind_water' => '2', 'kind_heating' => '3', 'kind_gas' => '', 'kind_internet' => '', 'kind_common' => '', 'kind_other' => '',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->staff(['is_company_admin' => true]);
        $this->actingAs($this->admin);
    }

    private function settings(): ExportSettings
    {
        return app(ExportSettings::class);
    }

    public function test_there_are_no_default_values(): void
    {
        $this->assertSame(0, ExportRule::query()->count());
        $this->assertNull($this->settings()->get('tenant'));
        $this->assertContains('tenant', $this->settings()->missing());
        $this->assertContains('revenue_own', $this->settings()->missing());

        $this->get('/company/export')->assertOk()
            ->assertSee(__('export.settings.title'))
            ->assertSee(__('export.settings.missing'))
            ->assertSee(__('export.settings.keys.revenue_own'))
            ->assertSee(__('contracts.types.sublease_legal'));
        $this->get('/company')->assertSee('href="/company/export"', false);
    }

    public function test_admin_saves_all_accounts_and_changes_are_audited(): void
    {
        $this->put('/company/export', self::FULL)->assertRedirect('/company/export')->assertSessionHasNoErrors();

        $this->assertSame('418', $this->settings()->get('tenant'));
        $this->assertSame('417/1', $this->settings()->get('revenue_own'));
        $this->assertSame('609/2', $this->settings()->get('manager_cost'));
        $this->assertTrue($this->settings()->includePaidBills());
        $this->assertSame('1', $this->settings()->kindCode('electricity'));
        $this->assertNull($this->settings()->kindCode('gas'));
        $this->assertSame([], $this->settings()->missing());
        $this->get('/company/export')->assertDontSee(__('export.settings.missing'))->assertSee('value="417/1"', false);

        $this->put('/company/export', ['revenue_own' => '417/5'] + self::FULL)->assertSessionHasNoErrors();
        $entry = AuditLog::query()->where('auditable_type', 'export_rules')->where('event', 'updated')->latest('id')->first();
        $this->assertSame(['old' => '417/1', 'new' => '417/5'], $entry->changes['value']);
        $this->assertSame($this->admin->id, $entry->user_id);
    }

    public function test_account_formats_are_checked(): void
    {
        // Синтетична сметка: точно 3 цифри (аналитиките се добавят при експорта).
        $this->put('/company/export', ['tenant' => '418/1', 'owner' => '40'] + self::FULL)->assertSessionHasErrors(['tenant', 'owner']);
        // Сметка с аналитика: 3 цифри и по желание /аналитика.
        $this->put('/company/export', ['revenue_own' => 'приход', 'bank_epay' => '503//2'] + self::FULL)->assertSessionHasErrors(['revenue_own', 'bank_epay']);
        // Код на вид услуга: букви и цифри, без „/“ и „-“.
        $this->put('/company/export', ['kind_water' => '2/1'] + self::FULL)->assertSessionHasErrors('kind_water');
        $this->assertSame(0, ExportRule::query()->count());
    }

    public function test_bank_accounts_are_required_only_when_paid_bills_are_exported(): void
    {
        $values = ['include_paid_bills' => '0', 'bank_epay' => '', 'bank_easypay' => ''] + self::FULL;
        $this->put('/company/export', $values)->assertSessionHasNoErrors();

        $this->assertFalse($this->settings()->includePaidBills());
        $this->assertNotContains('bank_epay', $this->settings()->missing());

        $this->put('/company/export', ['include_paid_bills' => '1'] + $values);
        $this->assertContains('bank_epay', $this->settings()->missing());
        $this->assertContains('bank_easypay', $this->settings()->missing());
    }

    public function test_kind_codes_are_required_only_for_kinds_in_use(): void
    {
        $this->put('/company/export', ['kind_electricity' => '', 'kind_water' => ''] + self::FULL);
        $this->assertNotContains('kind_electricity', $this->settings()->missing());

        $property = $this->record(Property::class);
        app(UtilityAccountService::class)->create(['kind' => 'electricity', 'billing' => 'actual', 'number' => '0012345',
            'provider_id' => \App\Models\Provider::query()->create(['name' => 'Измислено Електро'])->id,
            'valid_from' => '2026-01-01', 'properties' => [['property_id' => $property->id]]]);

        $this->assertContains('kind_electricity', $this->settings()->missing());
        $this->assertNotContains('kind_water', $this->settings()->missing());
    }

    public function test_only_the_company_admin(): void
    {
        $this->put('/company/export', self::FULL);
        auth()->logout();
        $this->actingAs($this->staff());

        $this->get('/company/export')->assertForbidden();
        $this->put('/company/export', self::FULL)->assertForbidden();

        $this->expectException(AuthorizationException::class);
        ExportRule::query()->first()->update(['value' => '419']);
    }

    public function test_company_b_has_its_own_settings(): void
    {
        $this->put('/company/export', self::FULL);
        auth()->logout();
        $foreign = $this->staff(['is_company_admin' => true], $this->otherCompany);
        $this->assertSame($this->otherCompany->id, $foreign->company_id);
        $this->actingAs($foreign);

        $this->assertNull($this->settings()->get('tenant'));
        $this->get('/company/export')->assertDontSee('value="417/1"', false);
        $this->put('/company/export', ['revenue_own' => '417/9'] + self::FULL);

        auth()->logout();
        $this->actingAs($this->admin);
        $this->assertSame('417/1', $this->settings()->get('revenue_own'));
    }
}
