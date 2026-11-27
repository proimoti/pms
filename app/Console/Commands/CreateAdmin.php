<?php

namespace App\Console\Commands;

use App\Enums\AccessLevel;
use App\Enums\PropertyScope;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Създава администратор на приложението (без фирма). Пуска се на сървъра.
 *
 * Паролата се въвежда скрито и никога не е аргумент на командата,
 * за да не остане в историята на терминала. При първия вход
 * администраторът трябва да настрои втория фактор.
 */
#[Signature('pms:create-admin')]
#[Description('Създава администратор на приложението')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        // Командата върви без влязъл потребител, затова вижда всички фирми.
        if (User::query()->where('role', Role::AppAdmin)->exists()
            && ! $this->confirm('Вече има администратор на приложението. Да се създаде ли още един?')) {
            $this->info('Нищо не е създадено.');

            return self::FAILURE;
        }

        $email = trim((string) $this->ask('Имейл'));

        if ($this->fails(['email' => $email], ['email' => ['required', 'email', \Illuminate\Validation\Rule::unique('users', 'email')->whereNull('company_id')]])) {
            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Име', 'Администратор'));
        $password = (string) $this->secret('Парола (поне 12 знака, не се вижда)');
        $confirmation = (string) $this->secret('Повторете паролата');

        if ($this->fails(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', Password::defaults(), 'confirmed']],
        )) {
            return self::FAILURE;
        }

        $admin = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $admin->forceFill([
            'role' => Role::AppAdmin,
            'company_id' => null,
            'access_level' => AccessLevel::Write,
            'property_scope' => PropertyScope::All,
        ])->save();

        $this->info("Администраторът {$email} е създаден. При първия вход ще настрои втория фактор.");

        return self::SUCCESS;
    }

    /** Показва грешките (без въведените стойности) и връща true, ако има такива. */
    private function fails(array $data, array $rules): bool
    {
        $validator = Validator::make($data, $rules);

        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return $validator->fails();
    }
}
