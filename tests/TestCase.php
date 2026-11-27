<?php

namespace Tests;

use App\Models\Company;
use App\Models\CompanyDomain;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Адресът на тестовете (localhost) е адресът на фирмата, а без фирма — на
     * администратора на приложението (27.11.2026: фирмата се определя по адреса).
     */
    protected function onAddressOf(?Company $company): void
    {
        if ($company === null) {
            config(['pms.admin_host' => 'localhost']);

            return;
        }
        CompanyDomain::query()->withoutGlobalScopes()->where('host', 'localhost')->delete();
        (new CompanyDomain)->forceFill(['company_id' => $company->id, 'host' => 'localhost'])->save();
    }
}
