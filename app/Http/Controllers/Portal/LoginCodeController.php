<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Support\LoginCode;
use App\Support\PortalAccess;
use App\Support\TrustedDevices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Код по имейл след паролата, при вход в портала от ново устройство
 * (27.11.2026). Без чакащ вход (или на чужд адрес, или изтекъл) — към входа.
 */
class LoginCodeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (LoginCode::pendingUser($request) === null) {
            return $this->restart($request);
        }

        return view('auth.login-code');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = LoginCode::pendingUser($request);
        if ($user === null || ! PortalAccess::hasAccess($user)) {
            return $this->restart($request);
        }
        $request->validate(['code' => ['required', 'string']]);

        $result = LoginCode::check($request, $user, preg_replace('/\D/', '', (string) $request->input('code')));
        if ($result === 'ended') {
            return redirect()->route('login')->withErrors(['email' => __('portal.code.too_many')]);
        }
        if ($result === 'wrong') {
            return back()->withErrors(['code' => __('portal.code.wrong')]);
        }

        $remember = LoginCode::remember($request);
        LoginCode::end($request);
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        return redirect('/portal')->withCookie(TrustedDevices::remember($user));
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = LoginCode::pendingUser($request);
        if ($user === null) {
            return $this->restart($request);
        }
        LoginCode::start($user, $request, LoginCode::remember($request));

        return redirect('/login/code')->with('status', __('portal.code.sent_again'));
    }

    private function restart(Request $request): RedirectResponse
    {
        LoginCode::end($request);

        return redirect()->route('login')->withErrors(['email' => __('portal.code.expired')]);
    }
}
