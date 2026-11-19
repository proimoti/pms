<?php

namespace Tests\Feature\Registry;

use App\Models\Owner;
use App\Models\Property;
use App\Models\ReservedPropertyNumber;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * Номера на имоти, собственици и наематели (PROJECT.md, т. 2 „Регистър, уточнения“):
 * цели числа, отделна поредица за всеки вид и всяка фирма, с ръчна промяна и проверка за
 * дубликат във фирмата. Имоти: следващият = най-малкият свободен; архивираните и резервираните
 * са заети, номер никога не се освобождава (ръчно сменен остава резервиран). Собственици и
 * наематели: следващият = най-големият + 1 (променено 19.11.2026).
 */
class NumbersTest extends TestCase
{
    use RefreshDatabase;

    private User $alpha;

    private User $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = User::factory()->create();
        $this->beta = User::factory()->create();
        $this->actingAs($this->alpha);
    }

    public function test_first_property_number_is_1_and_the_next_is_the_smallest_free(): void
    {
        $this->assertSame(1, Property::factory()->create()->number);
        $this->assertSame(2, Property::factory()->create()->number);
    }

    public function test_property_number_fills_the_smallest_gap(): void
    {
        foreach ([1, 2, 4, 5] as $number) {
            Property::factory()->create(['number' => $number]);
        }

        $this->assertSame(3, Property::factory()->create()->number);
        $this->assertSame(6, Property::factory()->create()->number);
    }

    public function test_owner_and_tenant_numbers_are_the_largest_plus_1_even_with_gaps(): void
    {
        Owner::factory()->create(['number' => 1]);
        Owner::factory()->create(['number' => 3]);
        Tenant::factory()->create(['number' => 2]);

        $this->assertSame(4, Owner::factory()->create()->number);
        $this->assertSame(3, Tenant::factory()->create()->number);
    }

    public function test_numbers_are_whole_numbers_not_text(): void
    {
        Owner::factory()->create(['number' => 9]);
        Owner::factory()->create(['number' => 10]);

        // При текст „9“ би било по-голямо от „10“ и следващият би бил 10 (дубликат).
        $this->assertSame(11, Owner::factory()->create()->number);
        $this->assertSame([9, 10, 11], Owner::query()->orderBy('number')->pluck('number')->all());
    }

    public function test_each_kind_has_its_own_sequence(): void
    {
        Property::factory()->count(3)->create();

        $this->assertSame(1, Owner::factory()->create()->number);
        $this->assertSame(1, Tenant::factory()->create()->number);
    }

    public function test_each_company_has_its_own_sequence(): void
    {
        Property::factory()->count(3)->create();

        $this->actingAs($this->beta);

        $this->assertSame(1, Property::factory()->create()->number);
    }

    public function test_manual_number_is_kept_and_the_owner_sequence_continues_after_it(): void
    {
        $this->assertSame(500, Owner::factory()->create(['number' => 500])->number);
        $this->assertSame(501, Owner::factory()->create()->number);
    }

    public function test_manual_property_number_does_not_move_the_sequence(): void
    {
        $this->assertSame(500, Property::factory()->create(['number' => 500])->number);
        $this->assertSame(1, Property::factory()->create()->number);
    }

    public function test_duplicate_number_in_the_same_company_is_rejected(): void
    {
        Owner::factory()->create(['number' => 7]);

        $this->expectException(ValidationException::class);

        Owner::factory()->create(['number' => 7]);
    }

    public function test_same_number_in_another_company_is_allowed(): void
    {
        Tenant::factory()->create(['number' => 7]);

        $this->actingAs($this->beta);

        $this->assertSame(7, Tenant::factory()->create(['number' => 7])->number);
    }

    public function test_number_of_an_archived_record_is_not_reused(): void
    {
        $first = Property::factory()->create();
        Property::factory()->create();
        $first->archive();

        $this->assertSame(3, Property::factory()->create()->number);
    }

    public function test_manually_changed_property_number_stays_reserved(): void
    {
        Property::factory()->count(2)->create();
        $third = Property::factory()->create();

        $third->update(['number' => 500]);

        $this->assertSame([3], ReservedPropertyNumber::query()->pluck('number')->all());
        $this->assertSame(4, Property::factory()->create()->number);
    }

    public function test_reserved_number_cannot_be_given_to_a_new_property(): void
    {
        $property = Property::factory()->create(['number' => 3]);
        $property->update(['number' => 30]);

        try {
            Property::factory()->create(['number' => 3]);
            $this->fail('Резервиран номер беше даден на нов имот.');
        } catch (ValidationException $e) {
            $this->assertSame(['Номер 3 е резервиран и не може да се ползва.'], $e->errors()['number']);
        }
    }

    public function test_reserved_number_cannot_be_taken_by_changing_another_property(): void
    {
        $first = Property::factory()->create(['number' => 3]);
        $first->update(['number' => 30]);
        $second = Property::factory()->create(['number' => 4]);

        $this->expectException(ValidationException::class);

        $second->update(['number' => 3]);
    }

    public function test_reserved_numbers_are_kept_per_company(): void
    {
        $property = Property::factory()->create(['number' => 1]);
        $property->update(['number' => 10]);

        $this->actingAs($this->beta);

        $this->assertSame(1, Property::factory()->create()->number);
        $this->assertSame(0, ReservedPropertyNumber::query()->count());
    }

    public function test_reserved_numbers_are_never_deleted(): void
    {
        $property = Property::factory()->create(['number' => 1]);
        $property->update(['number' => 10]);

        $this->expectException(LogicException::class);

        ReservedPropertyNumber::query()->sole()->delete();
    }

    public function test_number_can_be_changed_by_hand_while_not_locked(): void
    {
        $property = Property::factory()->create();

        // Заключването идва с първото начисление. Дотогава номерът може да се сменя.
        $this->assertFalse($property->isNumberLocked());

        $property->update(['number' => 42]);

        $this->assertSame(42, $property->fresh()->number);
    }

    public function test_changing_to_a_taken_number_is_rejected(): void
    {
        Property::factory()->create(['number' => 1]);
        $second = Property::factory()->create(['number' => 2]);

        $this->expectException(ValidationException::class);

        $second->update(['number' => 1]);
    }

    public function test_database_rejects_zero_and_negative_numbers(): void
    {
        $property = Property::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('properties')->where('id', $property->id)->update(['number' => 0]);
    }
}
