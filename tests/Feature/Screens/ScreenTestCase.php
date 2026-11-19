<?php

namespace Tests\Feature\Screens;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Общо за тестовете на екраните.
 *
 * Данните и потребителите се създават преди „влизането“, с изрична фирма
 * (без влязъл потребител филтърът по фирма не пречи). Потребителите са с
 * настроен втори фактор, за да стигат до страниците.
 */
abstract class ScreenTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['name' => 'Тест Алфа ООД']);
        $this->otherCompany = Company::factory()->create(['name' => 'Тест Бета ООД']);
    }

    /** Служител с „четене и запис“ и „всички имоти“ (по подразбиране в първата фирма). */
    protected function staff(array $attributes = [], ?Company $company = null): User
    {
        return User::factory()
            ->withTwoFactor(app(Google2FA::class)->generateSecretKey())
            ->create(['company_id' => ($company ?? $this->company)->id] + $attributes);
    }

    protected function readOnlyStaff(): User
    {
        return $this->staff(['access_level' => 'read']);
    }

    protected function assignedOnlyStaff(): User
    {
        return $this->staff(['property_scope' => 'assigned']);
    }

    /** Запис от регистъра в дадена фирма (по подразбиране първата). */
    protected function record(string $class, array $attributes = [], ?Company $company = null)
    {
        return $class::factory()->create(['company_id' => ($company ?? $this->company)->id] + $attributes);
    }
}
