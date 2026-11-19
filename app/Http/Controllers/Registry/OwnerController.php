<?php

namespace App\Http\Controllers\Registry;

use App\Http\Requests\Registry\PartyRequest;
use App\Models\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Екранът „Собственици“. */
class OwnerController extends PartyController
{
    protected function section(): string
    {
        return 'owners';
    }

    protected function modelClass(): string
    {
        return Owner::class;
    }

    public function show(Owner $owner): View
    {
        return $this->showRecord($owner);
    }

    public function edit(Owner $owner): View
    {
        return $this->editRecord($owner);
    }

    public function update(PartyRequest $request, Owner $owner): RedirectResponse
    {
        return $this->updateRecord($request, $owner);
    }

    public function archive(Owner $owner): RedirectResponse
    {
        return $this->archiveRecord($owner);
    }

    public function restore(Owner $owner): RedirectResponse
    {
        return $this->restoreRecord($owner);
    }
}
