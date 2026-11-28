<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\TenantPortal;
use App\Services\Statements\StatementPdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Порталът за наемателя (27.11.2026): договори, „Дължи / Надплатено“,
 * известия с PDF, плащания без сторнираните, депозит и банкова сметка за
 * плащане. Чужд, архивиран или несъществуващ наемател или известие — 404.
 */
class TenantPortalController extends Controller
{
    public function __construct(private readonly TenantPortal $portal) {}

    public function show(Request $request, int $tenant): View
    {
        $tenant = $this->portal->tenant($request->user(), $tenant) ?? abort(404);
        $leases = $this->portal->leases($tenant);

        return view('portal.tenant', [
            'tenant' => $tenant,
            'portal' => $this->portal,
            'leases' => $leases,
            'owes' => $this->portal->owes($tenant),
            'statements' => $this->portal->statements($tenant),
            'payments' => $this->portal->payments($tenant),
            'deposits' => $this->portal->deposits($leases),
            'bank' => $this->portal->bank($request->user()),
            'leaseNumbers' => $leases->pluck('number', 'id'),
        ]);
    }

    /** PDF на известието: при поискване, без пазене на файл. */
    public function pdf(Request $request, int $statement, StatementPdf $pdf): Response
    {
        $statement = $this->portal->statement($request->user(), $statement) ?? abort(404);

        return response($pdf->pdf($statement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($statement).'"',
        ]);
    }
}
