<?php

namespace App\Http\Responses;

use App\Enums\Role;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;

/** След вход (27.11.2026): клиентът — към портала, другите — към „Начало“. */
class LoginResponse implements LoginResponseContract, TwoFactorLoginResponse
{
    public function toResponse($request)
    {
        if ($request->user()?->role === Role::Client) {
            return redirect('/portal');
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
