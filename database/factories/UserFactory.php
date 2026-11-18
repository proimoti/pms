<?php

namespace Database\Factories;

use App\Enums\AccessLevel;
use App\Enums\PropertyScope;
use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'role' => Role::Staff,
            // Удобно за тестовете: служител, който работи с регистъра.
            // В базата по подразбиране е най-безопасното (само четене, само прикачените).
            'access_level' => AccessLevel::Write,
            'property_scope' => PropertyScope::All,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /** Администратор на приложението: без фирма. */
    public function appAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => null,
            'role' => Role::AppAdmin,
        ]);
    }

    public function readOnly(): static
    {
        return $this->state(fn (array $attributes) => ['access_level' => AccessLevel::Read]);
    }

    public function assignedPropertiesOnly(): static
    {
        return $this->state(fn (array $attributes) => ['property_scope' => PropertyScope::Assigned]);
    }

    public function canViewSensitive(): static
    {
        return $this->state(fn (array $attributes) => ['can_view_sensitive' => true]);
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Owner]);
    }

    public function tenant(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Tenant]);
    }

    /**
     * Включен и потвърден втори фактор с даден таен ключ.
     *
     * @param  list<string>  $recoveryCodes
     */
    public function withTwoFactor(string $secret, array $recoveryCodes = ['izmislen-kod-9999']): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
