<?php

namespace Tests\Feature\Screens;

use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\UserProperty;

/** Екранът „Имоти“: списък, търсене, форма, подробности, архив. */
class PropertyScreensTest extends ScreenTestCase
{
    public function test_list_shows_only_own_company_properties(): void
    {
        $this->record(Property::class, ['street' => 'ул. Нашата']);
        $this->record(Property::class, ['street' => 'ул. Чуждата'], $this->otherCompany);
        $this->actingAs($this->staff());

        $this->get('/properties')->assertOk()->assertSee('ул. Нашата')->assertDontSee('ул. Чуждата');
    }

    public function test_search_by_number_city_or_street(): void
    {
        $this->record(Property::class, ['number' => 127, 'city' => 'Варна', 'street' => 'ул. Морска']);
        $this->record(Property::class, ['number' => 5, 'city' => 'София', 'street' => 'ул. Планинска']);
        $this->actingAs($this->staff());

        $this->get('/properties?q=127')->assertSee('ул. Морска')->assertDontSee('ул. Планинска');
        $this->get('/properties?q=Варна')->assertSee('ул. Морска')->assertDontSee('ул. Планинска');
        $this->get('/properties?q=Планинска')->assertSee('ул. Планинска')->assertDontSee('ул. Морска');
    }

    public function test_search_treats_percent_and_underscore_as_plain_text(): void
    {
        $this->record(Property::class, ['street' => 'ул. Обикновена']);
        $this->actingAs($this->staff());

        $this->get('/properties?q=%25')->assertOk()->assertDontSee('ул. Обикновена');
    }

    public function test_archived_are_hidden_unless_asked_for(): void
    {
        $this->record(Property::class, ['street' => 'ул. Активна']);
        $this->record(Property::class, ['street' => 'ул. Архивна', 'archived_at' => now()]);
        $this->actingAs($this->staff());

        $this->get('/properties')->assertSee('ул. Активна')->assertDontSee('ул. Архивна');
        $this->get('/properties?archived=1')->assertSee('ул. Архивна')->assertDontSee('ул. Активна');
    }

    public function test_list_is_split_into_pages_of_50(): void
    {
        $type = $this->record(PropertyType::class);
        foreach (range(1, 51) as $number) {
            $this->record(Property::class, ['number' => $number, 'property_type_id' => $type->id]);
        }
        $this->actingAs($this->staff());

        $this->get('/properties')->assertOk()->assertSee('page=2', false);
    }

    public function test_staff_creates_a_property_with_an_automatic_number(): void
    {
        $type = $this->record(PropertyType::class, ['name' => 'Апартамент']);
        $broker = $this->record(Broker::class, ['name' => 'Измислено Лице']);
        $this->actingAs($this->staff());

        $response = $this->post('/properties', [
            'property_type_id' => $type->id,
            'broker_id' => $broker->id,
            'city' => 'София',
            'street' => 'ул. Измислена',
            'street_number' => '1',
            'area' => '55.5',
        ]);

        $property = Property::query()->sole();
        $response->assertRedirect("/properties/{$property->id}");
        $this->assertSame(1, $property->number);
        $this->assertSame('55.50', $property->area);
        $this->get("/properties/{$property->id}")->assertSee('Апартамент')->assertSee('Измислено Лице')->assertSee('ул. Измислена');
    }

    public function test_taken_number_shows_an_error_and_nothing_is_created(): void
    {
        $type = $this->record(PropertyType::class);
        $this->record(Property::class, ['number' => 7, 'property_type_id' => $type->id]);
        $this->actingAs($this->staff());

        $this->from('/properties/create')
            ->post('/properties', ['number' => 7, 'property_type_id' => $type->id, 'city' => 'София'])
            ->assertRedirect('/properties/create')
            ->assertSessionHasErrors(['number' => 'Номер 7 вече е зает във вашата фирма.']);

        $this->assertSame(1, Property::query()->count());
    }

    public function test_type_or_broker_of_another_company_is_rejected(): void
    {
        $foreignType = $this->record(PropertyType::class, [], $this->otherCompany);
        $foreignBroker = $this->record(Broker::class, [], $this->otherCompany);
        $ownType = $this->record(PropertyType::class);
        $this->actingAs($this->staff());

        $this->post('/properties', ['property_type_id' => $foreignType->id, 'city' => 'София'])
            ->assertSessionHasErrors('property_type_id');
        $this->post('/properties', ['property_type_id' => $ownType->id, 'broker_id' => $foreignBroker->id, 'city' => 'София'])
            ->assertSessionHasErrors('broker_id');

        $this->assertSame(0, Property::query()->count());
    }

    public function test_required_fields_are_checked_in_bulgarian(): void
    {
        $this->actingAs($this->staff());

        $this->post('/properties', [])->assertSessionHasErrors([
            'property_type_id' => 'Полето „Вид“ е задължително.',
            'city' => 'Полето „Град“ е задължително.',
        ]);
    }

    public function test_staff_edits_a_property_and_the_change_is_logged(): void
    {
        $property = $this->record(Property::class, ['city' => 'София']);
        $this->actingAs($this->staff());

        $this->put("/properties/{$property->id}", [
            'number' => $property->number,
            'property_type_id' => $property->property_type_id,
            'city' => 'Пловдив',
        ])->assertRedirect("/properties/{$property->id}");

        $this->assertSame('Пловдив', $property->fresh()->city);
        $this->assertSame(['old' => 'София', 'new' => 'Пловдив'],
            AuditLog::query()->where('event', 'updated')->sole()->changes['city']);
    }

    public function test_staff_archives_and_restores_a_property(): void
    {
        $property = $this->record(Property::class);
        $this->actingAs($this->staff());

        $this->post("/properties/{$property->id}/archive")->assertRedirect("/properties/{$property->id}");
        $this->assertNotNull($property->fresh()->archived_at);
        $this->get("/properties/{$property->id}")->assertSee('архивиран');

        $this->post("/properties/{$property->id}/restore")->assertRedirect("/properties/{$property->id}");
        $this->assertNull($property->fresh()->archived_at);
    }

    public function test_property_of_another_company_is_not_found(): void
    {
        $foreign = $this->record(Property::class, [], $this->otherCompany);
        $this->actingAs($this->staff());

        $this->get("/properties/{$foreign->id}")->assertNotFound();
        $this->get("/properties/{$foreign->id}/edit")->assertNotFound();
        $this->post("/properties/{$foreign->id}/archive")->assertNotFound();
    }

    public function test_user_with_assigned_properties_sees_only_those(): void
    {
        $assigned = $this->record(Property::class, ['street' => 'ул. Прикачена']);
        $other = $this->record(Property::class, ['street' => 'ул. Неприкачена']);
        $user = $this->assignedOnlyStaff();
        (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $user->id, 'property_id' => $assigned->id])->save();
        $this->actingAs($user);

        $this->get('/properties')->assertSee('ул. Прикачена')->assertDontSee('ул. Неприкачена');
        $this->get("/properties/{$other->id}")->assertNotFound();
    }
}
