<?php

namespace Tests\Feature\Registry;

use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Журнал „кой, кога и какво е променил“ (PROJECT.md, т. 2 и т. 6).
 * Само се добавя: записите в него не се поправят и не се трият.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $otherCompanyStaff;

    protected function setUp(): void
    {
        parent::setUp();
        // И двамата се създават преди „влизането“, за да са в различни фирми.
        $this->staff = User::factory()->create();
        $this->otherCompanyStaff = User::factory()->create();
        $this->actingAs($this->staff);
    }

    public function test_creating_a_record_is_logged_with_who_and_which_company(): void
    {
        $broker = Broker::factory()->create(['name' => 'Измислено Лице']);

        $entry = AuditLog::query()->sole();
        $this->assertSame('created', $entry->event);
        $this->assertSame('brokers', $entry->auditable_type);
        $this->assertSame($broker->id, $entry->auditable_id);
        $this->assertSame($this->staff->id, $entry->user_id);
        $this->assertSame($this->staff->company_id, $entry->company_id);
        $this->assertSame('Измислено Лице', $entry->changes['name']['new']);
        $this->assertNotNull($entry->created_at);
    }

    public function test_update_logs_only_the_changed_fields_with_old_and_new_value(): void
    {
        $broker = Broker::factory()->create(['name' => 'Старо Име', 'phone' => '0888000001']);

        $broker->update(['name' => 'Ново Име']);

        $entry = AuditLog::query()->where('event', 'updated')->sole();
        $this->assertSame(['name' => ['old' => 'Старо Име', 'new' => 'Ново Име']], $entry->changes);
    }

    public function test_archiving_and_restoring_are_logged(): void
    {
        $broker = Broker::factory()->create();

        $broker->archive();
        $broker->restoreFromArchive();

        $this->assertSame(
            ['created', 'archived', 'restored'],
            AuditLog::query()->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_protected_fields_are_logged_as_changed_but_without_their_values(): void
    {
        // Помощен модел само за теста: имейлът се третира като защитено поле (като IBAN).
        $model = new class extends Broker
        {
            protected array $auditProtected = ['email'];
        };
        $broker = $model->newQuery()->create(['name' => 'Измислено Лице', 'email' => 'old@example.test']);

        $broker->update(['email' => 'new@example.test']);

        $entry = AuditLog::query()->where('event', 'updated')->sole();
        $this->assertSame(['email' => ['old' => '[скрито]', 'new' => '[скрито]']], $entry->changes);
        $this->assertStringNotContainsString('example.test', json_encode(AuditLog::query()->pluck('changes')));
    }

    public function test_an_audit_entry_cannot_be_changed(): void
    {
        Broker::factory()->create();
        $entry = AuditLog::query()->sole();

        $this->expectException(LogicException::class);

        $entry->event = 'updated';
        $entry->save();
    }

    public function test_an_audit_entry_cannot_be_deleted(): void
    {
        Broker::factory()->create();

        $this->expectException(LogicException::class);

        AuditLog::query()->sole()->delete();
    }

    public function test_audit_entries_cannot_be_changed_or_deleted_in_bulk(): void
    {
        Broker::factory()->create();

        foreach ([fn () => AuditLog::query()->update(['event' => 'x']), fn () => AuditLog::query()->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Промяната на журнала трябваше да бъде отказана.');
            } catch (LogicException) {
                // очаквано
            }
        }

        $this->assertSame('created', AuditLog::query()->sole()->event);
    }

    public function test_company_a_does_not_see_the_audit_log_of_company_b(): void
    {
        Broker::factory()->create();

        $this->actingAs($this->otherCompanyStaff);

        $this->assertNotSame($this->staff->company_id, $this->otherCompanyStaff->company_id);
        $this->assertSame(0, AuditLog::query()->count());
    }
}
