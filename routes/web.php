<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Входът, изходът, забравената парола и вторият фактор са от Fortify
// (config/fortify.php, app/Providers/FortifyServiceProvider.php).

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');

    Route::get('/two-factor/setup', fn () => view('auth.two-factor-setup'))->name('two-factor.setup');
});
