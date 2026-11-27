<?php

namespace Tests\Feature\Staff;

use App\Enums\AccessLevel;
use App\Enums\PropertyScope;
use App\Enums\Role;
use App\Mail\InvitationMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Property;
use App\Models\User;
use App\Models\UserProperty;
use App\Support\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Екран „Служители“ (план „Портал и служители“, 27.11.2026). Само за
 * администратора на фирмата. Нов служител с шаблон и покана по имейл, права,
 * прикачени имоти, спиране и връщане на достъпа, нулиране на втория фактор с
 * код от телефона на администратора. Администраторът не пипа своя профил
 * оттук, затова фирмата винаги има поне един администратор. Всичко е в
 * журнала. Служители на друга фирма — 404. Само измислени данни.
 */
class StaffScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $adminSecret;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->company = Company::factory()->create(['name' => 'Тест Алфа ООД']);
        $this->onAddressOf($this->company);
        $this->adminSecret = app(Google2FA::class)->generateSecretKey();
        $this->admin = User::factory()->for($this->company)->withTwoFactor($this->adminSecret)
            ->create(['name' => 'Админ Измислен', 'is_company_admin' => true]);
        $this->staff = User::factory()->for($this->company)->withTwoFactor(app(Google2FA::class)->generateSecretKey())
            ->create(['name' => 'Петя Измислена', 'email' => 'petya@example.test',
                'access_level' => AccessLevel::Read, 'property_scope' => PropertyScope::Assigned]);
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin);
    }

    private function code(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    private function events(User $user): array
    {
        return AuditLog::query()->withoutGlobalScopes()->where('auditable_type', 'users')
            ->where('auditable_id', $user->id)->orderBy('id')->pluck('event')->all();
    }

    // --- Достъп до екрана ---

    public function test_only_the_company_admin_opens_the_screen(): void
    {
        $this->asAdmin()->get('/staff')->assertOk();

        $this->actingAs($this->staff)->get('/staff')->assertForbidden();
        $this->actingAs($this->staff)->post('/staff', ['name' => 'Х', 'email' => 'x@example.test', 'template' => 'broker'])->assertForbidden();
        $this->actingAs(User::factory()->for($this->company)->tenant()->create())->get('/staff')->assertForbidden();
    }

    public function test_the_guest_is_sent_to_login(): void
    {
        $this->get('/staff')->assertRedirect('/login');
    }

    public function test_the_company_page_links_to_the_staff_screen(): void
    {
        $this->asAdmin()->get('/company')->assertOk()->assertSee('href="/staff"', false);
    }

    // --- Списък ---

    public function test_the_list_shows_only_the_staff_of_the_company_with_template_and_state(): void
    {
        $other = Company::factory()->create();
        User::factory()->for($other)->create(['name' => 'Чужд Служител']);
        User::factory()->for($this->company)->tenant()->create(['name' => 'Клиент Наемател']);
        User::factory()->for($this->company)->create(['name' => 'Спрян Служител', 'disabled_at' => now()]);
        User::factory()->for($this->company)->create(['name' => 'Поканен Служител', 'invitation_token' => str_repeat('a', 64), 'invitation_expires_at' => now()->addDay()]);
        User::factory()->for($this->company)->create(['name' => 'Счетоводител Измислен', 'access_level' => AccessLevel::Read, 'property_scope' => PropertyScope::All, 'can_view_sensitive' => true]);

        $this->asAdmin()->get('/staff')->assertOk()
            ->assertSee('Админ Измислен')->assertSee('Петя Измислена')->assertSee('petya@example.test')
            ->assertSee(__('staff.templates.broker'))->assertSee(__('staff.templates.accountant'))
            ->assertSee(__('staff.state.stopped'))->assertSee(__('staff.state.invited'))
            ->assertDontSee('Чужд Служител')->assertDontSee('Клиент Наемател');
    }

    // --- Нов служител ---

    public static function templates(): array
    {
        return [
            'Брокер' => ['broker', AccessLevel::Read, PropertyScope::Assigned, false],
            'Мениджър' => ['manager', AccessLevel::Write, PropertyScope::All, false],
            'Оператор' => ['operator', AccessLevel::Write, PropertyScope::Assigned, false],
            'Счетоводител' => ['accountant', AccessLevel::Read, PropertyScope::All, true],
        ];
    }

    /** Преименувано 27.11.2026: „Администратор“ → „Мениджър“, за да не се бърка с „Администратор на фирмата“. */
    public function test_the_templates_are_broker_manager_operator_accountant(): void
    {
        $this->assertSame(['broker', 'manager', 'operator', 'accountant'], array_keys(\App\Support\StaffTemplates::all()));

        $this->asAdmin()->get('/staff/create')->assertOk()
            ->assertSeeInOrder(['Брокер', 'Мениджър', 'Оператор', 'Счетоводител'])
            ->assertDontSee('>Администратор:', false);
    }

    #[DataProvider('templates')]
    public function test_a_new_staff_member_gets_the_rights_of_the_template_and_an_invitation(string $template, AccessLevel $access, PropertyScope $scope, bool $sensitive): void
    {
        $this->asAdmin()->post('/staff', ['name' => 'Нов Служител', 'email' => 'nov@example.test', 'template' => $template])
            ->assertRedirect();

        $new = User::query()->where('email', 'nov@example.test')->sole();
        $this->assertSame(Role::Staff, $new->role);
        $this->assertSame($this->company->id, $new->company_id);
        $this->assertSame($access, $new->access_level);
        $this->assertSame($scope, $new->property_scope);
        $this->assertSame($sensitive, $new->can_view_sensitive);
        $this->assertFalse($new->is_company_admin);
        $this->assertNotNull($new->invitation_token);
        Mail::assertSent(InvitationMail::class, fn (InvitationMail $m) => $m->hasTo('nov@example.test'));
        $this->assertSame(['created', 'invited'], $this->events($new));
    }

    public function test_a_new_staff_member_can_be_a_company_admin(): void
    {
        $this->asAdmin()->post('/staff', ['name' => 'Нов Админ', 'email' => 'nov@example.test', 'template' => 'manager', 'is_company_admin' => '1']);

        $this->assertTrue(User::query()->where('email', 'nov@example.test')->sole()->is_company_admin);
    }

    public function test_the_new_staff_member_cannot_log_in_before_setting_a_password(): void
    {
        $this->asAdmin()->post('/staff', ['name' => 'Нов Служител', 'email' => 'nov@example.test', 'template' => 'broker']);
        auth()->logout();

        foreach (['password', '', 'nov@example.test'] as $guess) {
            $this->post('/login', ['email' => 'nov@example.test', 'password' => $guess]);
        }
        $this->assertGuest();
    }

    public function test_the_email_is_unique_in_the_company_but_not_across_companies(): void
    {
        User::factory()->for(Company::factory())->create(['email' => 'nov@example.test']);

        $this->asAdmin()->post('/staff', ['name' => 'Дубликат', 'email' => 'petya@example.test', 'template' => 'broker'])->assertSessionHasErrors('email');
        $this->asAdmin()->post('/staff', ['name' => 'Нов Служител', 'email' => 'nov@example.test', 'template' => 'broker'])->assertSessionHasNoErrors();

        $this->assertSame(1, User::query()->where('email', 'petya@example.test')->count());
    }

    public function test_an_unknown_template_or_missing_data_is_refused(): void
    {
        $this->asAdmin()->post('/staff', ['name' => 'Нов', 'email' => 'nov@example.test', 'template' => 'boss'])->assertSessionHasErrors('template');
        $this->asAdmin()->post('/staff', ['name' => '', 'email' => 'не-е-имейл', 'template' => 'broker'])->assertSessionHasErrors(['name', 'email']);

        $this->assertSame(0, User::query()->where('email', 'nov@example.test')->count());
    }

    public function test_the_role_and_company_cannot_be_sent_from_the_form(): void
    {
        $other = Company::factory()->create();
        $this->asAdmin()->post('/staff', ['name' => 'Нов', 'email' => 'nov@example.test', 'template' => 'broker',
            'role' => 'app_admin', 'company_id' => $other->id, 'password' => 'някаква-парола-123']);

        $new = User::query()->where('email', 'nov@example.test')->sole();
        $this->assertSame(Role::Staff, $new->role);
        $this->assertSame($this->company->id, $new->company_id);
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('някаква-парола-123', $new->password));
    }

    // --- Права ---

    public function test_the_admin_changes_rights_and_the_change_is_in_the_audit_log(): void
    {
        $this->asAdmin()->put("/staff/{$this->staff->id}", ['name' => 'Петя Измислена', 'access_level' => 'write',
            'property_scope' => 'all', 'can_view_sensitive' => '1'])->assertRedirect("/staff/{$this->staff->id}");

        $fresh = $this->staff->fresh();
        $this->assertSame(AccessLevel::Write, $fresh->access_level);
        $this->assertSame(PropertyScope::All, $fresh->property_scope);
        $this->assertTrue($fresh->can_view_sensitive);

        $row = AuditLog::query()->withoutGlobalScopes()->where('auditable_id', $this->staff->id)->where('event', 'updated')->sole();
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertSame(['old' => 'read', 'new' => 'write'], $row->changes['access_level']);
        $this->assertSame(['old' => 'assigned', 'new' => 'all'], $row->changes['property_scope']);
    }

    public function test_a_template_can_be_applied_to_an_existing_staff_member(): void
    {
        $this->asAdmin()->put("/staff/{$this->staff->id}", ['name' => 'Петя Измислена', 'template' => 'accountant']);

        $fresh = $this->staff->fresh();
        $this->assertSame([AccessLevel::Read, PropertyScope::All, true], [$fresh->access_level, $fresh->property_scope, $fresh->can_view_sensitive]);
    }

    public function test_the_admin_does_not_change_their_own_profile_here(): void
    {
        $this->asAdmin()->put("/staff/{$this->admin->id}", ['name' => 'Админ', 'access_level' => 'read', 'property_scope' => 'assigned'])
            ->assertForbidden();
        $this->asAdmin()->post("/staff/{$this->admin->id}/stop")->assertForbidden();
        $this->asAdmin()->post("/staff/{$this->admin->id}/reset-two-factor", ['code' => $this->code($this->adminSecret)])->assertForbidden();

        $fresh = $this->admin->fresh();
        $this->assertTrue($fresh->is_company_admin);
        $this->assertNull($fresh->disabled_at);
        $this->assertNotNull($fresh->two_factor_secret);
    }

    public function test_the_company_always_keeps_an_admin(): void
    {
        $second = User::factory()->for($this->company)->create(['is_company_admin' => true]);

        // Другият администратор може да бъде свален; себе си — не, затова винаги остава поне един.
        $this->asAdmin()->put("/staff/{$second->id}", ['name' => $second->name, 'access_level' => 'write', 'property_scope' => 'all'])->assertRedirect();
        $this->assertFalse($second->fresh()->is_company_admin);
        $this->assertSame(1, User::query()->where('company_id', $this->company->id)->where('is_company_admin', true)->whereNull('disabled_at')->count());
    }

    // --- Прикачени имоти ---

    public function test_attached_properties_are_saved_and_limit_what_the_staff_member_sees(): void
    {
        [$p1, $p2, $p3] = Property::factory()->count(3)->create(['company_id' => $this->company->id]);

        $this->asAdmin()->put("/staff/{$this->staff->id}/properties", ['property_ids' => [$p1->id, $p3->id]])->assertRedirect();
        $this->assertEqualsCanonicalizing([$p1->id, $p3->id], UserProperty::query()->where('user_id', $this->staff->id)->pluck('property_id')->all());

        $this->asAdmin()->put("/staff/{$this->staff->id}/properties", ['property_ids' => [$p3->id]]);
        $this->assertSame([$p3->id], UserProperty::query()->where('user_id', $this->staff->id)->pluck('property_id')->all());

        $this->actingAs($this->staff->fresh());
        $this->assertSame([$p3->id], Property::query()->pluck('id')->all());
        $this->assertNotContains($p2->id, Property::query()->pluck('id')->all());
    }

    public function test_attaching_and_detaching_are_in_the_audit_log(): void
    {
        $p = Property::factory()->create(['company_id' => $this->company->id]);

        $this->asAdmin()->put("/staff/{$this->staff->id}/properties", ['property_ids' => [$p->id]]);
        $this->asAdmin()->put("/staff/{$this->staff->id}/properties", ['property_ids' => []]);

        $this->assertSame(['created', 'deleted'], AuditLog::query()->withoutGlobalScopes()->where('auditable_type', 'user_properties')
            ->orderBy('id')->pluck('event')->all());
    }

    public function test_a_property_of_another_company_cannot_be_attached(): void
    {
        $foreign = Property::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->asAdmin()->put("/staff/{$this->staff->id}/properties", ['property_ids' => [$foreign->id]])->assertSessionHasErrors('property_ids.0');
        $this->assertSame(0, UserProperty::query()->withoutGlobalScopes()->count());
    }

    public function test_the_admin_sees_all_properties_to_attach_even_with_assigned_scope(): void
    {
        $this->admin->forceFill(['property_scope' => PropertyScope::Assigned])->save();
        $p = Property::factory()->create(['company_id' => $this->company->id, 'city' => 'Измисленово']);

        $this->actingAs($this->admin->fresh())->get("/staff/{$this->staff->id}")->assertOk()->assertSee('Измисленово');
    }

    // --- Спиране, връщане, покана ---

    public function test_the_admin_stops_and_restores_access(): void
    {
        $this->asAdmin()->post("/staff/{$this->staff->id}/stop")->assertRedirect("/staff/{$this->staff->id}");
        $this->assertTrue($this->staff->fresh()->isStopped());

        $this->asAdmin()->post("/staff/{$this->staff->id}/restore")->assertRedirect("/staff/{$this->staff->id}");
        $this->assertFalse($this->staff->fresh()->isStopped());
        $this->assertSame(['access_stopped', 'access_restored'], $this->events($this->staff));
    }

    public function test_the_admin_sends_the_invitation_again_until_a_password_is_set(): void
    {
        $this->asAdmin()->post('/staff', ['name' => 'Нов Служител', 'email' => 'nov@example.test', 'template' => 'broker']);
        $new = User::query()->where('email', 'nov@example.test')->sole();
        Mail::fake();

        $this->asAdmin()->post("/staff/{$new->id}/invite")->assertRedirect("/staff/{$new->id}")->assertSessionHas('status');
        Mail::assertSent(InvitationMail::class);

        Invitation::accept($new->fresh(), 'нова-парола-12345');
        Mail::fake();
        $this->asAdmin()->post("/staff/{$new->id}/invite")->assertSessionHasErrors('invite');
        Mail::assertNothingSent();
    }

    // --- Втори фактор ---

    public function test_the_admin_resets_the_second_factor_with_their_own_phone_code(): void
    {
        $this->asAdmin()->post("/staff/{$this->staff->id}/reset-two-factor", ['code' => $this->code($this->adminSecret)])
            ->assertRedirect("/staff/{$this->staff->id}")->assertSessionHasNoErrors();

        $fresh = $this->staff->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertSame(['two_factor_reset'], $this->events($this->staff));
    }

    public function test_a_wrong_code_or_the_staff_members_own_code_does_not_reset(): void
    {
        $staffSecret = app(Google2FA::class)->generateSecretKey();
        $this->staff->forceFill(['two_factor_secret' => \Laravel\Fortify\Fortify::currentEncrypter()->encrypt($staffSecret)])->save();

        $this->asAdmin()->post("/staff/{$this->staff->id}/reset-two-factor", ['code' => '000000'])->assertSessionHasErrors('code');
        $this->asAdmin()->post("/staff/{$this->staff->id}/reset-two-factor", ['code' => $this->code($staffSecret)])->assertSessionHasErrors('code');

        $this->assertNotNull($this->staff->fresh()->two_factor_secret);
        $this->assertSame([], $this->events($this->staff));
    }

    // --- Друга фирма ---

    public function test_staff_of_another_company_are_not_found(): void
    {
        $foreign = User::factory()->for(Company::factory())->withTwoFactor(app(Google2FA::class)->generateSecretKey())->create();
        $this->asAdmin();

        $this->get("/staff/{$foreign->id}")->assertNotFound();
        $this->put("/staff/{$foreign->id}", ['name' => 'Х', 'access_level' => 'read', 'property_scope' => 'all'])->assertNotFound();
        $this->put("/staff/{$foreign->id}/properties", ['property_ids' => []])->assertNotFound();
        $this->post("/staff/{$foreign->id}/stop")->assertNotFound();
        $this->post("/staff/{$foreign->id}/restore")->assertNotFound();
        $this->post("/staff/{$foreign->id}/invite")->assertNotFound();
        $this->post("/staff/{$foreign->id}/reset-two-factor", ['code' => $this->code($this->adminSecret)])->assertNotFound();

        $this->assertNull($foreign->fresh()->disabled_at);
        $this->assertNotNull($foreign->fresh()->two_factor_secret);
    }

    public function test_tenants_and_owners_are_not_managed_on_this_screen(): void
    {
        $tenant = User::factory()->for($this->company)->tenant()->create();

        $this->asAdmin()->get("/staff/{$tenant->id}")->assertNotFound();
        $this->asAdmin()->post("/staff/{$tenant->id}/stop")->assertNotFound();
    }
}
