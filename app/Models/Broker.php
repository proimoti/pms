<?php

namespace App\Models;

use App\Models\Concerns\RegistryRecord;
use Database\Factories\BrokerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Отговорно лице (информативно). Виждат го всички служители на фирмата. */
#[UseFactory(BrokerFactory::class)]
class Broker extends Model
{
    /** @use HasFactory<BrokerFactory> */
    use HasFactory, RegistryRecord;

    protected $table = 'brokers';

    protected $fillable = ['name', 'phone', 'email', 'note'];
}
