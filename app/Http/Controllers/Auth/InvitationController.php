<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules\Password;

/**
 * „Задай парола“ по покана (27.11.2026). Без вход. Връзката важи само на адреса
 * на фирмата, 72 часа и веднъж. Непозната, използвана или чужда връзка — 404;
 * изтекла — 410 с обяснение.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $user = Invitation::find($token, $request) ?? abort(404);
        $expired = Invitation::expired($user);

        return response()->view('auth.invitation', [
            'companyName' => Company::query()->withoutGlobalScopes()->whereKey($user->company_id)->value('name'),
            'email' => $user->email,
            'expired' => $expired,
        ], $expired ? 410 : 200);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = Invitation::find($token, $request) ?? abort(404);
        abort_if(Invitation::expired($user), 410, __('invitation.expired'));

        $data = $request->validate(['password' => ['required', 'string', Password::defaults(), 'confirmed']]);
        Invitation::accept($user, $data['password']);

        return redirect()->route('login')->with('status', __('invitation.done'));
    }
}
