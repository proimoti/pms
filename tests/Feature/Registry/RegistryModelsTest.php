<?php

namespace Tests\Feature\Registry;

use App\Models\Beneficiary;
use App\Models\Broker;
use App\Models\Concerns\Archivable;
use App\Models\Concerns\Audited;
use App\Models\Owner;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Общите правила важат за всяка таблица на регистъра:
 * изолация между фирмите, скрита от администратора на приложението,
 * архив вместо триене и журнал.
 */
class RegistryModelsTest extends TestCase
{
    use RefreshDatabase;

    private const MODELS = [
        Broker::class, PropertyType::class, Property::class,
        Owner::class, Tenant::class, Beneficiary::class,
    ];

    public function test_every_registry_model_is_archivable_and_audited(): void
    {
        foreach (self::MODELS as $class) {
            $traits = class_uses_recursive($class);
            $this->assertContains(Archivable::class, $traits, "{$class} не се архивира.");
            $this->assertContains(Audited::class, $traits, "{$class} не се записва в журнала.");
        }
    }

    public function test_company_b_sees_none_of_company_a_records(): void
    {
        $alpha = User::factory()->create();
        $beta = User::factory()->create();

        $this->actingAs($alpha);
        foreach (self::MODELS as $class) {
            $class::factory()->create();
        }

        $this->actingAs($beta);
        foreach (self::MODELS as $class) {
            $this->assertSame(0, $class::query()->count(), "Фирма Б вижда {$class} на фирма А.");
        }
    }

    public function test_app_admin_sees_none_of_the_registry(): void
    {
        $staff = User::factory()->create();
        $admin = User::factory()->appAdmin()->create();

        $this->actingAs($staff);
        foreach (self::MODELS as $class) {
            $class::factory()->create();
        }

        $this->actingAs($admin);
        foreach (self::MODELS as $class) {
            $this->assertSame(0, $class::query()->count(), "Администраторът вижда {$class}.");
        }
    }
}
