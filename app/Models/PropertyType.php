<?php

namespace App\Models;

use App\Models\Concerns\RegistryRecord;
use Database\Factories\PropertyTypeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Вид имот, отделно за всяка фирма. Засега без екран. */
#[UseFactory(PropertyTypeFactory::class)]
class PropertyType extends Model
{
    /** @use HasFactory<PropertyTypeFactory> */
    use HasFactory, RegistryRecord;

    /** Готовият списък при създаване на фирма (PROJECT.md, т. 2 „Регистър, уточнения“). */
    public const DEFAULTS = ['Апартамент', 'Къща', 'Офис', 'Магазин', 'Гараж', 'Паркомясто', 'Склад'];

    protected $table = 'property_types';

    protected $fillable = ['name', 'sort_order'];
}
