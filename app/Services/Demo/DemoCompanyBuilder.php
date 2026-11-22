<?php

namespace App\Services\Demo;

use App\Enums\AccessLevel;
use App\Enums\ChargeType;
use App\Enums\OwnerContractType;
use App\Enums\PartyKind;
use App\Enums\PaymentMethod;
use App\Enums\PropertyScope;
use App\Enums\Role;
use App\Models\Beneficiary;
use App\Models\Broker;
use App\Models\Company;
use App\Models\LeaseContract;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\UpcomingCharge;
use App\Models\User;
use App\Services\Charges\Month;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Services\Utilities\UtilityAccountService;
use App\Support\Xlsx\XlsxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Нова измислена демо фирма (командата pms:demo-data). Само измислени данни:
 * имена с „Измислен“, улица „ул. Измислена“, ЕИК и IBAN без истински
 * собственик, имейли @example.test. Създава само нови записи на новата фирма.
 *
 * Месец М е следващият (този, който „Начисления“ отваря по подразбиране).
 * Договорите започват 6 месеца преди него, а трудните случаи са в М.
 */
class DemoCompanyBuilder
{
    private Company $company;

    private CarbonImmutable $month;

    private CarbonImmutable $since;

    /** @var array<int, Property> */
    private array $properties = [];

    public function __construct(
        private readonly OwnerContractService $managements,
        private readonly LeaseContractService $leases,
        private readonly UtilityAccountService $utilityAccounts,
    ) {}

    /** @return array{company: Company, month: string, cases: list<string>, files: list<string>} */
    public function build(string $email, string $name, string $password): array
    {
        $this->month = CarbonImmutable::now()->startOfMonth()->addMonth();
        $this->since = $this->month->subMonths(6);

        return DB::transaction(function () use ($email, $name, $password) {
            $this->company = $this->company();
            $this->staff($email, $name, $password);
            $brokers = $this->brokers();
            $this->properties($brokers);
            $owners = $this->owners();
            $tenants = $this->tenants();
            $beneficiaries = $this->beneficiaries();

            $this->contracts($owners, $tenants, $beneficiaries);
            $files = $this->utilities();

            return ['company' => $this->company, 'month' => Month::label($this->month), 'cases' => $this->cases(), 'files' => $files];
        });
    }

    private function company(): Company
    {
        $number = Company::query()->where('name', 'like', 'Демо Имоти %')->count() + 1;
        do {
            $eik = '9'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        } while (Company::query()->where('eik', $eik)->exists());

        $company = Company::query()->create([
            'name' => "Демо Имоти {$number} ООД",
            'eik' => $eik,
            'address' => 'гр. София, ул. Измислена 1',
            'email' => "office-{$number}@example.test",
        ]);

        foreach (PropertyType::DEFAULTS as $position => $typeName) {
            PropertyType::query()->make(['name' => $typeName, 'sort_order' => $position + 1])
                ->forceFill(['company_id' => $company->id])->save();
        }

        return $company;
    }

    /** Администратор на фирмата, „четене и запис“, „всички имоти“. */
    private function staff(string $email, string $name, string $password): void
    {
        $staff = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $staff->forceFill([
            'company_id' => $this->company->id,
            'role' => Role::Staff,
            'is_company_admin' => true,
            'access_level' => AccessLevel::Write,
            'property_scope' => PropertyScope::All,
            'can_view_sensitive' => false,
        ])->save();
    }

    /** @return list<Broker> */
    private function brokers(): array
    {
        $names = ['Иво Измислен', 'Мира Измислена', 'Дани Измислен', 'Лора Измислена', 'Боян Измислен',
            'Нели Измислена', 'Пламен Измислен', 'Рая Измислена', 'Сашо Измислен', 'Теди Измислена'];

        return array_map(fn (string $name, int $i) => $this->save(new Broker, [
            'name' => $name, 'phone' => '0888 000 '.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
            'email' => 'broker-'.($i + 1).'@example.test',
        ]), $names, array_keys($names));
    }

