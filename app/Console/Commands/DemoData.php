<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsSafely;
use App\Models\User;
use App\Services\Demo\DemoCompanyBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Нова измислена демо фирма с данни за тестване: регистър, договорите с
 * трудните случаи и служител-администратор. Само измислени данни. Не трие и не
 * пипа нищо съществуващо; всяко пускане е нова фирма. Работи само с
 * ALLOW_DEMO_DATA=true в .env (само на тестов сървър).
 */
#[Signature('pms:demo-data')]
#[Description('Създава нова измислена демо фирма с данни за тестване')]
class DemoData extends Command
{
    use InteractsSafely;

    public function handle(DemoCompanyBuilder $builder): int
    {
        if (! config('app.allow_demo_data')) {
            $this->error('Демо данните не са разрешени. Сложете ALLOW_DEMO_DATA=true в .env (само на тестов сървър).');

            return self::FAILURE;
        }

        $email = trim((string) $this->ask('Имейл на служителя', $this->suggestedEmail()));
        if ($this->failsValidation(['email' => $email], ['email' => ['required', 'email', 'unique:users,email']])) {
            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Име на служителя', 'Демо Служител'));
        if ($this->failsValidation(['name' => $name], ['name' => ['required', 'string', 'max:255']])) {
            return self::FAILURE;
        }

        $password = $this->askNewPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $result = $builder->build($email, $name, $password);

        $this->info("Създадена е фирма „{$result['company']->name}“ (номер {$result['company']->id}).");
        $this->info("Вход: {$email}. При първия вход служителят настройва втория фактор.");
        $this->line('Месец за пробите: '.$result['month'].'. Случаи:');
        foreach ($result['cases'] as $case) {
            $this->line('  - '.$case);
        }

        return self::SUCCESS;
    }

    /** demo-N@example.test, свободен имейл (на него не идват писма). */
    private function suggestedEmail(): string
    {
        $number = 1;
        while (User::query()->where('email', "demo-{$number}@example.test")->exists()) {
            $number++;
        }

        return "demo-{$number}@example.test";
    }
}
