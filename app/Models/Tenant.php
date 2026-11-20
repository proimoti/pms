<?php

namespace App\Models;

use App\Models\Base\Party;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/** Наемател (сметка 418 в счетоводството). */
#[UseFactory(TenantFactory::class)]
class Tenant extends Party
{
    protected $table = 'tenants';

    /** Обхват „само прикачените имоти“: вижда се, ако е наемател във видим договор за наем. */
    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::referencedByContracts('lease_contracts', 'tenant_id', 'lease_contract_lines', 'lease_contract_id');
    }
}
