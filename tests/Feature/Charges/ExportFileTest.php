<?php

namespace Tests\Feature\Charges;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\ExportBatch;
use App\Models\LeaseContract;
use App\Models\OwnerContract;
use App\Models\PaidUtilityBill;
use App\Models\Property;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Charges\Issuer;
use App\Services\Charges\Ledger;
use App\Services\Charges\MonthWorksheet;
use App\Services\Export\ExportBatches;
use App\Services\Export\ExportSettings;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Services\Utilities\UtilityAccountService;
use App\Services\Utilities\UtilityDecisions;
use App\Services\Utilities\UtilityImporter;
use Carbon\CarbonImmutable;
use Tests\Feature\Screens\ScreenTestCase;
use Tests\Support\DossierScenario;
use ZipArchive;

/**
 * Файлът за Микроинвест и сверката (PROJECT.md, „Експорт към Микроинвест“,
 * 26.11.2026): лист „Операции“ с колони A–O от ред 2 и лист „Настройка
 * Операции“ като в шаблона (B3 = 2, B4 = последният ред с данни). Двойките
 * „дебит-кредит“ се сглобяват от записите в книгата; преди файла сборът по
 * всяка сметка във файла трябва да е равен на сбора в книгата.
 *
 * Пример (януари 2027 е издаден на 10.01.2027):
 * - № 101: Представител 10 %, Борис, наем 500 → 418→408 500 и 408→703/1 50; такса вход 15 → 418→406/1;
 * - № 102: „Собствен“, Христо, наем 400 → 418→417/1;
 * - № 103: Представител фиксиран 460, свободен → 408→703/2 −460; сметка 33 € → за сметка на управителя;
 * - общ абонат № 0099999 на № 101 и № 102: сметка 60 € → 418→407/0099999 по 30, платено 407/0099999→503/3.
 * Само измислени данни и номера на сметки.
 */
class ExportFileTest extends ScreenTestCase
{
    use DossierScenario;

    private User $user;

    private Property $fixed;

    private LeaseContract $ownLease;

    private OwnerContract $fixedContract;

    private ExportBatch $january;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00'));
        $this->user = $this->staff(['is_company_admin' => true]);
        $this->buildDossierScenario($this->user);

        $this->fixed = Property::factory()->create(['company_id' => $this->company->id, 'number' => 103]);
        $this->fixedContract = app(OwnerContractService::class)->create(['type' => OwnerContractType::RepresentativeFixed, 'owner_id' => $this->owner->id,
            'signed_on' => '2026-01-01', 'payment_method' => PaymentMethod::Cash, 'lines' => [['property_id' => $this->fixed->id, 'fixed_amount' => '460']]]);
        auth()->logout();
        $hristo = Tenant::factory()->create(['company_id' => $this->company->id, 'name' => 'Христо Измислен']);
        $this->actingAs($this->user);
        $this->ownLease = app(LeaseContractService::class)->create(['tenant_id' => $hristo->id, 'signed_on' => '2026-12-01',
            'lines' => [['property_id' => $this->empty->id, 'rent' => '400']]]);

        $provider = Provider::query()->sole();
        $accounts = app(UtilityAccountService::class);
        $accounts->create(['provider_id' => $provider->id, 'number' => '0099999', 'kind' => 'electricity', 'billing' => 'actual', 'valid_from' => '2026-12-01',
            'properties' => [['property_id' => $this->flat->id, 'share' => '50'], ['property_id' => $this->empty->id, 'share' => '50']]]);
        $accounts->create(['provider_id' => $provider->id, 'number' => '0055555', 'kind' => 'electricity', 'billing' => 'actual', 'valid_from' => '2026-12-01',
            'properties' => [['property_id' => $this->fixed->id]]]);
        app(UtilityImporter::class)->import($this->easypayFile([
            ['ref' => 990101, 'date' => '2027-01-05 10:00:00', 'number' => 99999, 'provider' => 'IZM EL', 'amount' => 60.00],
            ['ref' => 990102, 'date' => '2027-01-06 10:00:00', 'number' => 55555, 'provider' => 'IZM EL', 'amount' => 33.00],
        ]), 'jan.xlsx');
        app(UtilityDecisions::class)->toManager(PaidUtilityBill::query()->where('reference', '990102')->sole(), $this->fixed->id, 'Свободен имот (измислено)');

