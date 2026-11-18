<?php

namespace Tests\Feature\Tenancy;

use App\Models\Broker;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Изолация между фирмите (CLAUDE.md, правила за безопасност).
 *
 * Потребител на фирма A никога не вижда и не променя данни на фирма B.
 * Само администраторът на приложението (без фирма) вижда всички фирми.
 * Всяка нова таблица с company_id получава свои проверки тук.
 */
class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;

    private Company $beta;

    private User $alphaUser;

    private User $betaUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Само измислени данни.
        $this->alpha = Company::factory()->create(['name' => 'Тест Алфа ООД']);
        $this->beta = Company::factory()->create(['name' => 'Тест Бета ООД']);

        $this->alphaUser = User::factory()->for($this->alpha)->create();
        $this->betaUser = User::factory()->for($this->beta)->create();
    }

    public function test_company_a_user_lists_only_company_a_users(): void
    {
        $this->actingAs($this->alphaUser);

        $this->assertEqualsCanonicalizing(
            [$this->alphaUser->id],
            User::query()->pluck('id')->all(),
        );
    }

    public function test_company_b_user_lists_only_company_b_users(): void
    {
        $this->actingAs($this->betaUser);

        $this->assertEqualsCanonicalizing(
            [$this->betaUser->id],
            User::query()->pluck('id')->all(),
        );
    }

    public function test_company_a_user_cannot_open_company_b_user_by_id(): void
    {
        $this->actingAs($this->alphaUser);

        $this->assertNull(User::find($this->betaUser->id));
    }

    public function test_record_created_by_company_a_user_belongs_to_company_a_even_if_b_is_given(): void
    {
        $this->actingAs($this->alphaUser);

        $created = User::factory()->create(['company_id' => $this->beta->id]);

        $this->assertDatabaseHas('users', [
            'id' => $created->id,
            'company_id' => $this->alpha->id,
        ]);
    }

    public function test_company_a_user_cannot_move_a_record_to_company_b(): void
    {
        $this->actingAs($this->alphaUser);

        $record = User::findOrFail($this->alphaUser->id);
        $record->company_id = $this->beta->id;
        $record->save();

        $this->assertDatabaseHas('users', [
            'id' => $this->alphaUser->id,
            'company_id' => $this->alpha->id,
        ]);
    }

    public function test_company_a_user_sees_only_company_a_among_companies(): void
    {
        $this->actingAs($this->alphaUser);

        $this->assertEqualsCanonicalizing(
            [$this->alpha->id],
            Company::query()->pluck('id')->all(),
        );
        $this->assertNull(Company::find($this->beta->id));
    }

    public function test_app_admin_sees_all_companies(): void
    {
        $this->actingAs(User::factory()->appAdmin()->create());

        $this->assertEqualsCanonicalizing(
            [$this->alpha->id, $this->beta->id],
            Company::query()->pluck('id')->all(),
        );
    }

    public function test_company_a_user_sees_only_company_a_brokers(): void
    {
        $this->actingAs($this->betaUser);
        $betaBroker = Broker::factory()->create();

        $this->actingAs($this->alphaUser);
        $alphaBroker = Broker::factory()->create();

        $this->assertSame([$alphaBroker->id], Broker::query()->pluck('id')->all());
        $this->assertNull(Broker::find($betaBroker->id));
    }

    public function test_broker_created_by_company_a_user_belongs_to_company_a_even_if_b_is_given(): void
    {
        $this->actingAs($this->alphaUser);

        $broker = Broker::factory()->create(['company_id' => $this->beta->id]);

        $this->assertDatabaseHas('brokers', ['id' => $broker->id, 'company_id' => $this->alpha->id]);
    }

    public function test_app_admin_sees_users_of_all_companies(): void
    {
        $admin = User::factory()->appAdmin()->create();

        $this->actingAs($admin);

        $this->assertEqualsCanonicalizing(
            [$this->alphaUser->id, $this->betaUser->id, $admin->id],
            User::query()->pluck('id')->all(),
        );
    }
}
