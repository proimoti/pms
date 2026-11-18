<?php

namespace Database\Factories;

use App\Models\Tenant;

/** @extends PartyFactory<Tenant> */
class TenantFactory extends PartyFactory
{
    protected $model = Tenant::class;
}
