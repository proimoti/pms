<?php

namespace App\Auth;

use App\Support\CompanyAddress;
use Illuminate\Auth\EloquentUserProvider;

/**
 * Търсене на потребител по имейл само сред профилите на фирмата на адреса
 * (вход, забравена парола, нова парола; 27.11.2026). Имейлът е уникален в
 * рамките на фирмата, затова без адреса не е ясно кой е профилът.
 */
class CompanyAddressUserProvider extends EloquentUserProvider
{
    public function retrieveByCredentials(array $credentials)
    {
        if (! isset($credentials['email'])) {
            return null;
        }

        return CompanyAddress::constrain($this->newModelQuery(), request())->where('email', $credentials['email'])->first();
    }
}
