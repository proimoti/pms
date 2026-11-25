<?php

namespace Tests\Feature\Charges;

use App\Models\BankAccount;
use App\Models\Property;
use App\Models\Provider;
use App\Models\Statement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserProperty;
use App\Services\Charges\Issuer;
use App\Services\Charges\MonthWorksheet;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Services\Statements\StatementPdf;
use App\Services\Utilities\UtilityImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Screens\ScreenTestCase;
use Tests\Support\DossierScenario;

/**
 * PDF на известието (PROJECT.md, „PDF и имейл на известията“, 24.11.2026):
 * dompdf; при издаване известието запомня данните на фирмата, наемателя и
 * банковата сметка, а PDF се прави при поискване от тях, без пазене на файлове.
 * ЕИК се показва само при наемател-фирма; ЕГН и IBAN на хора — никога. BIC е
 * по избор и не се показва, когато е празен.
 *
 * Пример: януари 2027 е издаден; Борис има наем 500 € и сметка за ток от ePay
 * с „Подробно описание“. „Днес“ е 10.01.2027. Само измислени данни.
 */
class StatementPdfTest extends ScreenTestCase
{
    use DossierScenario;

    private const IBAN = 'BG80BNBG96611020345678';

    private User $user;

    private Statement $statement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00'));
        $this->user = $this->staff(['is_company_admin' => true]);
        $this->buildDossierScenario($this->user);

        $this->company->update(['name' => 'Тест Алфа ООД', 'eik' => '123456789', 'vat_number' => 'BG123456789', 'address' => 'София, ул. Измислена 1',
            'email' => 'office@example.test', 'phone' => '02 123 4567']);
        $bank = BankAccount::query()->create(['bank_name' => 'Измислена банка', 'iban' => self::IBAN, 'bic' => 'BNBGBGSD']);
        $bank->forceFill(['for_statements' => true])->save();

