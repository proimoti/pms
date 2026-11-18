<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsSafely;
use App\Models\Company;
use App\Models\PropertyType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Създава фирма и готовия ѝ списък с видове имоти. Пуска се на сървъра
 * (PROJECT.md, т. 2 „Създаване на фирма и служител“).
 */
#[Signature('pms:create-company')]
#[Description('Създава фирма с готовите видове имоти')]
class CreateCompany extends Command
{
    use InteractsSafely;

    public function handle(): int
    {
        $name = trim((string) $this->ask('Име на фирмата'));
        $eik = trim((string) $this->ask('ЕИК'));

        // Командата върви без влязъл потребител, затова вижда всички фирми.
        if ($this->failsValidation(['name' => $name, 'eik' => $eik], [
            'name' => ['required', 'string', 'max:255'],
            'eik' => ['required', 'regex:/^(\d{9}|\d{13})$/', 'unique:companies,eik'],
        ], ['eik.regex' => 'ЕИК трябва да е 9 или 13 цифри.'])) {
            return self::FAILURE;
        }

        $data = [
            'name' => $name,
            'eik' => $eik,
            'vat_number' => $this->askOptional('ДДС номер (по избор)'),
            'manager_name' => $this->askOptional('МОЛ (по избор)'),
            'address' => $this->askOptional('Адрес (по избор)'),
            'email' => $this->askOptional('Имейл (по избор)'),
        ];

        if ($this->failsValidation($data, [
            'vat_number' => ['nullable', 'string', 'max:15'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
        ])) {
            return self::FAILURE;
        }

        $company = DB::transaction(function () use ($data) {
            $company = Company::query()->create($data);

            foreach (PropertyType::DEFAULTS as $position => $typeName) {
                PropertyType::query()->make(['name' => $typeName, 'sort_order' => $position + 1])
                    ->forceFill(['company_id' => $company->id])
                    ->save();
            }

            return $company;
        });

        $this->info("Фирмата „{$company->name}“ е създадена (номер {$company->id}) с ".count(PropertyType::DEFAULTS).' вида имоти.');

        return self::SUCCESS;
    }
}
