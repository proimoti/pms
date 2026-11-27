<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Base\Party;
use App\Models\Owner;
use App\Models\Tenant;
use App\Support\PortalAccess;
use App\Support\UserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * „Покани в портала“, „Спри достъпа“ и „Върни достъпа“ от картата на
 * наемателя или собственика (27.11.2026). Само за служител с „четене и
 * запис“ (маршрутите). Запис на друга фирма — 404 (филтърът на модела).
 */
class PortalCardController extends Controller
{
    public function tenant(Request $request, Tenant $tenant, string $action): RedirectResponse
    {
        return $this->act($request, $tenant, $action, "/tenants/{$tenant->id}");
    }

    public function owner(Request $request, Owner $owner, string $action): RedirectResponse
    {
        return $this->act($request, $owner, $action, "/owners/{$owner->id}");
    }

    private function act(Request $request, Party $party, string $action, string $back): RedirectResponse
    {
        try {
            if ($action === 'invite') {
                $result = PortalAccess::invite($party, $request->user());

                return redirect($back)->with('status', __('portal.invite.'.$result, ['email' => mb_strtolower(trim((string) $party->email))]));
            }

            $user = PortalAccess::userFor($party) ?? abort(404);
            $action === 'stop' ? UserAccess::stop($user, $request->user()) : UserAccess::restore($user, $request->user());

            return redirect($back)->with('status', __('portal.card.'.($action === 'stop' ? 'stopped' : 'restored')));
        } catch (LogicException $e) {
            return redirect($back)->withErrors(['portal' => $e->getMessage()]);
        }
    }
}
