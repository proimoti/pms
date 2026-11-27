<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsSafely;
use App\Enums\AccessLevel;
use App\Enums\PropertyScope;
use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Създава служител във фирма. Пуска се на сървъра
 * (PROJECT.md, т. 2 „Създаване на фирма и служител“).
 *
 * Паролата се въвежда скрито и никога не е аргумент на командата.
 * При първия вход служителят трябва да настрои втория фактор.
 */
#[Signature('pms:create-staff')]
#[Description('Създава служител във фирма')]
class CreateStaff extends Command
{
    use InteractsSafely;

    private const ACCESS = ['само четене' => AccessLevel::Read, 'четене и запис' => AccessLevel::Write];

    private const SCOPE = ['всички имоти' => PropertyScope::All, 'само прикачените имоти' => PropertyScope::Assigned];

    public function handle(): int
    {
        // Командата върви без влязъл потребител, затова вижда всички фирми.
        $company = Company::query()->where('eik', trim((string) $this->ask('ЕИК на фирмата')))->first();

        if (! $company) {
            $this->error('Няма фирма с този ЕИК.');

            return self::FAILURE;
        }

        if (! $company->is_active) {
            $this->warn('Внимание: фирмата е неактивна и служителят няма да може да влиза, докато не се активира.');
        }

        $email = trim((string) $this->ask('Имейл'));

        if ($this->failsValidation(['email' => $email], ['email' => ['required', 'email', \Illuminate\Validation\Rule::unique('users', 'email')->where('company_id', $company->id)]])) {
            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Име'));

        if ($this->failsValidation(['name' => $name], ['name' => ['required', 'string', 'max:255']])) {
            return self::FAILURE;
        }

        $password = $this->askNewPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $isCompanyAdmin = $this->confirm('Администратор на фирмата ли е?', false);
        // По подразбиране: само четене. Обхват „всички имоти“, докато няма екран
        // за прикачване на имоти (PROJECT.md, т. 2 „Регистър, уточнения“).
        $access = $this->choice('Права', array_keys(self::ACCESS), 'само четене');
        $scope = $this->choice('Обхват', array_keys(self::SCOPE), 'всички имоти');
        $canViewSensitive = $this->confirm('Да вижда ли ЕГН и IBAN?', false);

        $staff = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $staff->forceFill([
            'company_id' => $company->id,
            'role' => Role::Staff,
            'is_company_admin' => $isCompanyAdmin,
            'access_level' => self::ACCESS[$access],
            'property_scope' => self::SCOPE[$scope],
            'can_view_sensitive' => $canViewSensitive,
        ])->save();

        $this->info("Служителят {$email} е създаден във „{$company->name}“. При първия вход ще настрои втория фактор.");

        return self::SUCCESS;
    }
}
