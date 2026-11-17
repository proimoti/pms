<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Passkeys\PasskeysServiceProvider;
use Tests\TestCase;

/**
 * Ключовете за достъп (passkeys) идват с Fortify, но не се ползват.
 * Тестът доказва, че функцията е изключена: няма адреси за нея и тя не е
 * включена в настройките на Fortify. Изключени са и регистрацията и др.
 */
class PasskeysDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_passkey_routes_exist(): void
    {
        $passkeyRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains(strtolower($route->uri()), 'passkey')
                || str_contains(strtolower((string) $route->getName()), 'passkey')
                || str_contains(strtolower($route->getActionName()), 'passkey')
                || str_contains(strtolower($route->uri()), 'webauthn'))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $passkeyRoutes, 'Има адреси за ключове за достъп.');
    }

    public function test_fortify_features_are_exactly_the_ones_we_use(): void
    {
        $this->assertEqualsCanonicalizing(
            ['reset-passwords', 'two-factor-authentication'],
            config('fortify.features'),
        );
    }

    public function test_no_fortify_feature_mentions_passkeys(): void
    {
        foreach (config('fortify.features') as $feature) {
            $this->assertStringNotContainsStringIgnoringCase('passkey', $feature);
        }
    }

    public function test_passkeys_package_is_not_loaded(): void
    {
        $this->assertSame([], app()->getProviders(PasskeysServiceProvider::class),
            'Пакетът laravel/passkeys не трябва да се зарежда (composer.json, dont-discover).');
    }

    public function test_there_is_no_passkeys_table(): void
    {
        $this->assertFalse(Schema::hasTable('passkeys'));
    }
}
