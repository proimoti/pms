<?php

namespace Tests\Feature\Screens;

use App\Models\Broker;

/** Екранът „Отговорни лица“. */
class BrokerScreensTest extends ScreenTestCase
{
    public function test_staff_creates_edits_and_archives_a_broker(): void
    {
        $this->actingAs($this->staff());

        $this->post('/brokers', ['name' => 'Измислено Лице', 'phone' => '0888000000', 'email' => 'lice@example.test'])
            ->assertSessionHasNoErrors();
        $broker = Broker::query()->sole();

        $this->get('/brokers')->assertSee('Измислено Лице')->assertSee('0888000000');

        $this->put("/brokers/{$broker->id}", ['name' => 'Ново Име'])->assertRedirect("/brokers/{$broker->id}");
        $this->assertSame('Ново Име', $broker->fresh()->name);

        $this->post("/brokers/{$broker->id}/archive");
        $this->assertNotNull($broker->fresh()->archived_at);
    }

    public function test_name_is_required_and_email_must_be_valid(): void
    {
        $this->actingAs($this->staff());

        $this->post('/brokers', ['email' => 'not-an-email'])->assertSessionHasErrors(['name', 'email']);
    }

    public function test_user_with_assigned_properties_still_sees_brokers(): void
    {
        $this->record(Broker::class, ['name' => 'Видимо Лице']);
        $this->actingAs($this->assignedOnlyStaff());

        $this->get('/brokers')->assertSee('Видимо Лице');
    }

    public function test_search_by_name(): void
    {
        $this->record(Broker::class, ['name' => 'Петър Измислен']);
        $this->record(Broker::class, ['name' => 'Георги Измислен']);
        $this->actingAs($this->staff());

        $this->get('/brokers?q=Петър')->assertSee('Петър Измислен')->assertDontSee('Георги Измислен');
    }
}
