<?php

namespace Tests\Feature\Portal;

use App\Enums\AccessLevel;
use App\Enums\Role;
use App\Mail\InvitationMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Owner;
use App\Models\PortalLink;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Invitation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Основата на портала: „Покани в портала“ от картите на наемателя и
 * собственика (план „Портал и служители“, 27.11.2026). Само служител с
 * „четене и запис“. Имейлът се взима от записа; без имейл няма портал.
 * Профилът е с роля „клиент“; връзките към наемател и собственик решават
 * какво вижда. Един човек и наемател, и собственик (същият имейл) — един
 * профил с две връзки. Само измислени данни.
 */
class PortalInviteTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->company = Company::factory()->create(['name' => 'Тест Алфа ООД']);
        $this->onAddressOf($this->company);
        $this->staff = User::factory()->for($this->company)->withTwoFactor(app(Google2FA::class)->generateSecretKey())->create();
        $this->tenant = Tenant::factory()->create(['company_id' => $this->company->id, 'name' => 'Иван Измислен', 'email' => 'ivan@example.test']);
    }

    private function inviteTenant(?Tenant $tenant = null): \Illuminate\Testing\TestResponse
    {
        $tenant ??= $this->tenant;

        return $this->actingAs($this->staff)->post("/tenants/{$tenant->id}/portal/invite");
    }

    private function client(string $email = 'ivan@example.test'): User
    {
        return User::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('email', $email)->sole();
    }

    public function test_inviting_a_tenant_creates_a_client_profile_linked_to_the_tenant(): void
    {
        $this->inviteTenant()->assertRedirect("/tenants/{$this->tenant->id}")->assertSessionHas('status');

        $client = $this->client();
        $this->assertSame(Role::Client, $client->role);
        $this->assertSame('Иван Измислен', $client->name);
        $this->assertFalse($client->is_company_admin);
        $this->assertSame([[$this->tenant->id, null]], PortalLink::query()->where('user_id', $client->id)->get()
            ->map(fn ($l) => [$l->tenant_id, $l->owner_id])->all());
        Mail::assertSent(InvitationMail::class, fn (InvitationMail $m) => $m->hasTo('ivan@example.test'));
        $this->assertSame(['created', 'invited'], AuditLog::query()->withoutGlobalScopes()->where('auditable_type', 'users')
            ->where('auditable_id', $client->id)->orderBy('id')->pluck('event')->all());
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('auditable_type', 'portal_links')->where('event', 'created')->count());
    }

    public function test_inviting_an_owner_works_the_same_way(): void
    {
        $owner = Owner::factory()->create(['company_id' => $this->company->id, 'email' => 'maria@example.test']);

        $this->actingAs($this->staff)->post("/owners/{$owner->id}/portal/invite")->assertRedirect("/owners/{$owner->id}");

        $this->assertSame([[null, $owner->id]], PortalLink::query()->where('user_id', $this->client('maria@example.test')->id)->get()
            ->map(fn ($l) => [$l->tenant_id, $l->owner_id])->all());
    }

    public function test_one_person_who_is_tenant_and_owner_gets_one_profile_with_two_links(): void
    {
        $owner = Owner::factory()->create(['company_id' => $this->company->id, 'email' => 'IVAN@example.test ']);

        $this->inviteTenant();
        $this->actingAs($this->staff)->post("/owners/{$owner->id}/portal/invite");

        $this->assertSame(1, User::query()->withoutGlobalScopes()->where('email', 'ivan@example.test')->count());
        $this->assertSame(2, PortalLink::query()->where('user_id', $this->client()->id)->count());
        Mail::assertSent(InvitationMail::class, 2); // още няма парола — поканата се праща отново
    }

    public function test_a_profile_that_has_a_password_only_gets_the_new_link(): void
    {
        $this->inviteTenant();
        Invitation::accept($this->client(), 'нова-парола-12345');
        Mail::fake();
        $owner = Owner::factory()->create(['company_id' => $this->company->id, 'email' => 'ivan@example.test']);

        $this->actingAs($this->staff)->post("/owners/{$owner->id}/portal/invite")->assertSessionHas('status', __('portal.invite.linked', ['email' => 'ivan@example.test']));

        Mail::assertNothingSent();
        $this->assertSame(2, PortalLink::query()->where('user_id', $this->client()->id)->count());
    }

    public function test_without_an_email_there_is_no_portal(): void
    {
        $this->tenant->forceFill(['email' => null])->save();

        $this->actingAs($this->staff)->get("/tenants/{$this->tenant->id}")->assertOk()
            ->assertSee(__('portal.card.no_email'))->assertDontSee(__('portal.card.invite'));
        $this->inviteTenant()->assertSessionHasErrors('portal');

        $this->assertSame(0, User::query()->withoutGlobalScopes()->where('role', 'client')->count());
        Mail::assertNothingSent();
    }

    public function test_only_staff_with_write_access_invite(): void
    {
        $reader = User::factory()->for($this->company)->withTwoFactor(app(Google2FA::class)->generateSecretKey())->create(['access_level' => AccessLevel::Read]);

        $this->actingAs($reader)->get("/tenants/{$this->tenant->id}")->assertOk()->assertDontSee(__('portal.card.invite'));
        $this->actingAs($reader)->post("/tenants/{$this->tenant->id}/portal/invite")->assertForbidden();

        $this->assertSame(0, User::query()->withoutGlobalScopes()->where('role', 'client')->count());
    }

    public function test_an_archived_record_is_not_invited(): void
    {
        $this->tenant->forceFill(['archived_at' => now()])->save();

        $this->inviteTenant()->assertSessionHasErrors('portal');
        $this->assertSame(0, User::query()->withoutGlobalScopes()->where('role', 'client')->count());
    }

    public function test_the_email_of_a_staff_member_is_not_used_for_the_portal(): void
    {
        $this->tenant->forceFill(['email' => $this->staff->email])->save();

        $this->inviteTenant()->assertSessionHasErrors('portal');
        $this->assertSame(0, PortalLink::query()->count());
        $this->assertSame(Role::Staff, $this->staff->fresh()->role);
    }

    public function test_a_tenant_of_another_company_is_not_found(): void
    {
        $foreign = Tenant::factory()->create(['email' => 'chuzhd@example.test']);

        $this->inviteTenant($foreign)->assertNotFound();
        $this->assertSame(0, User::query()->withoutGlobalScopes()->where('role', 'client')->count());
    }

    public function test_the_card_shows_the_portal_state(): void
    {
        $card = fn () => $this->actingAs($this->staff)->get("/tenants/{$this->tenant->id}")->assertOk();

        $card()->assertSee(__('portal.state.none'))->assertSee(__('portal.card.invite'));

        $this->inviteTenant();
        $card()->assertSee(__('portal.state.invited'))->assertSee(__('portal.card.invite_again'));

        Invitation::accept($this->client(), 'нова-парола-12345');
        $card()->assertSee(__('portal.state.active'))->assertSee(__('portal.card.stop'));

        $this->actingAs($this->staff)->post("/tenants/{$this->tenant->id}/portal/stop")->assertRedirect("/tenants/{$this->tenant->id}");
        $this->assertTrue($this->client()->isStopped());
        $card()->assertSee(__('portal.state.stopped'))->assertSee(__('portal.card.restore'));

        $this->actingAs($this->staff)->post("/tenants/{$this->tenant->id}/portal/restore");
        $this->assertFalse($this->client()->isStopped());
    }

    public function test_a_link_cannot_point_to_a_record_of_another_company(): void
    {
        $this->inviteTenant();
        auth()->logout(); // иначе записът става на фирмата на влезлия служител
        $foreign = Tenant::factory()->create();
        $this->assertNotSame($this->company->id, $foreign->company_id);

        $this->expectException(QueryException::class);
        DB::table('portal_links')->insert(['company_id' => $this->company->id, 'user_id' => $this->client()->id, 'tenant_id' => $foreign->id]);
    }

    public function test_a_link_points_to_exactly_one_record(): void
    {
        $this->inviteTenant();
        $owner = Owner::factory()->create(['company_id' => $this->company->id]);

        $this->expectException(QueryException::class);
        DB::table('portal_links')->insert(['company_id' => $this->company->id, 'user_id' => $this->client()->id,
            'tenant_id' => $this->tenant->id, 'owner_id' => $owner->id]);
    }

    public function test_the_database_accepts_the_client_role_only_with_a_company(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_role_company_check');
        DB::table('users')->insert(['name' => 'Х', 'email' => 'x@example.test', 'password' => 'x', 'role' => 'client', 'company_id' => null]);
    }
}
