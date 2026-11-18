<?php

namespace App\Models;

use App\Enums\PaymentSource;
use App\Models\Scopes\CompanyScope;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'eik', 'vat_number', 'manager_name', 'address', 'email'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Потребителят вижда само своята фирма (тук връзката е по id).
        // Администраторът на приложението вижда всички фирми.
        static::addGlobalScope(new CompanyScope('id', appAdminSeesAll: true));
    }

    protected function casts(): array
    {
        return [
            'payment_source' => PaymentSource::class,
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
