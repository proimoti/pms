<?php

namespace App\Models;

use App\Models\Base\Party;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/** Наемател (сметка 418 в счетоводството). */
#[UseFactory(TenantFactory::class)]
class Tenant extends Party
{
    protected $table = 'tenants';
}
