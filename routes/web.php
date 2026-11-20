<?php

use App\Http\Controllers\Charges\ChargeWorksheetController;
use App\Http\Controllers\Charges\CorrectionController;
use App\Http\Controllers\Charges\IssueController;
use App\Http\Controllers\Charges\StatementController;
use App\Http\Controllers\Charges\UpcomingChargeController;
use App\Http\Controllers\Contracts\LeaseContractController;
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

        // Договори за управление (PROJECT.md, т. 2 „Договори“, „Поправки“).
        $write = EnsureRegistryAccess::class.':write';
        Route::get('owner-contracts', [OwnerContractController::class, 'index'])->name('owner-contracts.index');
        Route::get('owner-contracts/create', [OwnerContractController::class, 'create'])->middleware($write)->name('owner-contracts.create');
        Route::post('owner-contracts', [OwnerContractController::class, 'store'])->middleware($write)->name('owner-contracts.store');
        Route::get('owner-contracts/{contract}', [OwnerContractController::class, 'show'])->name('owner-contracts.show');
        Route::get('owner-contracts/{contract}/edit', [OwnerContractController::class, 'edit'])->middleware($write)->name('owner-contracts.edit');
        Route::put('owner-contracts/{contract}', [OwnerContractController::class, 'update'])->middleware($write)->name('owner-contracts.update');
        Route::post('owner-contracts/{contract}/archive', [OwnerContractController::class, 'archive'])->middleware($write)->name('owner-contracts.archive');
        Route::post('owner-contracts/{contract}/restore', [OwnerContractController::class, 'restore'])->middleware($write)->name('owner-contracts.restore');
        Route::get('owner-contracts/{contract}/annexes/create', [OwnerContractController::class, 'createAnnex'])->middleware($write)->name('owner-contracts.annexes.create');
        Route::post('owner-contracts/{contract}/annexes', [OwnerContractController::class, 'storeAnnex'])->middleware($write)->name('owner-contracts.annexes.store');
        Route::get('owner-contracts/{contract}/correct', [OwnerContractController::class, 'editCorrection'])->middleware($write)->name('owner-contracts.correct');
        Route::put('owner-contracts/{contract}/correct', [OwnerContractController::class, 'updateCorrection'])->middleware($write)->name('owner-contracts.correct.update');

        // Договори за наем (PROJECT.md, т. 2 „Договори“, „Договор за наем“, „Поправки“).
        Route::get('lease-contracts', [LeaseContractController::class, 'index'])->name('lease-contracts.index');
        Route::get('lease-contracts/create', [LeaseContractController::class, 'create'])->middleware($write)->name('lease-contracts.create');
        Route::post('lease-contracts', [LeaseContractController::class, 'store'])->middleware($write)->name('lease-contracts.store');
        Route::get('lease-contracts/{contract}', [LeaseContractController::class, 'show'])->name('lease-contracts.show');
        Route::get('lease-contracts/{contract}/edit', [LeaseContractController::class, 'edit'])->middleware($write)->name('lease-contracts.edit');
        Route::put('lease-contracts/{contract}', [LeaseContractController::class, 'update'])->middleware($write)->name('lease-contracts.update');
        Route::post('lease-contracts/{contract}/archive', [LeaseContractController::class, 'archive'])->middleware($write)->name('lease-contracts.archive');
        Route::post('lease-contracts/{contract}/restore', [LeaseContractController::class, 'restore'])->middleware($write)->name('lease-contracts.restore');
        Route::get('lease-contracts/{contract}/annexes/create', [LeaseContractController::class, 'createAnnex'])->middleware($write)->name('lease-contracts.annexes.create');
        Route::post('lease-contracts/{contract}/annexes', [LeaseContractController::class, 'storeAnnex'])->middleware($write)->name('lease-contracts.annexes.store');
        Route::get('lease-contracts/{contract}/correct', [LeaseContractController::class, 'editCorrection'])->middleware($write)->name('lease-contracts.correct');
        Route::put('lease-contracts/{contract}/correct', [LeaseContractController::class, 'updateCorrection'])->middleware($write)->name('lease-contracts.correct.update');

        // Начисления: работната таблица на месеца и предстоящите (PROJECT.md, „Работна таблица на месеца“).
        // Генериране и промени: „четене и запис“ и „всички имоти“.
        $manage = EnsureRegistryAccess::class.':manage';
        Route::get('charges', [ChargeWorksheetController::class, 'index'])->name('charges.index');
        Route::post('charges/generate', [ChargeWorksheetController::class, 'generate'])->middleware($manage)->name('charges.generate');
        // Издаване и корекции (PROJECT.md, „Издаване и заключване“).
        Route::get('charges/issue', [IssueController::class, 'preview'])->middleware($manage)->name('charges.issue.preview');
        Route::post('charges/issue', [IssueController::class, 'issue'])->middleware($manage)->name('charges.issue');
        Route::get('lease-contracts/{contract}/issue', [IssueController::class, 'previewNow'])->middleware($manage)->name('lease-contracts.issue.preview');
        Route::post('lease-contracts/{contract}/issue', [IssueController::class, 'issueNow'])->middleware($manage)->name('lease-contracts.issue');
        Route::post('charges/corrections', [CorrectionController::class, 'correct'])->middleware($manage)->name('charges.corrections.correct');
        Route::get('charges/corrections/create', [CorrectionController::class, 'create'])->middleware($manage)->name('charges.corrections.create');
        Route::post('charges/corrections/manual', [CorrectionController::class, 'store'])->middleware($manage)->name('charges.corrections.store');
        Route::get('statements', [StatementController::class, 'index'])->name('statements.index');
        Route::get('statements/{statement}', [StatementController::class, 'show'])->name('statements.show');

        Route::get('charges/create', [ChargeWorksheetController::class, 'create'])->middleware($manage)->name('charges.create');
        Route::post('charges', [ChargeWorksheetController::class, 'store'])->middleware($manage)->name('charges.store');
        Route::get('charges/{charge}/edit', [ChargeWorksheetController::class, 'edit'])->middleware($manage)->name('charges.edit');
        Route::put('charges/{charge}', [ChargeWorksheetController::class, 'update'])->middleware($manage)->name('charges.update');
        Route::post('charges/{charge}/remove', [ChargeWorksheetController::class, 'remove'])->middleware($manage)->name('charges.remove');
        Route::post('charges/{charge}/restore', [ChargeWorksheetController::class, 'restore'])->middleware($manage)->name('charges.restore');

        Route::get('upcoming-charges', [UpcomingChargeController::class, 'index'])->name('upcoming-charges.index');
        Route::get('upcoming-charges/create', [UpcomingChargeController::class, 'create'])->middleware($manage)->name('upcoming-charges.create');
        Route::post('upcoming-charges', [UpcomingChargeController::class, 'store'])->middleware($manage)->name('upcoming-charges.store');
        Route::get('upcoming-charges/{upcoming}/edit', [UpcomingChargeController::class, 'edit'])->middleware($manage)->name('upcoming-charges.edit');
        Route::put('upcoming-charges/{upcoming}', [UpcomingChargeController::class, 'update'])->middleware($manage)->name('upcoming-charges.update');
        Route::post('upcoming-charges/{upcoming}/cancel', [UpcomingChargeController::class, 'cancel'])->middleware($manage)->name('upcoming-charges.cancel');
    });
});
