<?php

namespace Tests\Feature\Registry;

use App\Models\Owner;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Номера на имоти, собственици и наематели (PROJECT.md, т. 2 „Регистър, уточнения“):
 * цели числа, отделна поредица за всеки вид и всяка фирма, следващият = най-големият + 1,
 * с ръчна промяна и проверка за дубликат във фирмата.
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

    public function test_first_number_is_1_and_the_next_is_the_largest_plus_1(): void
    {
        $this->assertSame(1, Property::factory()->create()->number);
        $this->assertSame(2, Property::factory()->create()->number);
    }

    public function test_numbers_are_whole_numbers_not_text(): void
    {
        Property::factory()->create(['number' => 9]);
        Property::factory()->create(['number' => 10]);

        // При текст „9“ би било по-голямо от „10“ и следващият би бил 10 (дубликат).
        $this->assertSame(11, Property::factory()->create()->number);
        $this->assertSame([9, 10, 11], Property::query()->orderBy('number')->pluck('number')->all());
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

    public function test_manual_number_is_kept_and_the_sequence_continues_after_it(): void
    {
        $this->assertSame(500, Property::factory()->create(['number' => 500])->number);
        $this->assertSame(501, Property::factory()->create()->number);
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
        Property::factory()->create();
        $last = Property::factory()->create();
        $last->archive();

        $this->assertSame(3, Property::factory()->create()->number);
    }

    public function test_number_can_be_changed_by_hand_while_not_locked(): void
    {
        $property = Property::factory()->create();

        // Заключването идва с договорите и книгата. Дотогава номерът е свободен.
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
