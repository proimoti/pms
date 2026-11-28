<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Порталът за клиентите (27.11.2026): само записите, свързани с профила.
 * Регистърът е скрит за клиентите, затова записите се четат изрично по връзките.
 */
class PortalController extends Controller
{
    public function home(Request $request): View
    {
        $user = $request->user();
        $links = DB::table('portal_links')->where('company_id', $user->company_id)->where('user_id', $user->id);

        $tenants = DB::table('tenants')->where('company_id', $user->company_id)->whereNull('archived_at')
            ->whereIn('id', (clone $links)->whereNotNull('tenant_id')->select('tenant_id'))->orderBy('name')->get(['id', 'number', 'name']);
        $owners = DB::table('owners')->where('company_id', $user->company_id)->whereNull('archived_at')
            ->whereIn('id', (clone $links)->whereNotNull('owner_id')->select('owner_id'))->orderBy('name')->get(['id', 'number', 'name']);

        // „Дължи / Надплатено“ на всеки наемател (27.11.2026).
        $portal = app(\App\Services\Portal\TenantPortal::class);
        $owes = $portal->tenants($user)->mapWithKeys(fn ($tenant) => [$tenant->id => $portal->owes($tenant)['total']])->all();

        return view('portal.home', ['tenants' => $tenants, 'owners' => $owners, 'owes' => $owes]);
    }

    /** Логото на фирмата за заглавието на портала. */
    public function logo(Request $request): Response
    {
        $logo = Company::query()->withoutGlobalScopes()->findOrFail($request->user()->company_id)->logoFile() ?? abort(404);

        return response($logo['bytes'], 200, ['Content-Type' => $logo['mime'], 'Cache-Control' => 'private, max-age=3600']);
    }
}