    /** 15 имота (№ 2 е гараж). */
    private function properties(array $brokers): void
    {
        $types = PropertyType::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->pluck('id', 'name');

        for ($i = 1; $i <= 15; $i++) {
            $this->properties[$i] = $this->save(new Property, [
                'property_type_id' => $types[$i === 2 ? 'Гараж' : 'Апартамент'],
                'broker_id' => $brokers[($i - 1) % count($brokers)]->id,
                'city' => 'София', 'district' => 'Измислен квартал', 'street' => 'ул. Измислена',
                'street_number' => (string) $i, 'floor' => $i === 2 ? null : (string) (($i % 6) + 1),
                'apartment' => $i === 2 ? null : (string) $i,
                'note' => $i === 2 ? 'Гараж към апартамент № 1' : null,
            ]);
        }
    }

    /** @return array<string, Owner> */
    private function owners(): array
    {
        $owners = [];
        foreach ([
            'percent' => ['Георги Измислен', PartyKind::Individual],
            'fixed' => ['Стефка Измислена', PartyKind::Individual],
            'sublease_individual' => ['Петко Измислен', PartyKind::Individual],
            'sublease_legal' => ['Тест Делта ООД', PartyKind::Legal],
            'fixed_from_10' => ['Ваня Измислена', PartyKind::Individual],
            'partial' => ['Камен Измислен', PartyKind::Individual],
            'extra_1' => ['Нина Измислена', PartyKind::Individual],
            'extra_2' => ['Тест Гама ЕООД', PartyKind::Legal],
            'extra_3' => ['Огнян Измислен', PartyKind::Individual],
            'extra_4' => ['Цвета Измислена', PartyKind::Individual],
        ] as $key => [$name, $kind]) {
            $owners[$key] = $this->party(new Owner, $name, $kind);
        }

        return $owners;
    }

    /** @return list<Tenant> */
    private function tenants(): array
    {
        $names = ['Петър Измислен', 'Мария Измислена', 'Иван Измислен', 'Елена Измислена', 'Тест Сигма ООД',
            'Христо Измислен', 'Даря Измислена', 'Тест Омега ЕООД', 'Калин Измислен', 'Зоя Измислена', 'Любо Измислен'];

        return array_map(fn (string $name) => $this->party(new Tenant, $name,
            str_starts_with($name, 'Тест ') ? PartyKind::Legal : PartyKind::Individual), $names);
    }

    /** Бенефициенти, различни от собствениците (с измислен IBAN с вярна контролна сума). */
    private function beneficiaries(): array
    {
        $beneficiaries = [];
        foreach (['Тест Бенефициент ЕООД', 'Мила Измислена', 'Тодор Измислен'] as $i => $name) {
            $beneficiaries[] = $this->save(new Beneficiary, ['name' => $name, 'iban' => $this->iban($i + 1)]);
        }

        return $beneficiaries;
    }

