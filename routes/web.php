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
use App\Http\Controllers\Registry\ProviderController;
use App\Http\Controllers\Registry\FundController;
use App\Http\Controllers\Registry\UtilityAccountController;
use App\Http\Controllers\Utilities\UtilityImportController;
use App\Http\Controllers\Utilities\UnallocatedController;
use App\Http\Controllers\Utilities\UtilityIssueController;
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
            'providers' => ProviderController::class,
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

        // Абонати на битовите услуги (PROJECT.md, т. 3.4, 21.11.2026).
        $write = EnsureRegistryAccess::class.':write';
        Route::get('utility-accounts', [UtilityAccountController::class, 'index'])->name('utility-accounts.index');
        Route::get('utility-accounts/create', [UtilityAccountController::class, 'create'])->middleware($write)->name('utility-accounts.create');
        Route::post('utility-accounts', [UtilityAccountController::class, 'store'])->middleware($write)->name('utility-accounts.store');
        Route::get('utility-accounts/{account}', [UtilityAccountController::class, 'show'])->name('utility-accounts.show');
        Route::get('utility-accounts/{account}/edit', [UtilityAccountController::class, 'edit'])->middleware($write)->name('utility-accounts.edit');
        Route::put('utility-accounts/{account}', [UtilityAccountController::class, 'update'])->middleware($write)->name('utility-accounts.update');
        Route::post('utility-accounts/{account}/archive', [UtilityAccountController::class, 'archive'])->middleware($write)->name('utility-accounts.archive');
        Route::post('utility-accounts/{account}/restore', [UtilityAccountController::class, 'restore'])->middleware($write)->name('utility-accounts.restore');

        // Общи фондове (PROJECT.md, „Общ фонд“, 22–23.11.2026).
        Route::get('funds', [FundController::class, 'index'])->name('funds.index');
        Route::get('funds/create', [FundController::class, 'create'])->middleware($write)->name('funds.create');
        Route::post('funds', [FundController::class, 'store'])->middleware($write)->name('funds.store');
        Route::get('funds/{fund}', [FundController::class, 'show'])->name('funds.show');
        Route::get('funds/{fund}/edit', [FundController::class, 'edit'])->middleware($write)->name('funds.edit');
        Route::put('funds/{fund}', [FundController::class, 'update'])->middleware($write)->name('funds.update');
        Route::post('funds/{fund}/archive', [FundController::class, 'archive'])->middleware($write)->name('funds.archive');
        Route::post('funds/{fund}/restore', [FundController::class, 'restore'])->middleware($write)->name('funds.restore');

        // Договори за управление (PROJECT.md, т. 2 „Договори“, „Поправки“).
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

        // Внос на платените битови сметки (PROJECT.md, т. 5.3): само „четене и запис“ и „всички имоти“.
        Route::middleware($manage)->group(function () {
            Route::get('utility-imports', [UtilityImportController::class, 'index'])->name('utility-imports.index');
            Route::post('utility-imports/preview', [UtilityImportController::class, 'preview'])->name('utility-imports.preview');
            Route::post('utility-imports', [UtilityImportController::class, 'store'])->name('utility-imports.store');
            Route::post('utility-imports/cancel', [UtilityImportController::class, 'cancel'])->name('utility-imports.cancel');
            Route::get('utility-imports/{batch}', [UtilityImportController::class, 'show'])->name('utility-imports.show');
            Route::get('utility-issues', [UtilityIssueController::class, 'index'])->name('utility-issues.index');
            Route::post('utility-issues/retry', [UtilityIssueController::class, 'retry'])->name('utility-issues.retry');
            Route::post('utility-issues/{issue}/ignore', [UtilityIssueController::class, 'ignore'])->name('utility-issues.ignore');
            Route::post('utility-issues/{issue}/unignore', [UtilityIssueController::class, 'unignore'])->name('utility-issues.unignore');
            Route::post('utility-issues/{issue}/provider', [UtilityIssueController::class, 'linkProvider'])->name('utility-issues.provider');
        });

        // Неразпределени битови сметки (PROJECT.md, част 5, 23.11.2026): вижда всеки служител, решава „управлява начисленията“.
        Route::get('utility-unallocated', [UnallocatedController::class, 'index'])->name('utility-unallocated.index');
        Route::post('utility-unallocated/manager', [UnallocatedController::class, 'toManager'])->middleware($manage)->name('utility-unallocated.manager');
        Route::post('utility-unallocated/next-tenant', [UnallocatedController::class, 'toNextTenant'])->middleware($manage)->name('utility-unallocated.next-tenant');
        Route::post('utility-decisions/{decision}/cancel', [UnallocatedController::class, 'cancel'])->middleware($manage)->name('utility-decisions.cancel');

        Route::get('upcoming-charges', [UpcomingChargeController::class, 'index'])->name('upcoming-charges.index');
        Route::get('upcoming-charges/create', [UpcomingChargeController::class, 'create'])->middleware($manage)->name('upcoming-charges.create');
        Route::post('upcoming-charges', [UpcomingChargeController::class, 'store'])->middleware($manage)->name('upcoming-charges.store');
        Route::get('upcoming-charges/{upcoming}/edit', [UpcomingChargeController::class, 'edit'])->middleware($manage)->name('upcoming-charges.edit');
        Route::put('upcoming-charges/{upcoming}', [UpcomingChargeController::class, 'update'])->middleware($manage)->name('upcoming-charges.update');
        Route::post('upcoming-charges/{upcoming}/cancel', [UpcomingChargeController::class, 'cancel'])->middleware($manage)->name('upcoming-charges.cancel');
    });
});