        Provider::query()->sole()->update(['epay_name' => 'Измислено Електро АД']);
        app(UtilityImporter::class)->import($this->epayFile([[
            'date' => '05.01.2027 10:15:00', 'out' => '48,20', 'provider' => 'Измислено Електро АД',
            'code' => '1234567890123456', 'description' => '127(0012345)', 'details' => 'Период 01.12–31.12.2026; 350 кВтч (измислено)',
        ]]), 'epay.xlsx');
        app(MonthWorksheet::class)->generate('2027-01');
        app(Issuer::class)->issueMonth('2027-01');
        $this->statement = Statement::query()->where('lease_contract_id', $this->lease->id)->whereDate('month', '2027-01-01')->sole();
    }

    private function html(?Statement $statement = null): string
    {
        return app(StatementPdf::class)->html($statement ?? $this->statement->fresh());
    }

    public function test_issuing_remembers_company_tenant_and_bank(): void
    {
        $snapshot = $this->statement->snapshot;

        $this->assertSame('Тест Алфа ООД', $snapshot['company']['name']);
        $this->assertSame('123456789', $snapshot['company']['eik']);
        $this->assertSame('Борис Измислен', $snapshot['tenant']['name']);
        $this->assertSame(self::IBAN, $snapshot['bank']['iban']);
        $this->assertSame('BNBGBGSD', $snapshot['bank']['bic']);

        // По-късни промени не пипат издаденото известие.
        $this->company->update(['name' => 'Ново Име ООД', 'address' => 'Пловдив, ул. Друга 2']);
        $this->boris->update(['name' => 'Борис Преименуван']);
        BankAccount::query()->sole()->forceFill(['archived_at' => now(), 'for_statements' => false])->save();

        $html = $this->html();
        $this->assertStringContainsString('Тест Алфа ООД', $html);
        $this->assertStringNotContainsString('Ново Име ООД', $html);
        $this->assertStringContainsString('Борис Измислен', $html);
        $this->assertStringNotContainsString('Борис Преименуван', $html);
        $this->assertStringContainsString('BG80 BNBG 9661 1020 3456 78', $html);
    }

    public function test_document_content(): void
    {
        $html = $this->html();

        foreach (['Тест Алфа ООД', '123456789', 'BG123456789', 'София, ул. Измислена 1', 'office@example.test', '02 123 4567',
            __('charges.statements.notice').' № '.$this->statement->number, 'Януари 2027', '10.01.2027',
            'Борис Измислен', __('registry.one.lease-contracts').' № '.$this->lease->number,
            __('charges.types.rent'), '500,00', 'Измислено Електро', '№ 0012345', 'Период 01.12–31.12.2026; 350 кВтч (измислено)',
            \App\Support\Format::money($this->statement->total), 'Измислена банка', 'BG80 BNBG 9661 1020 3456 78', 'BNBGBGSD',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }

        // Вид на известието (PROJECT.md, 25.11.2026): „Имот: “, основание само по договора, без „Това не е фактура“, срок за плащане.
        $this->assertStringContainsString(__('statements.pdf.property_line', ['number' => 101]), $html);
        $this->assertStringContainsString(__('statements.pdf.reference_label').':</strong> '.__('statements.pdf.reference', ['contract' => $this->lease->number]), $html);
        $this->assertStringNotContainsString('Известие № '.$this->statement->number.', договор', $html);
        $this->assertStringNotContainsString('Това не е фактура', $html);
        $this->assertStringContainsString(__('statements.pdf.due_on').': 15.01.2027', $html);
        $this->assertLessThan(strpos($html, __('statements.pdf.due_on')), strpos($html, __('statements.pdf.issued_on')));
        // Без ЕГН и без IBAN на хора (бенефициентът на собственика е с измислен IBAN).
        $this->assertStringNotContainsString('BG18RZBB91550123456789', $html);
        $this->assertStringNotContainsString('ЕГН', $html);
        // Наемател физическо лице: без ЕИК при наемателя.
        $tenant = substr($html, strpos($html, 'id="tenant"'), 400);
        $this->assertStringNotContainsString(__('statements.pdf.eik'), $tenant);
    }

    public function test_bic_is_not_shown_when_empty(): void
    {
        DB::table('statements')->where('id', $this->statement->id)->update(['snapshot' => json_encode(
            array_replace_recursive($this->statement->snapshot, ['bank' => ['bic' => null]]), JSON_UNESCAPED_UNICODE)]);

        $html = $this->html();
        $this->assertStringContainsString('BG80 BNBG 9661 1020 3456 78', $html);
        $this->assertStringNotContainsString('BIC', $html);
        $this->assertStringNotContainsString('BNBGBGSD', $html);
    }

    public function test_without_a_statement_account_there_is_no_payment_account(): void
    {
        BankAccount::query()->sole()->forceFill(['for_statements' => false])->save();
        $lease = $this->leaseFor('Тест Фирма ЕООД', 'legal', '204567891');
        $statement = app(Issuer::class)->issueNow($lease, '2027-02');

        $html = $this->html($statement);
        $this->assertNull($statement->snapshot['bank']);
        $this->assertStringNotContainsString(__('statements.pdf.bank_account'), $html);
        // Наемател-фирма: с ЕИК.
        $tenant = substr($html, strpos($html, 'id="tenant"'), 500);
        $this->assertStringContainsString(__('statements.pdf.eik').' 204567891', $tenant);
        $this->get("/statements/{$statement->id}")->assertSee(__('statements.pdf.no_bank_warning'));
    }

    public function test_pdf_download_with_embedded_cyrillic_font(): void
    {
        $response = $this->get("/statements/{$this->statement->id}/pdf")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('izvestie-'.$this->statement->number.'.pdf', (string) $response->headers->get('content-disposition'));
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('DejaVuSans', $pdf);
        $this->get("/statements/{$this->statement->id}")->assertSee('href="/statements/'.$this->statement->id.'/pdf"', false);
    }

    public function test_migration_fills_old_statements_from_the_current_state(): void
    {
        DB::table('statements')->where('id', $this->statement->id)->update(['snapshot' => null]);
        $this->company->update(['name' => 'Сегашно Име ООД']);

        $migration = require database_path('migrations/2026_11_24_000005_add_snapshot_to_statements.php');
        $migration->backfill();

        $snapshot = $this->statement->fresh()->snapshot;
        $this->assertSame('Сегашно Име ООД', $snapshot['company']['name']);
        $this->assertSame('Борис Измислен', $snapshot['tenant']['name']);
        $this->assertSame(self::IBAN, $snapshot['bank']['iban']);
    }

    public function test_other_company_and_unassigned_staff_cannot_download(): void
    {
        auth()->logout();
        $this->actingAs($this->staff([], $this->otherCompany));
        $this->get("/statements/{$this->statement->id}/pdf")->assertNotFound();

        auth()->logout();
        $assigned = $this->assignedOnlyStaff();
        (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $assigned->id, 'property_id' => $this->empty->id])->save();
        $this->actingAs($assigned);
        $this->get("/statements/{$this->statement->id}/pdf")->assertNotFound();
    }

    // Срок за плащане (PROJECT.md, „Вид на известието“, 25.11.2026)

    public function test_due_date_is_the_5th_of_the_month_or_5_days_after_a_late_issue(): void
    {
        // Издадено на 10.01.2027 за януари (след 5-о число): 5 дни след издаването.
        $this->assertSame('2027-01-15', $this->statement->snapshot['due_on']);

        // Издадено преди месеца: 5-о число на месеца.
        $early = app(Issuer::class)->issueNow($this->leaseFor('Ранен Измислен', 'individual', ''), '2027-03');
        $this->assertSame('2027-03-05', $early->snapshot['due_on']);
        $this->assertStringContainsString(__('statements.pdf.due_on').': 05.03.2027', $this->html($early));
    }

    public function test_issued_on_the_5th_is_due_the_same_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-02-05 18:00:00'));
        $statement = app(Issuer::class)->issueNow($this->leaseFor('Точен Измислен', 'individual', ''), '2027-02');

        $this->assertSame('2027-02-05', $statement->snapshot['due_on']);
    }

    public function test_due_date_is_remembered_at_issue(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-04-20 12:00:00'));
        $this->assertStringContainsString(__('statements.pdf.due_on').': 15.01.2027', $this->html());
    }

    public function test_migration_fills_the_due_date_of_old_statements_by_the_same_rule(): void
    {
        $snapshot = $this->statement->snapshot;
        unset($snapshot['due_on']);
        DB::table('statements')->where('id', $this->statement->id)->update(['snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
        // Декември 2026 е издаден в подготовката също на 10.01.2027 — след 5-о число.
        $december = Statement::query()->where('lease_contract_id', $this->lease->id)->whereDate('month', '2026-12-01')->sole();
        DB::table('statements')->where('id', $december->id)->update(['issued_at' => '2026-11-23 09:00:00', 'snapshot' => json_encode(['company' => [], 'tenant' => [], 'bank' => null])]);

        $migration = require database_path('migrations/2026_11_25_000001_add_due_date_and_company_logo.php');
        $migration->backfill();

        $this->assertSame('2027-01-15', $this->statement->fresh()->snapshot['due_on']);
        $this->assertSame('2026-12-05', $december->fresh()->snapshot['due_on']);
    }

    // Лого (винаги текущото)

    public function test_current_company_logo_is_in_the_top_left_or_nothing(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->assertStringNotContainsString('<img', $this->html());

        $this->post('/company/logo', ['logo' => \Illuminate\Http\UploadedFile::fake()->image('logo.png', 200, 80)])->assertRedirect('/company');
        $html = $this->html();
        $this->assertMatchesRegularExpression('#id="company">\s*<img src="data:image/png;base64,[A-Za-z0-9+/=]+"#', $html);

        // Ново лого след издаването: показва се новото.
        $this->post('/company/logo', ['logo' => \Illuminate\Http\UploadedFile::fake()->image('logo.jpg', 100, 40)])->assertRedirect('/company');
        $this->assertStringContainsString('data:image/jpeg;base64,', $this->html());
        $this->assertStringContainsString('DejaVuSans', $this->get("/statements/{$this->statement->id}/pdf")->getContent());
    }

    private function leaseFor(string $name, string $kind, string $eik): \App\Models\LeaseContract
    {
        $property = Property::factory()->create(['company_id' => $this->company->id, 'number' => 130]);
        app(OwnerContractService::class)->create(['type' => \App\Enums\OwnerContractType::Own, 'signed_on' => '2026-01-01', 'lines' => [['property_id' => $property->id]]]);
        auth()->logout();
        $tenant = Tenant::factory()->create(['company_id' => $this->company->id, 'name' => $name, 'kind' => $kind, 'eik' => $eik ?: null]);
        $this->actingAs($this->user);

        return app(LeaseContractService::class)->create(['tenant_id' => $tenant->id, 'signed_on' => '2027-01-01', 'lines' => [['property_id' => $property->id, 'rent' => '300']]]);
    }
}