    private function contracts(array $owners, array $tenants, array $beneficiaries): void
    {
        $m = $this->month;
        $p = fn (int $i) => $this->properties[$i]->id;

        // Представител, %: 7 имота (договор с повече от 5 имота); анекс с нов процент 12% от 16-о число.
        $percent = $this->management(OwnerContractType::RepresentativePercent, $owners['percent'],
            ['commission_percent' => '10', 'payment_method' => PaymentMethod::Cash],
            array_map(fn ($i) => ['property_id' => $p($i)], range(1, 7)));
        $annex = $this->managements->addAnnex($percent, 1, $m->subDays(5), 'Нов процент от средата на месеца');
        $this->managements->changeCommission($annex, $m->addDays(15), '12');

        // Представител, фиксиран: имот 8 нает, имот 9 свободен; по банка без IBAN (предупреждение).
        $this->management(OwnerContractType::RepresentativeFixed, $owners['fixed'], ['payment_method' => PaymentMethod::Bank],
            [['property_id' => $p(8), 'fixed_amount' => '460'], ['property_id' => $p(9), 'fixed_amount' => '380']]);

        // Преотдаване, физическо и юридическо лице; при юридическото бенефициентът е друг (по банка, с IBAN).
        $this->management(OwnerContractType::SubleaseIndividual, $owners['sublease_individual'], ['payment_method' => PaymentMethod::Cash],
            [['property_id' => $p(10), 'fixed_amount' => '500']]);
        $this->management(OwnerContractType::SubleaseLegal, $owners['sublease_legal'],
            ['payment_method' => PaymentMethod::Bank, 'beneficiary_id' => $beneficiaries[0]->id],
            [['property_id' => $p(11), 'fixed_amount' => '700']]);

        // Собствен (имотът е на фирмата).
        $this->management(OwnerContractType::Own, null, [], [['property_id' => $p(12)]]);

        // Фиксирана сума от 10-о число на М (по дни); имотът е свободен.
        $this->management(OwnerContractType::RepresentativeFixed, $owners['fixed_from_10'], ['payment_method' => PaymentMethod::Cash],
            [['property_id' => $p(14), 'fixed_amount' => '460']], $m->addDays(9));

        // Договор за управление само за част от М (прекратен на 16-о), а наемът продължава: „за решение“.
        $partial = $this->management(OwnerContractType::RepresentativePercent, $owners['partial'],
            ['commission_percent' => '10', 'payment_method' => PaymentMethod::Cash], [['property_id' => $p(15)]]);
        $partial->update(['terminated_on' => $m->addDays(15)]);

        // Договори за наем.
        $this->lease($tenants[0], [['property_id' => $p(1), 'rent' => '700', 'deposit' => '1400'],
            ['property_id' => $p(2), 'rent' => '0']]); // гараж с наем 0

        $withAnnex = $this->lease($tenants[1], [['property_id' => $p(3), 'rent' => '600', 'deposit' => '1200']]);
        $this->leases->changeRent($this->leases->addAnnex($withAnnex, 1, $m->subDays(5), 'Нов наем от 11-о число'),
            $p(3), $m->addDays(10), '650');
        $this->upcoming($withAnnex, $p(3), ChargeType::Penalty, $m, '50', 'Закъснение с плащането');

        // Наемател от 16-о число, с договорен наем за първия период и депозит на три вноски.
        $fromMiddle = $this->lease($tenants[2], [['property_id' => $p(4), 'rent' => '600', 'deposit' => '1200', 'first_period_rent' => '300']],
            $m->addDays(15));
        foreach ([0, 1, 2] as $i) {
            $this->upcoming($fromMiddle, $p(4), ChargeType::Deposit, $m->addMonths($i), '400', 'Вноска '.($i + 1).' от депозита');
        }

        // Прекратяване на 15-о число.
        $this->lease($tenants[3], [['property_id' => $p(5), 'rent' => '550', 'deposit' => '1100']])
            ->update(['terminated_on' => $m->addDays(14)]);

        // Един наемател с два договора за наем: две отделни известия.
        $this->lease($tenants[4], [['property_id' => $p(6), 'rent' => '500']]);
        $this->lease($tenants[4], [['property_id' => $p(7), 'rent' => '450']]);

        $this->lease($tenants[5], [['property_id' => $p(8), 'rent' => '520', 'deposit' => '1040']]);
        $this->lease($tenants[6], [['property_id' => $p(10), 'rent' => '650']]);
        $this->lease($tenants[7], [['property_id' => $p(11), 'rent' => '900', 'deposit' => '1800']]);
        $this->lease($tenants[8], [['property_id' => $p(12), 'rent' => '400']]);
        // Имот с наем без договор за управление: „за решение“.
        $this->lease($tenants[9], [['property_id' => $p(13), 'rent' => '350']]);
        $this->lease($tenants[10], [['property_id' => $p(15), 'rent' => '480']]);
    }

    /**
     * Битови сметки (PROJECT.md, т. 3.4 и т. 5.3): доставчици с имената им във
     * файловете, абонати (номер с нули отпред, общ 50/50 и 33,33/33,33/33,34, фиксиран)
     * и два измислени файла за внос, платени в месеца преди М. Файловете са в
     * папката demo/{фирма}/ на локалното хранилище (storage/app/private).
     *
     * @return list<string> пътищата на файловете
     */
    private function utilities(): array
    {
        $p = fn (int $i) => $this->properties[$i]->id;
        $provider = fn (string $name, ?string $easypay, ?string $epay) => $this->save(new Provider, ['name' => $name, 'easypay_name' => $easypay, 'epay_name' => $epay]);
        $power = $provider('Измислено Електро', 'IZMISLENO ELEKTRO AD', 'Измислено Електро АД');
        $water = $provider('Измислена Вода', 'IZMISLENA VODA EAD', 'Измислена Вода ЕАД');
        $heating = $provider('Измислена Топлофикация', 'IZMISLENA TOPLOFIKACIA', 'Измислена Топлофикация ЕАД');
        $provider('Входна такса (измислена)', null, null);

        $account = fn (?Provider $by, ?string $number, string $kind, array $lines, array $extra = []) => $this->utilityAccounts->create(array_merge([
            'company_id' => $this->company->id, 'provider_id' => $by?->id, 'number' => $number, 'kind' => $kind,
            'billing' => 'actual', 'valid_from' => $this->since->toDateString(), 'properties' => $lines,
        ], $extra));

        // Ток по имот: номерът е с две нули отпред, а EasyPay го дава като число.
        foreach ([1, 3, 4, 5, 8, 10] as $i) {
            $account($power, '00'.(10000 + $i), 'electricity', [['property_id' => $p($i)]]);
        }
        // Общ абонат за вода за двата имота на един наемател, 50/50.
        $account($water, '5000123', 'water', [['property_id' => $p(6), 'share' => '50'], ['property_id' => $p(7), 'share' => '50']]);
        $account($water, '0300012', 'water', [['property_id' => $p(12)]]);
        // Общо парно 33,33/33,33/33,34 (сборът е точно 100 %); стотинките отиват към последния имот.
        $account($heating, '2000045678', 'heating', [
            ['property_id' => $p(8), 'share' => '33.33'], ['property_id' => $p(10), 'share' => '33.33'], ['property_id' => $p(11), 'share' => '33.34'],
        ]);
        // Фиксиран абонат (такса вход): без номер, 15 € на месец, по дни като наема.
        $account(null, null, 'common', [['property_id' => $p(1)]], ['billing' => 'fixed', 'fixed_amount' => '15']);

        return $this->importFiles();
    }

