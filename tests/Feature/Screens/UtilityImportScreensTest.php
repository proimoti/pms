<?php

namespace Tests\Feature\Screens;

use App\Models\PaidUtilityBill;
use App\Models\Property;
use App\Models\Provider;
use App\Models\UtilityAccount;
use App\Models\UtilityImportIssue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UtilityFiles;

/**
 * Екраните на вноса (PROJECT.md, т. 5.3, 21–22.11.2026): качване, преглед без
 * запис, „Внеси“, списък „за решение“ с „Свържи“ и „Игнорирай“. Качените
 * файлове не се пазят. Само измислени данни.
 */
class UtilityImportScreensTest extends ScreenTestCase
{
    use UtilityFiles;

    private Provider $power;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->power = (new Provider)->forceFill(['company_id' => $this->company->id, 'name' => 'Измислено Електро', 'easypay_name' => 'IZM EL']);
        $this->power->save();
        $this->property = $this->record(Property::class, ['number' => 101]);
        $account = (new UtilityAccount)->forceFill([
            'company_id' => $this->company->id, 'provider_id' => $this->power->id, 'number' => '0012345',
            'kind' => 'electricity', 'billing' => 'actual', 'valid_from' => '2026-01-01',
        ]);
        $account->save();
        $account->properties()->forceCreate(['company_id' => $this->company->id, 'property_id' => $this->property->id, 'share' => '100', 'position' => 1]);
    }

    private function upload(array $rows): UploadedFile
    {
        return new UploadedFile($this->easypayFile($rows), 'easypay-dekemvri.xlsx', null, null, true);
    }

    private function rows(): array
    {
        return [
            ['ref' => 900001, 'date' => '2026-12-05 10:15:00', 'number' => 12345, 'provider' => 'IZM EL', 'amount' => 48.2],
            ['ref' => 900002, 'date' => '2026-12-06 09:00:00', 'number' => 4444, 'provider' => 'IZM EL', 'amount' => 30],
        ];
    }

    public function test_upload_shows_a_preview_and_saves_nothing(): void
    {
        $this->actingAs($this->staff());

        $response = $this->post('/utility-imports/preview', ['file' => $this->upload($this->rows())])->assertOk();

        $response->assertSee('easypay-dekemvri.xlsx')->assertSee('0012345')->assertSee('48,20')->assertSee('№ 101')
            ->assertSee('4444')->assertSee(__('utilities.reasons.unknown_number'));
        $this->assertSame(0, PaidUtilityBill::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles("imports/{$this->company->id}"), 'временно, до „Внеси“ или „Откажи“');
    }

    public function test_confirm_imports_and_deletes_the_file(): void
    {
        $this->actingAs($this->staff());
        $token = $this->post('/utility-imports/preview', ['file' => $this->upload($this->rows())])->viewData('token');

        $this->post('/utility-imports', ['token' => $token])->assertRedirect();

        $this->assertSame(1, PaidUtilityBill::query()->count());
        $this->assertSame(1, UtilityImportIssue::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles("imports/{$this->company->id}"));

        $this->get('/utility-imports')->assertOk()->assertSee('easypay-dekemvri.xlsx');
    }

    public function test_cancel_deletes_the_file(): void
    {
        $this->actingAs($this->staff());
        $token = $this->post('/utility-imports/preview', ['file' => $this->upload($this->rows())])->viewData('token');

        $this->post('/utility-imports/cancel', ['token' => $token])->assertRedirect('/utility-imports');

        $this->assertSame([], Storage::disk('local')->allFiles("imports/{$this->company->id}"));
        $this->assertSame(0, PaidUtilityBill::query()->count());
    }

    public function test_token_of_another_company_is_refused(): void
    {
        $other = $this->staff([], $this->otherCompany); // преди влизането, иначе защитата го слага в нашата фирма
        $this->actingAs($this->staff());
        $token = $this->post('/utility-imports/preview', ['file' => $this->upload($this->rows())])->viewData('token');

        $this->actingAs($other);
        $this->post('/utility-imports', ['token' => $token])->assertSessionHasErrors('token');
        $this->post('/utility-imports', ['token' => '../../etc/passwd'])->assertSessionHasErrors('token');
    }

    public function test_wrong_file_shows_an_error(): void
    {
        $this->actingAs($this->staff());

        $this->from('/utility-imports')->post('/utility-imports/preview', ['file' => UploadedFile::fake()->create('bills.pdf', 10)])
            ->assertRedirect('/utility-imports')->assertSessionHasErrors('file');
    }

    public function test_issues_list_with_link_and_ignore(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);
        $token = $this->post('/utility-imports/preview', ['file' => $this->upload($this->rows())])->viewData('token');
        $this->post('/utility-imports', ['token' => $token]);
        $issue = UtilityImportIssue::query()->sole();

        $this->get('/utility-issues')->assertOk()->assertSee('4444')->assertSee(__('utilities.reasons.unknown_number'))
            ->assertSee('/utility-accounts/create?provider_id='.$this->power->id.'&amp;number=4444', false);

        $this->post("/utility-issues/{$issue->id}/ignore", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/utility-issues/{$issue->id}/ignore", ['reason' => 'грешно платено'])->assertRedirect('/utility-issues');
        $this->assertSame('ignored', $issue->fresh()->status);
        $this->get('/utility-issues')->assertDontSee('грешно платено');
        $this->get('/utility-issues?status=ignored')->assertSee('грешно платено');
    }

    public function test_ignored_list_shows_who_and_when_and_unignore_removes_the_rule(): void
    {
        $staff = $this->staff(['name' => 'Измислена Служителка']);
        $this->actingAs($staff);
        $token = $this->post('/utility-imports/preview', ['file' => $this->upload($this->rows())])->viewData('token');
        $this->post('/utility-imports', ['token' => $token]);
        $issue = UtilityImportIssue::query()->sole();
        $this->post("/utility-issues/{$issue->id}/ignore", ['reason' => 'грешно платено']);

        $this->get('/utility-issues?status=ignored')->assertOk()
            ->assertSee('06.12.2026')->assertSee('30,00')->assertSee('4444')->assertSee('IZM EL')->assertSee('грешно платено')
            ->assertSee('Измислена Служителка')->assertSee($issue->fresh()->decided_at->format('d.m.Y H:i'))
            ->assertSee(__('utilities.import.unignore'))->assertSee("/utility-issues/{$issue->id}/unignore", false);

        $this->post("/utility-issues/{$issue->id}/unignore")->assertRedirect('/utility-issues?status=ignored')->assertSessionHas('status');
        $this->assertSame(0, UtilityImportIssue::query()->count());
        $this->get('/utility-issues?status=ignored')->assertDontSee('грешно платено');
    }

    public function test_read_only_staff_cannot_unignore(): void
    {
        $this->actingAs($this->staff());
        $token = $this->post('/utility-imports/preview', ['file' => $this->upload($this->rows())])->viewData('token');
        $this->post('/utility-imports', ['token' => $token]);
        $issue = UtilityImportIssue::query()->sole();
        $this->post("/utility-issues/{$issue->id}/ignore", ['reason' => 'грешно платено']);

        $this->actingAs($this->readOnlyStaff());
        $this->post("/utility-issues/{$issue->id}/unignore")->assertForbidden();
        $this->assertSame(1, UtilityImportIssue::query()->count());
    }

    public function test_new_account_from_the_issue_imports_the_row(): void
    {
        $this->actingAs($this->staff());
        $token = $this->post('/utility-imports/preview', ['file' => $this->upload($this->rows())])->viewData('token');
        $this->post('/utility-imports', ['token' => $token]);

        $this->get('/utility-accounts/create?provider_id='.$this->power->id.'&number=4444')->assertOk()->assertSee('value="4444"', false);

        $this->post('/utility-accounts', [
            'provider_id' => $this->power->id, 'number' => '4444', 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '01.01.2026', 'properties' => [['property_id' => $this->property->id]],
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertSame('imported', UtilityImportIssue::query()->sole()->status);
        $this->assertSame(2, PaidUtilityBill::query()->count());
    }

    public function test_read_only_staff_cannot_import(): void
    {
        $this->actingAs($this->readOnlyStaff());

        $this->get('/utility-imports')->assertForbidden();
        $this->post('/utility-imports/preview', ['file' => $this->upload($this->rows())])->assertForbidden();
        $this->get('/utility-issues')->assertForbidden();
    }

    public function test_menu_has_the_import(): void
    {
        $this->actingAs($this->staff());

        $this->get('/utility-accounts')->assertSee('href="/utility-imports"', false)->assertSee('href="/utility-issues"', false);
    }
}