        app(MonthWorksheet::class)->generate('2027-01');
        app(Issuer::class)->issueMonth('2027-01');

        $this->settings();
        $this->january = app(ExportBatches::class)->create(CarbonImmutable::parse('2027-01-01'));
    }

    private function settings(array $override = []): void
    {
        app(ExportSettings::class)->save($override + [
            'tenant' => '418', 'deposit' => '491', 'owner' => '408', 'utility' => '407', 'fund' => '406',
            'revenue_representative_percent' => '703/1', 'revenue_representative_fixed' => '703/2', 'revenue_sublease_individual' => '703/3',
            'revenue_sublease_legal' => '703/4', 'revenue_own' => '417/1', 'revenue_penalty' => '703/9', 'revenue_other' => '709',
            'manager_cost' => '609/2', 'include_paid_bills' => '1', 'bank_epay' => '503/2', 'bank_easypay' => '503/3', 'kind_electricity' => '1', 'kind_common' => '9',
        ]);
    }

    /** Клетките на лист от изтегления файл: ref => [type, style, value]. */
    private function download(ExportBatch $batch): array
    {
        $response = $this->get("/export/batches/{$batch->id}/download")->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('mikroinvest-paket-'.$batch->number.'.xlsx', (string) $response->headers->get('content-disposition'));

        return $this->parse($response->baseResponse->getFile()->getPathname());
    }

    private function parse(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $strings = [];
        foreach (simplexml_load_string($zip->getFromName('xl/sharedStrings.xml'))->si as $si) {
            $strings[] = (string) $si->t;
        }
        $styles = simplexml_load_string($zip->getFromName('xl/styles.xml'));
        $formats = [];
        foreach ($styles->cellXfs->xf as $xf) {
            $formats[] = (int) $xf['numFmtId'];
        }
        $book = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $sheets = [];
        foreach ($book->sheets->sheet as $i => $sheet) {
            $sheets[] = (string) $sheet['name'];
        }
        $read = function (string $file) use ($zip, $strings, $formats) {
            $cells = [];
            foreach (simplexml_load_string($zip->getFromName($file))->sheetData->row as $row) {
                foreach ($row->c as $c) {
                    $type = (string) $c['t'] ?: 'n';
                    $value = (string) $c->v;
                    $cells[(string) $c['r']] = [$type, $formats[(int) $c['s']] ?? 0, $type === 's' ? $strings[(int) $value] : $value];
                }
            }

            return $cells;
        };
        $result = ['sheets' => $sheets, 'operations' => $read('xl/worksheets/sheet1.xml'), 'settings' => $read('xl/worksheets/sheet2.xml')];
        $zip->close();

        return $result;
    }

    /** Редовете на лист „Операции“ като [сума, сметки, основание, документ]. */
    private function rows(array $file): array
    {
        $rows = [];
        for ($r = 2; isset($file['operations']['A'.$r]); $r++) {
            $rows[] = [$file['operations']['A'.$r][2], $file['operations']['B'.$r][2], $file['operations']['J'.$r][2], $file['operations']['G'.$r][2] ?? ''];
        }

        return $rows;
    }

    private function assertRow(array $rows, string $accounts, string $amount, ?string $basis = null): void
    {
        $found = array_values(array_filter($rows, fn ($row) => $row[1] === $accounts && (float) $row[0] === (float) $amount));
        $this->assertNotEmpty($found, "Няма ред {$accounts} за {$amount}. Има: ".json_encode(array_map(fn ($r) => $r[1].' '.$r[0], $rows), JSON_UNESCAPED_UNICODE));
        if ($basis !== null) {
            $this->assertSame($basis, $found[0][2], $accounts);
        }
    }

    public function test_rows_are_built_from_the_ledger_by_contract_type(): void
    {
        $rows = $this->rows($this->download($this->january));
        $m = $this->management->number;
        $statement = \App\Models\Statement::query()->where('lease_contract_id', $this->lease->id)->whereDate('month', '2027-01-01')->sole();

        // Представител, %: наемът 418 → 408, приходът 408 → 703/1.
        $this->assertRow($rows, "418/{$this->lease->number}/101-408/{$m}/101", '500', 'Наем');
        $this->assertRow($rows, "408/{$m}/101-703/1", '50', 'Наем');
        $rent = array_values(array_filter($rows, fn ($row) => $row[1] === "418/{$this->lease->number}/101-408/{$m}/101"))[0];
        $this->assertSame((string) $statement->number, $rent[3]);
        // „Собствен“: направо в сметката от настройките, без 408.
        $this->assertRow($rows, "418/{$this->ownLease->number}/102-417/1", '400', 'Наем');
        $this->assertEmpty(array_filter($rows, fn ($row) => str_contains($row[1], '408/') && str_contains($row[1], '/102')));
        // Фиксиран, свободен имот: приходът е с минус.
        $this->assertRow($rows, "408/{$this->fixedContract->number}/103-703/2", '-460', 'Наем');
    }

    public function test_utilities_funds_paid_bills_and_manager_cost(): void
    {
        $rows = $this->rows($this->download($this->january));

        // Общ абонат: една аналитика 407/абонатен номер и за начисленията, и за плащането.
        $this->assertRow($rows, "418/{$this->lease->number}/101-407/0099999", '30', __('utilities.kinds.electricity'));
        $this->assertRow($rows, "418/{$this->ownLease->number}/102-407/0099999", '30', __('utilities.kinds.electricity'));
        $this->assertRow($rows, '407/0099999-503/3', '60', __('utilities.kinds.electricity'));
        // Абонат с един имот: 407/вид/имот; за сметка на управителя.
        $this->assertRow($rows, '407/1/103-503/3', '33', __('utilities.kinds.electricity'));
        $this->assertRow($rows, '609/2-407/1/103', '33', __('utilities.kinds.electricity'));
        // Фиксирана такса към фонда: 418 → 406/аналитиката на фонда, основанието е видът услуга.
        $this->assertRow($rows, "418/{$this->lease->number}/101-406/1", '15', __('utilities.kinds.common'));
    }

    public function test_paid_bills_are_left_out_when_the_setting_is_off(): void
    {
        $this->settings(['include_paid_bills' => '0']);
        $rows = $this->rows($this->download($this->january));

        $this->assertEmpty(array_filter($rows, fn ($row) => str_contains($row[1], '-503/')));
        $this->assertRow($rows, "418/{$this->lease->number}/101-407/0099999", '30');
    }

    public function test_columns_types_and_formats(): void
    {
        $file = $this->download($this->january);
        $ops = $file['operations'];

        $this->assertSame(['Операции', 'Настройка Операции'], $file['sheets']);
        $this->assertSame(['Сума', 'Сметки', 'За месец', 'Партньор', 'Булстат/ЕИК', 'ДДС номер', 'Документ', 'Тип', 'Дата на документа', 'Основание',
            'Забележка', 'Втора забележка', 'Вид сделка', 'VIES', 'Месец за експорт'], array_map(fn ($col) => $ops[$col.'1'][2], range('A', 'O')));
        $this->assertArrayNotHasKey('P1', $ops);

        // A: число с формат „€“; C, I, O: дата 01.01.2027 (формат 14); H: „МО“; M, N: текст „1“; D, E, F, K, L: празни.
        $this->assertSame('n', $ops['A2'][0]);
        $this->assertSame(164, $ops['A2'][1]);
        foreach (['C2', 'I2', 'O2'] as $ref) {
            $this->assertSame(['n', 14, '46388'], $ops[$ref], $ref);
        }
        $this->assertSame(['s', 0, 'МО'], $ops['H2']);
        $this->assertSame(['s', 0, '1'], $ops['M2']);
        $this->assertSame(['s', 0, '1'], $ops['N2']);
        foreach (['D2', 'E2', 'F2', 'K2', 'L2', 'P2'] as $ref) {
            $this->assertTrue(! isset($ops[$ref]) || $ops[$ref][2] === '', $ref);
        }
        // Без IBAN и без ЕГН във файла.
        $this->assertStringNotContainsString('BG18', json_encode($file, JSON_UNESCAPED_UNICODE));
    }

    public function test_settings_sheet_is_like_the_template_with_the_last_row(): void
    {
        $file = $this->download($this->january);
        $settings = $file['settings'];
        $last = count($this->rows($file)) + 1;

        $this->assertSame(['n', 0, '2'], $settings['B3']);
        $this->assertSame(['n', 0, (string) $last], $settings['B4']);
        $expected = ['A1' => 'Настройка', 'C1' => 'Описание', 'A2' => 'Sheet с данни', 'B2' => 'Операции', 'A3' => 'Начален ред', 'A4' => 'Краен ред',
            'A5' => 'Колона за суми', 'B5' => 'A', 'A6' => 'Колона за сметки', 'B6' => 'B', 'A7' => 'Колона за дата', 'B7' => 'C',
            'A8' => 'Колона на партньор', 'B8' => 'D', 'A9' => 'Колона за Булстат\ЕИК', 'B9' => 'E', 'A10' => 'Колона за ДДС номер', 'B10' => 'F',
            'A11' => 'Колона за номер на док.', 'B11' => 'G', 'A12' => 'IBAN', 'B12' => 'P', 'A13' => 'Колона за вид док.', 'B13' => 'H',
            'A14' => 'Колона за дата на док.', 'B14' => 'I', 'A15' => 'Колона за основание', 'B15' => 'J', 'A16' => 'Колона за забележка', 'B16' => 'K',
            'A17' => 'Колона за втора забележка', 'B17' => 'L', 'A18' => 'Колона за вид сделка', 'B18' => 'M', 'A19' => 'Колона за VIES', 'B19' => 'N',
            'A20' => 'Колона за месец за експорт', 'B20' => 'O'];
        foreach ($expected as $ref => $value) {
            $this->assertSame($value, $settings[$ref][2], $ref);
        }
        $this->assertStringStartsWith('Настоящият файл служи като пример за импорт на операции', $settings['C2'][2]);
    }

    public function test_the_last_row_follows_the_number_of_rows(): void
    {
        $december = app(ExportBatches::class)->create(CarbonImmutable::parse('2026-12-01'));
        $file = $this->download($december);

        $this->assertSame((string) (count($this->rows($file)) + 1), $file['settings']['B4'][2]);
        $this->assertNotSame($this->download($this->january)['settings']['B4'][2], $file['settings']['B4'][2]);
    }

    public function test_the_sums_match_the_ledger_and_a_mismatch_stops_the_file(): void
    {
        $this->get("/export/batches/{$this->january->id}")->assertOk()
            ->assertSee(__('export.file.check_ok'))->assertSee('418')->assertSee('408')->assertSee('407')->assertSee('406');

        // Запис по 407 без двойката си (не може да се сглоби) → файлът не се дава.
        Ledger::book(CarbonImmutable::parse('2027-01-01'), LedgerKind::Issue, ['account' => LedgerAccount::Utility, 'type' => ChargeType::Utility,
            'property_id' => $this->flat->id], -1000);
        $broken = app(ExportBatches::class)->create(CarbonImmutable::parse('2027-01-01'));

        $this->get("/export/batches/{$broken->id}/download")->assertRedirect("/export/batches/{$broken->id}")
            ->assertSessionHas('warning');
        $this->get("/export/batches/{$broken->id}")->assertSee(__('export.file.check_failed'))->assertSee('407')->assertSee('-10,00');
    }

    public function test_the_same_batch_gives_the_same_file(): void
    {
        $this->assertSame($this->rows($this->download($this->january)), $this->rows($this->download($this->january)));
    }

    public function test_only_the_manager_and_only_the_own_company(): void
    {
        auth()->logout();
        $this->actingAs($this->readOnlyStaff());
        $this->get("/export/batches/{$this->january->id}/download")->assertForbidden();

        auth()->logout();
        $this->actingAs($this->staff([], $this->otherCompany));
        $this->get("/export/batches/{$this->january->id}/download")->assertNotFound();
        $this->get("/export/batches/{$this->january->id}")->assertNotFound();
    }
}
