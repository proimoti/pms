<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\OwnerPortal;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Порталът за собственика (27.11.2026): договорите за управление, имотите
 * „Под наем“ или „Свободен“, по месеци начислено, изплатено и остатък и
 * начинът на изплащане. Чужд, архивиран или несъществуващ собственик — 404.
 */
class OwnerPortalController extends Controller
{
    public function __construct(private readonly OwnerPortal $portal) {}

    public function show(Request $request, int $owner): View
    {
        $owner = $this->portal->owner($request->user(), $owner) ?? abort(404);

        return view('portal.owner', ['owner' => $owner, 'portal' => $this->portal, 'contracts' => $this->portal->contracts($owner)]);
    }
}