    /** Двата измислени файла за внос (EasyPay и ePay) с плащания в месеца преди М. */
    private function importFiles(): array
    {
        $paid = $this->month->subMonth();
        $at = fn (int $day, string $time) => $paid->addDays($day - 1)->setTimeFromTimeString($time);
        $nbsp = "\u{00A0}";
        $base = 700000 + $this->company->id * 100;

        $easypay = [array_map(fn ($title) => $nbsp.$title.$nbsp, ['Номер на превода', 'Дата', 'Кл.рег.', 'Абонатен номер', 'Търговец', 'Сума'])];
        foreach ([1 => 48.20, 3 => 61.35, 4 => 22.10, 5 => 39.90, 8 => 55.00, 10 => 44.44] as $i => $amount) {
            $easypay[] = [$base + $i, $at(5, '10:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)), 4401, 10000 + $i, 'IZMISLENO ELEKTRO AD', $amount];
        }
        $easypay[] = [$base + 20, $at(6, '09:00'), 4401, 5000123, 'IZMISLENA VODA EAD', 35.51];  // общ 50/50
        $easypay[] = [$base + 21, $at(6, '09:05'), 4401, 99999, 'IZMISLENO ELEKTRO AD', 18.00]; // непознат номер
        $easypay[] = [$base + 1, $at(5, '10:01'), 4401, 10001, 'IZMISLENO ELEKTRO AD', 48.20];  // повторен ред

        $code = fn (int $n) => '90'.str_pad((string) ($this->company->id * 1000 + $n), 14, '0', STR_PAD_LEFT);
        $date = fn (int $day, string $time) => $at($day, $time)->format('d.m.Y H:i:s');
        $epay = [
            ['Извлечение по сметка ePay (измислено)'],
            ['Дата и час', 'Сметка', 'Приход', 'Разход', 'Кореспондент', 'Системен код', 'Описание', 'Подробно описание'],
            [$date(7, '11:20'), 'EPAY-DEMO', null, '121.21', 'Измислена Топлофикация ЕАД', $code(1), '127(2000045678)',
                "Измислена Топлофикация ЕАД\nАбонат 2000045678\nПериод: предходният месец"],
            [$date(7, '11:25'), 'EPAY-DEMO', null, '0.60', 'Измислена Банка', $code(2), 'Такса банков превод', null],
            [$date(8, '12:00'), 'EPAY-DEMO', null, '27.80', 'Измислена Вода ЕАД', $code(3), '127(0300012)', "Измислена Вода ЕАД\nАбонат 0300012"],
            [$date(8, '12:05'), 'EPAY-DEMO', null, '0.60', 'Измислена Банка', $code(4), 'Такса банков превод', null],
            [$date(9, '08:30'), 'EPAY-DEMO', '650.00', null, 'Измислен Наемател', $code(5), 'ДОГ. НАЕМ 3', null],
            [$date(9, '09:00'), 'EPAY-DEMO', null, '12.00', 'Непознат Доставчик ООД', $code(6), '127(777001)', null],
        ];

        $folder = "demo/{$this->company->id}";
        Storage::disk('local')->makeDirectory($folder);
        $paths = [];
        foreach (['easypay-demo.xlsx' => $easypay, 'epay-demo.xlsx' => $epay] as $name => $rows) {
            $path = Storage::disk('local')->path("{$folder}/{$name}");
            (new XlsxWriter)->write($path, $rows);
            $paths[] = $path;
        }

        return $paths;
    }

    /** @return list<string> какво да се пробва (за съобщението на командата) */
    private function cases(): array
    {
        $n = fn (int $i) => '№ '.$this->properties[$i]->number;

        return [
            'Петте типа договори за управление; договорът „Представител, %“ е със 7 имота и анекс с нов процент 12% от 16-о число',
            "Гараж с наем 0: {$n(2)}",
            "Анекс с нов наем 650 € от 11-о число: {$n(3)}",
            "Наемател от 16-о число с договорен наем за първия период и депозит на 3 вноски: {$n(4)}",
            "Прекратяване на 15-о число: {$n(5)}",
            "Един наемател с два договора за наем: {$n(6)} и {$n(7)}",
            "Свободен имот с фиксирана сума: {$n(9)}; фиксирана сума от 10-о число: {$n(14)}",
            "Бенефициент, различен от собственика: {$n(11)}; плащане по банка без IBAN: {$n(8)} и {$n(9)}",
            "„За решение“: без договор за управление {$n(13)}; договорът за управление покрива само част от месеца {$n(15)}",
            "Битови сметки: общ абонат за вода 50/50 ({$n(6)} и {$n(7)}), общо парно 33,33/33,33/33,34 ({$n(8)}, {$n(10)}, {$n(11)}), фиксирана такса вход 15 € ({$n(1)}); файловете за внос са по-долу",
        ];
    }

    private function management(OwnerContractType $type, ?Owner $owner, array $extra, array $lines, ?CarbonImmutable $from = null): OwnerContract
    {
        return $this->managements->create(array_merge([
            'company_id' => $this->company->id,
            'type' => $type,
            'owner_id' => $owner?->id,
            'signed_on' => ($from ?? $this->since)->toDateString(),
            'lines' => $lines,
        ], $extra));
    }

    private function lease(Tenant $tenant, array $lines, ?CarbonImmutable $from = null): LeaseContract
    {
        return $this->leases->create([
            'company_id' => $this->company->id,
            'tenant_id' => $tenant->id,
            'signed_on' => ($from ?? $this->since)->toDateString(),
            'lines' => $lines,
        ]);
    }

    private function upcoming(LeaseContract $lease, int $propertyId, ChargeType $type, CarbonImmutable $month, string $amount, string $note): void
    {
        $this->save(new UpcomingCharge, [
            'lease_contract_id' => $lease->id, 'property_id' => $propertyId, 'type' => $type,
            'month' => $month->startOfMonth(), 'amount' => $amount, 'note' => $note,
        ]);
    }

    private function party(Owner|Tenant $party, string $name, PartyKind $kind): Owner|Tenant
    {
        static $phone = 0;
        $phone++;

        return $this->save($party, [
            'kind' => $kind, 'name' => $name, 'country' => 'BG', 'city' => 'София', 'street' => 'ул. Измислена',
            'street_number' => (string) $phone, 'phone' => '0888 100 '.str_pad((string) $phone, 3, '0', STR_PAD_LEFT),
            'email' => 'party-'.$this->company->id.'-'.$phone.'@example.test',
            'eik' => $kind === PartyKind::Legal ? '8'.str_pad((string) ($this->company->id * 100 + $phone), 8, '0', STR_PAD_LEFT) : null,
            'manager_name' => $kind === PartyKind::Legal ? 'Управител Измислен' : null,
        ]);
    }

    /** Записва нов запис в демо фирмата. */
    private function save($model, array $attributes)
    {
        $model->forceFill($attributes + ['company_id' => $this->company->id])->save();

        return $model;
    }

    /** Измислен български IBAN с банка „DEMO“ и вярна контролна сума. */
    private function iban(int $account): string
    {
        $bban = 'DEMO'.'0000'.'10'.str_pad((string) ($this->company->id * 1000 + $account), 8, '0', STR_PAD_LEFT);
        $digits = '';
        foreach (str_split($bban.'BG00') as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return 'BG'.str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT).$bban;
    }
}
