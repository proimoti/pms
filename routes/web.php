<?php

use App\Http\Controllers\Contracts\OwnerContractController;
use App\Http\Controllers\Registry\BeneficiaryController;
use App\Http\Controllers\Registry\BrokerController;
use App\Http\Controllers\Registry\OwnerController;
use App\Http\Controllers\Registry\PropertyController;
use App\Http\Controllers\Registry\TenantController;
use App\Http\Middleware\EnsureRegistryAccess;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', function () {
    return view('welcome');
});

// Входът, изходът, забравената парола и вторият фактор са от Fortify
// (config/fortify.php, app/Providers/FortifyServiceProvider.php).

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');

    Route::get('/two-factor/setup', fn () => view('auth.two-factor-setup'))->name('two-factor.setup');

    // Регистър (PROJECT.md, т. 2): само за служители; промени само с „четене и запис“.
    // Изтриване няма, само архивиране.
    Route::middleware(EnsureRegistryAccess::class)->group(function () {
        $sections = [
            'properties' => PropertyController::class,
            'owners' => OwnerController::class,
            'beneficiaries' => BeneficiaryController::class,
            'tenants' => TenantController::class,
            'brokers' => BrokerController::class,
        ];

        foreach ($sections as $section => $controller) {
            $parameter = Str::singular($section);
            $write = EnsureRegistryAccess::class.':write';

            Route::get($section, [$controller, 'index'])->name("{$section}.index");
            Route::get("{$section}/create", [$controller, 'create'])->middleware($write)->name("{$section}.create");
            Route::post($section, [$controller, 'store'])->middleware($write)->name("{$section}.store");
            Route::get("{$section}/{{$parameter}}", [$controller, 'show'])->name("{$section}.show");
            Route::get("{$section}/{{$parameter}}/edit", [$controller, 'edit'])->middleware($write)->name("{$section}.edit");
            Route::put("{$section}/{{$parameter}}", [$controller, 'update'])->middleware($write)->name("{$section}.update");
            Route::post("{$section}/{{$parameter}}/archive", [$controller, 'archive'])->middleware($write)->name("{$section}.archive");
            Route::post("{$section}/{{$parameter}}/restore", [$controller, 'restore'])->middleware($write)->name("{$section}.restore");
        }

        // Договори за управление (PROJECT.md, т. 2 „Договори“). Анексите идват в следващата стъпка.
        $write = EnsureRegistryAccess::class.':write';
        Route::get('owner-contracts', [OwnerContractController::class, 'index'])->name('owner-contracts.index');
        Route::get('owner-contracts/create', [OwnerContractController::class, 'create'])->middleware($write)->name('owner-contracts.create');
        Route::post('owner-contracts', [OwnerContractController::class, 'store'])->middleware($write)->name('owner-contracts.store');
        Route::get('owner-contracts/{contract}', [OwnerContractController::class, 'show'])->name('owner-contracts.show');
        Route::get('owner-contracts/{contract}/edit', [OwnerContractController::class, 'edit'])->middleware($write)->name('owner-contracts.edit');
        Route::put('owner-contracts/{contract}', [OwnerContractController::class, 'update'])->middleware($write)->name('owner-contracts.update');
        Route::post('owner-contracts/{contract}/archive', [OwnerContractController::class, 'archive'])->middleware($write)->name('owner-contracts.archive');
        Route::post('owner-contracts/{contract}/restore', [OwnerContractController::class, 'restore'])->middleware($write)->name('owner-contracts.restore');
    });
});
