<?php

namespace Tests\Feature\Payments;

use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Enums\OwnerContractType;
use App\Models\Fund;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\OpeningImport;
use App\Models\PeriodLock;
use App\Models\Property;
use App\Models\Statement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Charges\Issuer;
use App\Services\Charges\MonthWorksheet;
use App\Services\Export\ExportBatches;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Services\Payments\PaymentService;
use App\Services\Utilities\FundService;
use App\Support\Xlsx\XlsxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Screens\ScreenTestCase;

/**
 * Начални салда (PROJECT.md, „Начални салда“, 26.11.2026): внос на два файла
 * .xlsx от Микроинвест (колони „Номер“, „Дебит“, „Кредит“; заглавният ред се
 * търси сам; общите редове се пропускат) и салдата на фондовете на същия
 * екран. 418/договор/имот: дебит = дължи, кредит = надплатено. 491: кредит =
 * внесен депозит (брои се за начислен и внесен). Фонд: парите във фонда (може и
 * минус). Преглед преди запис, всичко или нищо, веднъж за фирма; датата е преди
 * първия издаден месец. Сторно на целия внос — с код от телефона и причина.
 * Само администраторът на фирмата (в „Фирма“). Никога в експорта.
 *
 * Пример: договор А за № 501 и № 502, договор Б за № 503; фонд „Вход“.
 * „Днес“ е 10.12.2026. Само измислени данни.
 */
class OpeningBalancesTest extends ScreenTestCase
{
    private User $admin;

    private LeaseContract $a;

    private LeaseContract $b;

    private Fund $fund;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-12-10 12:00:00'));
        $this->admin = $this->staff(['is_company_admin' => true]);
        [$p1, $p2, $p3] = [$this->record(Property::class, ['number' => 501]), $this->record(Property::class, ['number' => 502]), $this->record(Property::class, ['number' => 503])];
        $boris = $this->record(Tenant::class, ['name' => 'Борис Измислен']);
        $anna = $this->record(Tenant::class, ['name' => 'Анна Измислена']);
        $this->actingAs($this->admin);

