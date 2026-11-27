<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Паролата е поне 12 знака (PROJECT.md, т. 2).
        Password::defaults(fn () => Password::min(12));

        // Потребителят се търси само сред профилите на фирмата на адреса (27.11.2026).
        \Illuminate\Support\Facades\Auth::provider('company-address',
            fn ($app, array $config) => new \App\Auth\CompanyAddressUserProvider($app['hash'], $config['model']));

        // Всяко писмо минава през пазача на пощата (PROJECT.md, „PDF и имейл на известията“, 24.11.2026).
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, [\App\Mail\MailGuard::class, 'handle']);
    }
}
