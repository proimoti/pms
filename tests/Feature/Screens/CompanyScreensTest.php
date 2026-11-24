<?php

namespace Tests\Feature\Screens;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Екранът „Фирма“ (PROJECT.md, „PDF и имейл на известията“, 24.11.2026):
 * данните на фирмата за известията и банковите ѝ сметки (може да са няколко
 * и да се сменят, без шифроване; една е „за известията“). Само
 * администраторът на фирмата ги променя; всяка промяна е в журнала.
 * Само измислени данни (IBAN-ите са измислени, с вярна контролна сума).
 */
class CompanyScreensTest extends ScreenTestCase
{
    private const IBAN_A = 'BG80BNBG96611020345678';

    private const IBAN_B = 'BG18RZBB91550123456789';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->staff(['is_company_admin' => true, 'name' => 'Админ Измислен']);
        $this->actingAs($this->admin);
    }

    private function account(string $iban, array $extra = []): BankAccount
    {
        $this->post('/company/bank-accounts', ['bank_name' => 'Измислена банка', 'iban' => $iban] + $extra)->assertSessionHasNoErrors();

        return BankAccount::query()->where('iban', str_replace(' ', '', $iban))->sole();
    }

    public function test_admin_sees_and_edits_the_company_data_with_an_audit_entry(): void
    {
        $this->get('/company')->assertOk()->assertSee('Тест Алфа ООД')->assertSee($this->company->eik);

        $this->put('/company', [
            'name' => 'Тест Алфа ООД', 'eik' => '123456789', 'vat_number' => 'BG123456789', 'manager_name' => 'Иван Измислен',
            'address' => 'София, ул. Измислена 1', 'email' => 'office@example.test', 'phone' => '02 123 4567',
        ])->assertRedirect('/company')->assertSessionHasNoErrors();

        $company = $this->company->fresh();
        $this->assertSame(['123456789', 'София, ул. Измислена 1', '02 123 4567'], [$company->eik, $company->address, $company->phone]);
        $this->get('/company')->assertSee('02 123 4567')->assertSee('office@example.test');
        $entry = AuditLog::query()->where('auditable_type', 'companies')->where('auditable_id', $this->company->id)->latest('id')->first();
        $this->assertSame('updated', $entry->event);
        $this->assertSame($this->company->id, $entry->company_id);
        $this->assertSame($this->admin->id, $entry->user_id);
    }

    public function test_company_validation(): void
    {
        $this->put('/company', ['name' => '', 'eik' => '12345', 'email' => 'не-е-имейл'])
            ->assertSessionHasErrors(['name', 'eik', 'email']);
    }

    public function test_bank_accounts_iban_is_normalized_validated_and_unique(): void
    {
        $account = $this->account('bg80 bnbg 9661 1020 3456 78', ['bic' => 'BNBGBGSD']);
        $this->assertSame(self::IBAN_A, $account->iban);
        $this->assertSame($this->company->id, $account->company_id);

        $this->post('/company/bank-accounts', ['bank_name' => 'Х', 'iban' => 'BG00XXXX00000000000000'])->assertSessionHasErrors('iban');
        $this->post('/company/bank-accounts', ['bank_name' => 'Х', 'iban' => self::IBAN_A])->assertSessionHasErrors('iban');

        // IBAN на фирмата не е таен: вижда се целият.
        $this->get('/company')->assertSee('BG80 BNBG 9661 1020 3456 78')->assertSee('BNBGBGSD');
    }

    public function test_only_one_account_is_for_statements(): void
    {
        $a = $this->account(self::IBAN_A, ['for_statements' => '1']);
        $b = $this->account(self::IBAN_B);
        $this->assertTrue($a->fresh()->for_statements);
        $this->assertSame($a->id, $this->company->fresh()->statementAccount()?->id);

        $this->post("/company/bank-accounts/{$b->id}/for-statements")->assertRedirect('/company');
        $this->assertFalse($a->fresh()->for_statements);
        $this->assertTrue($b->fresh()->for_statements);
        $this->get('/company')->assertSeeInOrder(['BG18 RZBB', __('company.for_statements')]);

        // Архивираната сметка не е „за известията“.
        $this->post("/company/bank-accounts/{$b->id}/archive")->assertRedirect('/company');
        $this->assertFalse($b->fresh()->for_statements);
        $this->assertNull($this->company->fresh()->statementAccount());
        $this->get('/company')->assertSee(__('company.no_statement_account'));
    }

    public function test_bank_account_can_be_edited_and_changes_are_audited(): void
    {
        $account = $this->account(self::IBAN_A);
        $this->put("/company/bank-accounts/{$account->id}", ['bank_name' => 'Друга измислена банка', 'iban' => self::IBAN_A, 'bic' => ''])
            ->assertRedirect('/company');

        $this->assertSame('Друга измислена банка', $account->fresh()->bank_name);
        $this->assertSame(['created', 'updated'], AuditLog::query()->where('auditable_type', 'bank_accounts')->where('auditable_id', $account->id)->orderBy('id')->pluck('event')->all());
    }

    public function test_staff_who_is_not_company_admin_cannot_see_or_change_it(): void
    {
        $account = $this->account(self::IBAN_A);
        auth()->logout();
        $staff = $this->staff();
        $this->actingAs($staff);

        $this->get('/company')->assertForbidden();
        $this->put('/company', ['name' => 'Х', 'eik' => '123456789'])->assertForbidden();
        $this->post('/company/bank-accounts', ['iban' => self::IBAN_B])->assertForbidden();
        $this->post("/company/bank-accounts/{$account->id}/for-statements")->assertForbidden();

        // И в модела, не само на екрана.
        $this->expectException(AuthorizationException::class);
        $account->update(['bank_name' => 'Х']);
    }

    public function test_menu_links_the_company_name_only_for_the_admin(): void
    {
        $this->get('/properties')->assertSee('<a class="firm" href="/company">Тест Алфа ООД</a>', false);

        auth()->logout();
        $this->actingAs($this->staff());
        $this->get('/properties')->assertDontSee('href="/company"', false)->assertSee('Тест Алфа ООД');
    }

    public function test_company_b_does_not_see_the_accounts_of_company_a(): void
    {
        $account = $this->account(self::IBAN_A);
        auth()->logout();
        $foreign = $this->staff(['is_company_admin' => true], $this->otherCompany);
        $this->assertSame($this->otherCompany->id, $foreign->company_id);
        $this->actingAs($foreign);

        $this->get('/company')->assertOk()->assertSee('Тест Бета ООД')->assertDontSee('Тест Алфа ООД')->assertDontSee('BG80 BNBG');
        $this->put("/company/bank-accounts/{$account->id}", ['bank_name' => 'Х', 'iban' => self::IBAN_A])->assertNotFound();
        $this->post("/company/bank-accounts/{$account->id}/for-statements")->assertNotFound();
        $this->assertSame(0, BankAccount::query()->count());
        $this->assertNull(Company::query()->find($this->company->id));
    }
}
