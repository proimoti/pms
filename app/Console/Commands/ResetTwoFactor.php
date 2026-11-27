<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * Нулира втория фактор на потребител, изгубил телефона и резервните кодове.
 * Ползва се само от доставчика, на сървъра (PROJECT.md, т. 2).
 * При следващия вход потребителят трябва да го настрои отново.
 */
#[Signature('pms:reset-two-factor {email : Имейл на потребителя} {--company= : ЕИК на фирмата, ако имейлът е в няколко фирми} {--force : Без въпрос за потвърждение}')]
#[Description('Нулира втория фактор на потребител')]
class ResetTwoFactor extends Command
{
    public function handle(DisableTwoFactorAuthentication $disable): int
    {
        $email = (string) $this->argument('email');

        // Командата върви без влязъл потребител, затова търси във всички фирми.
        // Имейлът е уникален в рамките на фирмата (27.11.2026): при няколко профила се иска ЕИК.
        $eik = $this->option('company');
        $users = User::query()->where('email', $email)
            ->when($eik !== null, fn ($q) => $q->whereHas('company', fn ($c) => $c->where('eik', trim((string) $eik))))->get();

        if ($users->isEmpty()) {
            $this->error('Няма потребител с този имейл.');

            return self::FAILURE;
        }
        if ($users->count() > 1) {
            $this->error('Имейлът е в няколко фирми. Кажете фирмата с --company=ЕИК.');

            return self::FAILURE;
        }
        $user = $users->first();

        if (! $this->option('force') && ! $this->confirm("Да се нулира ли вторият фактор на {$user->email}?")) {
            $this->info('Нищо не е променено.');

            return self::FAILURE;
        }

        $disable($user);

        $this->info('Вторият фактор е нулиран. При следващия вход потребителят ще го настрои отново.');

        return self::SUCCESS;
    }
}
