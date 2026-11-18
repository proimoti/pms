<?php

namespace Tests\Feature\Registry;

use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Администраторът на приложението не вижда регистъра (PROJECT.md, т. 2):
 * вижда фирмите и потребителите, но не имоти, лица и журнала им.
 */
class AppAdminCannotSeeRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_admin_sees_no_registry_records(): void
    {
        $broker = Broker::factory()->create();

        $this->actingAs(User::factory()->appAdmin()->create());

        $this->assertSame(0, Broker::query()->count());
        $this->assertNull(Broker::find($broker->id));
    }

    public function test_app_admin_cannot_create_registry_records(): void
    {
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->appAdmin()->create());

        $this->expectException(AuthorizationException::class);

        Broker::factory()->for($company)->create();
    }

    public function test_app_admin_sees_no_audit_log(): void
    {
        // Двамата се създават преди „влизането“: влязъл служител не може да създаде администратор.
        $staff = User::factory()->create();
        $admin = User::factory()->appAdmin()->create();

        $this->actingAs($staff);
        Broker::factory()->create();

        $this->actingAs($admin);

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_app_admin_still_sees_companies_and_users(): void
    {
        $staff = User::factory()->create();

        $this->actingAs(User::factory()->appAdmin()->create());

        $this->assertTrue(Company::query()->whereKey($staff->company_id)->exists());
        $this->assertTrue(User::query()->whereKey($staff->id)->exists());
    }
}
