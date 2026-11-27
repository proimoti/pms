<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccessLevel;
use App\Enums\PropertyScope;
use App\Enums\Role;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

// Ролята, фирмата и правата нарочно не са във Fillable:
// задават се изрично, не идват направо от попълнена форма.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToCompany, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_company_admin' => 'boolean',
            'property_scope' => PropertyScope::class,
            'access_level' => AccessLevel::class,
            'can_view_sensitive' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Администраторът на приложението вижда потребителите на всички фирми. */
    protected static function appAdminSeesAll(): bool
    {
        return true;
    }

    public function isAppAdmin(): bool
    {
        return $this->role === Role::AppAdmin;
    }

    /** Вторият фактор е задължителен за администратора на приложението и служителите. */
    public function requiresTwoFactor(): bool
    {
        return in_array($this->role, [Role::AppAdmin, Role::Staff], true);
    }

    /**
     * Ключът на връзката за нова парола (27.11.2026): имейлът е уникален в рамките
     * на фирмата, затова ключът е „фирма|имейл“ — връзка, поискана в една фирма,
     * не пипа профила със същия имейл в друга.
     */
    public function getEmailForPasswordReset(): string
    {
        return ($this->company_id ?? 'admin').'|'.$this->email;
    }
}
