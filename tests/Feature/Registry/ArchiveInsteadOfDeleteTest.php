<?php

namespace Tests\Feature\Registry;

use App\Models\Broker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/** Записите в регистъра не се трият, само се архивират (PROJECT.md, т. 2). */
class ArchiveInsteadOfDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_a_record_cannot_be_deleted(): void
    {
        $broker = Broker::factory()->create();

        try {
            $broker->delete();
            $this->fail('Изтриването трябваше да бъде отказано.');
        } catch (LogicException) {
            $this->assertDatabaseHas('brokers', ['id' => $broker->id]);
        }
    }

    public function test_records_cannot_be_deleted_in_bulk_either(): void
    {
        $broker = Broker::factory()->create();

        try {
            Broker::query()->delete();
            $this->fail('Изтриването трябваше да бъде отказано.');
        } catch (LogicException) {
            $this->assertDatabaseHas('brokers', ['id' => $broker->id]);
        }
    }

    public function test_archived_record_stays_in_the_database_but_leaves_the_active_list(): void
    {
        $active = Broker::factory()->create();
        $archived = Broker::factory()->create();

        $archived->archive();

        $this->assertNotNull($archived->fresh()->archived_at);
        $this->assertSame([$active->id], Broker::query()->active()->pluck('id')->all());
        $this->assertSame([$archived->id], Broker::query()->archived()->pluck('id')->all());
    }

    public function test_archived_record_can_be_restored(): void
    {
        $broker = Broker::factory()->create();
        $broker->archive();

        $broker->restoreFromArchive();

        $this->assertNull($broker->fresh()->archived_at);
    }
}
