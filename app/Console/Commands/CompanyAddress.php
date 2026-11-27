<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyDomain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Адресите на фирма (PROJECT.md, „Портал и служители“, 27.11.2026): домейнът или
 * поддомейнът, на който влизат потребителите на фирмата. Без аргументи — списък.
 * Пуска се на сървъра от доставчика.
 */
#[Signature('pms:company-address {eik? : ЕИК на фирмата} {host? : Адресът, например pms.proimoti.bg} {--remove : Маха адреса}')]
#[Description('Показва, добавя или маха адрес (домейн) на фирма')]
class CompanyAddress extends Command
{
    public function handle(): int
    {
        if ($this->argument('eik') === null) {
            $rows = CompanyDomain::query()->withoutGlobalScopes()->with('company')->orderBy('host')->get()
                ->map(fn ($d) => [$d->host, $d->company?->name, $d->company?->eik])->all();
            $this->table(['Адрес', 'Фирма', 'ЕИК'], $rows);
            $this->line('Адресът на администратора на приложението (PMS_ADMIN_HOST): '.(config('pms.admin_host') ?: 'не е зададен'));

            return self::SUCCESS;
        }

        // Командата върви без влязъл потребител, затова вижда всички фирми.
        $company = Company::query()->where('eik', trim((string) $this->argument('eik')))->first();
        if (! $company) {
            $this->error('Няма фирма с този ЕИК.');

            return self::FAILURE;
        }
        $host = $this->host((string) $this->argument('host'));
        if ($host === null) {
            $this->error('Адресът не е като pms.proimoti.bg.');

            return self::FAILURE;
        }

        $existing = CompanyDomain::query()->withoutGlobalScopes()->with('company')->where('host', $host)->first();
        if ($this->option('remove')) {
            if ($existing === null || $existing->company_id !== $company->id) {
                $this->error("Фирмата няма адрес {$host}.");

                return self::FAILURE;
            }
            if (! $this->confirm("Да се махне ли адресът {$host} от „{$company->name}“? Потребителите на фирмата няма да могат да влизат на него.")) {
                $this->info('Нищо не е променено.');

                return self::FAILURE;
            }
            $existing->delete();
            $this->info("Адресът {$host} е махнат.");

            return self::SUCCESS;
        }

        if (config('pms.admin_host') && CompanyDomain::normalize((string) config('pms.admin_host')) === $host) {
            $this->error('Това е адресът на администратора на приложението.');

            return self::FAILURE;
        }
        if ($existing !== null) {
            $this->error("Адресът {$host} вече е на „{$existing->company?->name}“.");

            return self::FAILURE;
        }
        (new CompanyDomain)->forceFill(['company_id' => $company->id, 'host' => $host])->save();
        $this->info("Адресът {$host} е добавен към „{$company->name}“.");

        return self::SUCCESS;
    }

    /** Само името на адреса: без http://, порт и път; малки букви. */
    private function host(string $value): ?string
    {
        $value = preg_replace('#^[a-z]+://#i', '', trim($value));
        $value = CompanyDomain::normalize(preg_replace('#[:/].*$#', '', $value));

        return preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $value) ? $value : null;
    }
}