        app(OwnerContractService::class)->create(['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01',
            'lines' => [['property_id' => $p1->id], ['property_id' => $p2->id], ['property_id' => $p3->id]]]);
        $leases = app(LeaseContractService::class);
        $this->a = $leases->create(['tenant_id' => $boris->id, 'signed_on' => '2026-06-01', 'lines' => [
            ['property_id' => $p1->id, 'rent' => '300'], ['property_id' => $p2->id, 'rent' => '200']]]);
        $this->b = $leases->create(['tenant_id' => $anna->id, 'signed_on' => '2026-06-01', 'lines' => [['property_id' => $p3->id, 'rent' => '400']]]);
        $this->fund = app(FundService::class)->create(['name' => 'Вход (измислен)', 'properties' => [$p1->id, $p2->id]]);
    }

    /** Файл като оборотната ведомост: два реда заглавие, после „Номер / Име / Дебит / Кредит“. */
    private function file(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pms-opening-').'.xlsx';
        (new XlsxWriter)->write($path, array_merge([['Оборотна ведомост (измислена)'], [], ['Номер', 'Име', 'Дебит', 'Кредит']], $rows));

        return new UploadedFile($path, 'vedomost.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function tenantFile(): UploadedFile
    {
        return $this->file([
            ['418', 'Разчети с наематели', 420, 50],
            ['418/'.$this->a->number, 'Договор А', 300, 50],
            ['418/'.$this->a->number.'/501', '', 300, 0],
            ['418/'.$this->a->number.'/502', '', 0, 50],
            ['418/'.$this->b->number.'/503', '', 120, 0],
        ]);
    }

    private function depositFile(): UploadedFile
    {
        return $this->file([
            ['491', 'Депозити', 0, 1500],
            ['491/'.$this->a->number.'/501', '', 0, 1000],
            ['491/'.$this->b->number.'/503', '', 25, 500], // дебитът се пренебрегва
        ]);
    }

    private function preview(array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/company/opening/preview', $override + [
            'as_of' => '30.11.2026', 'tenants' => $this->tenantFile(), 'deposits' => $this->depositFile(),
            'funds' => [$this->fund->id => '120'],
        ]);
    }

    private function import(): void
    {
        $this->preview()->assertOk()->assertSessionHasNoErrors();
        $this->post('/company/opening/confirm')->assertRedirect('/company/opening');
    }

    private function code(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->two_factor_secret));
    }

    private function owes(LeaseContract $lease): float
    {
        return (float) LedgerEntry::query()->where('account', LedgerAccount::Tenant->value)->where('lease_contract_id', $lease->id)->sum('amount');
    }

    public function test_screen_is_in_company_settings_for_the_admin(): void
    {
        $this->get('/company')->assertSee('href="/company/opening"', false);
        $this->get('/company/opening')->assertOk()->assertSee('name="tenants"', false)->assertSee('name="deposits"', false)
            ->assertSee('Вход (измислен)')->assertSee('name="funds['.$this->fund->id.']"', false);
        $this->get('/properties')->assertDontSee('href="/company/opening"', false);
    }

    public function test_preview_shows_rows_totals_and_saves_everything(): void
    {
        $this->preview()->assertOk()
            ->assertSeeInOrder(['418/'.$this->a->number.'/501', 'Борис Измислен', '№ 501', '300,00'])
            ->assertSee('418/'.$this->b->number.'/503')->assertSee('Анна Измислена')
            ->assertSee(__('opening.totals.debit').': 420,00')->assertSee(__('opening.totals.credit').': 50,00')
            ->assertSee(__('opening.totals.deposits').': 1 500,00')->assertSee(__('opening.totals.funds').': 120,00')
            ->assertDontSee('418/'.$this->a->number.'</td>', false) // общият ред е пропуснат
            ->assertSee(__('opening.confirm'));
        $this->assertSame(0, LedgerEntry::query()->count() - LedgerEntry::query()->where('kind', '<>', LedgerKind::Opening->value)->count());

        $this->post('/company/opening/confirm')->assertRedirect('/company/opening')->assertSessionHas('status');

        $import = OpeningImport::query()->sole();
        $this->assertSame(['2026-11-30', $this->admin->id], [$import->as_of->format('Y-m-d'), $import->created_by]);
        $this->assertSame([250.0, 120.0], [$this->owes($this->a), $this->owes($this->b)]);
        $this->assertSame(1500.0, (float) LedgerEntry::query()->where('account', LedgerAccount::Deposit->value)->sum('amount'));
        $this->assertSame('2026-11-01', LedgerEntry::query()->where('kind', LedgerKind::Opening->value)->first()->month->format('Y-m-d'));

        // Депозитът е начислен и внесен; фондът има 120 €.
        $html = $this->get("/tenants/{$this->a->tenant_id}")->getContent();
        $this->assertMatchesRegularExpression('#<td class="num paid">\s*1 000,00\s*</td>#', substr($html, strpos($html, 'id="deposits"')));
        $this->get("/funds/{$this->fund->id}")->assertSee('120,00');
        $this->get("/lease-contracts/{$this->a->id}")->assertSeeInOrder([__('dossiers.owes_on_contract'), '250,00']);
    }

    public function test_opening_deposit_counts_as_paid_and_is_not_owed(): void
    {
        $this->import();

        // „Депозити“: начислен и внесен — пълната сума по двата договора.
        foreach ([[$this->a, '1 000,00'], [$this->b, '500,00']] as [$lease, $amount]) {
            $html = $this->get("/tenants/{$lease->tenant_id}")->getContent();
            $deposits = substr($html, strpos($html, 'id="deposits"'));
            $this->assertMatchesRegularExpression('#<td class="num">\s*'.$amount.'\s*</td>\s*<td class="num paid">\s*'.$amount.'\s*</td>#', $deposits);
        }

        // Наемателят не дължи депозита: няма неплатен ред „депозит“, а ново плащане не отива за депозит.
        $allocation = app(\App\Services\Payments\Allocation::class);
        foreach ([$this->a, $this->b] as $lease) {
            $open = array_filter($allocation->forLease($this->company->id, $lease->id)['debts'],
                fn ($d) => $d['type'] === \App\Enums\ChargeType::Deposit->value && $d['paid'] < $d['amount']);
            $this->assertSame([], $open);
        }
        $this->assertSame(100000, $allocation->depositPaid($this->company->id, $this->a->id));
        $this->assertSame(50000, $allocation->depositPaid($this->company->id, $this->b->id));
    }

    public function test_fund_balance_may_be_negative(): void
    {
        $this->preview(['funds' => [$this->fund->id => '-30,50']])->assertOk();
        $this->post('/company/opening/confirm');

        $this->assertSame(30.5, (float) LedgerEntry::query()->where('fund_id', $this->fund->id)->sum('amount'));
    }

    public function test_errors_are_shown_and_nothing_is_saved(): void
    {
        $bad = $this->file([
            ['418/9999/501', '', 10, 0],                          // няма такъв договор
            ['418/'.$this->a->number.'/503', '', 10, 0],          // имотът не е по договора
            ['491/'.$this->a->number.'/501', '', 0, 10],          // друга сметка в този файл
            ['418/'.$this->a->number.'/501', '', 5, 0],
            ['418/'.$this->a->number.'/501', '', 5, 0],           // повтаря се
            ['418/'.$this->b->number.'/503', '', 'сто', 0],       // сумата не е число
        ]);

        $html = $this->preview(['tenants' => $bad])->assertOk()->getContent();
        foreach (['contract_not_found', 'property_not_in_contract', 'wrong_account', 'duplicate', 'amount'] as $error) {
            $this->assertStringContainsString(__('opening.errors.'.$error), $html, $error);
        }
        $this->assertStringContainsString('class="row-error"', $html);
        $this->assertStringNotContainsString(__('opening.confirm'), $html);

        $this->post('/company/opening/confirm')->assertSessionHasErrors('file');
        $this->assertSame(0, OpeningImport::query()->count());
        $this->assertSame(0, LedgerEntry::query()->where('kind', LedgerKind::Opening->value)->count());
    }

    public function test_file_without_the_header_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pms-opening-').'.xlsx';
        (new XlsxWriter)->write($path, [['Нещо друго'], ['418/1/1', 5, 0]]);

        $this->preview(['tenants' => new UploadedFile($path, 'x.xlsx', null, null, true)])->assertSessionHasErrors('tenants');
    }

    public function test_date_must_be_before_the_first_issued_month(): void
    {
        app(MonthWorksheet::class)->generate('2026-12');
        app(Issuer::class)->issueMonth('2026-12');

        $this->preview(['as_of' => '31.12.2026'])->assertSessionHasErrors('as_of');
        $this->preview(['as_of' => '01.12.2026'])->assertSessionHasErrors('as_of');
        $this->preview(['as_of' => '30.11.2026'])->assertOk()->assertSessionHasNoErrors();
    }

    public function test_only_once_per_company(): void
    {
        $this->import();

        $this->get('/company/opening')->assertSee(__('opening.done', ['date' => '30.11.2026']))->assertDontSee('name="tenants"', false);
        $this->preview()->assertSessionHasErrors('as_of');
        $this->assertSame(1, OpeningImport::query()->count());
    }

    public function test_opening_debt_is_the_oldest_for_payments(): void
    {
        $this->import();
        app(MonthWorksheet::class)->generate('2026-12');
        app(Issuer::class)->issueMonth('2026-12');
        $december = Statement::query()->where('lease_contract_id', $this->a->id)->sole();

        app(PaymentService::class)->record($this->a, ['amount' => '250', 'paid_on' => '05.12.2026', 'method' => 'cash']);
        $this->get('/statements?month=2026-12')->assertSee(__('payments.status.unpaid'));

        app(PaymentService::class)->record($this->a, ['amount' => '100', 'paid_on' => '06.12.2026', 'method' => 'cash']);
        $this->get("/statements/{$december->id}")->assertSee(__('payments.status.partly', ['paid' => '100,00', 'amount' => '500,00']));
    }

    public function test_opening_balances_never_go_into_an_export_batch(): void
    {
        $this->import();
        $opening = LedgerEntry::query()->where('kind', LedgerKind::Opening->value)->count();
        $this->assertGreaterThan(0, $opening);

        $this->assertSame(0, app(ExportBatches::class)->pending(CarbonImmutable::parse('2026-11-01'))->count());
    }

    public function test_reversal_needs_the_phone_code_and_a_reason(): void
    {
        $this->import();
        $this->get('/company/opening')->assertSee(__('opening.reverse.warning'))->assertSee('name="code"', false);

        $this->post('/company/opening/reverse', ['code' => '', 'reason' => 'Грешен файл (измислено)'])->assertSessionHasErrors('code');
        $this->post('/company/opening/reverse', ['code' => '000000', 'reason' => 'Грешен файл (измислено)'])->assertSessionHasErrors('code');
        $this->post('/company/opening/reverse', ['code' => $this->code($this->admin), 'reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame([250.0, 120.0], [$this->owes($this->a), $this->owes($this->b)]);

        $this->travel(1)->minutes(); // нов код
        $this->post('/company/opening/reverse', ['code' => $this->code($this->admin), 'reason' => 'Грешен файл (измислено)'])
            ->assertRedirect('/company/opening')->assertSessionHasNoErrors();

        $this->assertSame([0.0, 0.0], [$this->owes($this->a), $this->owes($this->b)]);
        $this->assertSame(0.0, (float) LedgerEntry::query()->where('account', LedgerAccount::Deposit->value)->sum('amount'));
        $this->assertSame(0.0, (float) LedgerEntry::query()->where('fund_id', $this->fund->id)->sum('amount'));
        $this->assertSame('Грешен файл (измислено)', OpeningImport::query()->whereNotNull('reversal_of_id')->sole()->reason);

        // След сторното може нов внос.
        $this->import();
        $this->assertSame(250.0, $this->owes($this->a));
    }

    public function test_only_the_company_admin(): void
    {
        auth()->logout();
        $this->actingAs($this->staff());

        $this->get('/company/opening')->assertForbidden();
        $this->preview()->assertForbidden();
        $this->post('/company/opening/confirm')->assertForbidden();
        $this->post('/company/opening/reverse', ['code' => '123456', 'reason' => 'Х'])->assertForbidden();
    }

    public function test_company_b_does_not_find_the_contracts_of_company_a(): void
    {
        $this->import();
        auth()->logout();
        $this->actingAs($this->staff(['is_company_admin' => true], $this->otherCompany));

        $this->get('/company/opening')->assertOk()->assertSee('name="tenants"', false)->assertDontSee('Вход (измислен)');
        $this->preview(['funds' => []])->assertOk()->assertSee(__('opening.errors.contract_not_found'));
        $this->assertSame(1, OpeningImport::query()->withoutGlobalScopes()->count());
    }
}
