<?php

namespace App\Http\Controllers\Registry;

use App\Http\Requests\Registry\PartyRequest;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Екранът „Наематели“. */
class TenantController extends PartyController
{
    protected function section(): string
    {
        return 'tenants';
    }

    protected function modelClass(): string
    {
        return Tenant::class;
    }

    public function show(Tenant $tenant): View
    {
        return $this->showRecord($tenant);
    }

    public function edit(Tenant $tenant): View
    {
        return $this->editRecord($tenant);
    }

    public function update(PartyRequest $request, Tenant $tenant): RedirectResponse
    {
        return $this->updateRecord($request, $tenant);
    }

    public function archive(Tenant $tenant): RedirectResponse
    {
        return $this->archiveRecord($tenant);
    }

    public function restore(Tenant $tenant): RedirectResponse
    {
        return $this->restoreRecord($tenant);
    }
}
