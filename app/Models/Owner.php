<?php

namespace App\Models;

use App\Models\Base\Party;
use Database\Factories\OwnerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/** Собственик (сметка 408 в счетоводството). */
#[UseFactory(OwnerFactory::class)]
class Owner extends Party
{
    protected $table = 'owners';
}
