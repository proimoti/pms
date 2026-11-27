<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Services\Home\Home;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Начало“: за служителя на фирма — лентата на месеца, „Иска решение“ и „За
 * проследяване“ (24.11.2026); за другите потребители — простата страница.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, Home $home): View|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        if ($user->role === Role::Client) {
            return redirect('/portal'); // клиентът е в портала (27.11.2026)
        }
        if ($user->role !== Role::Staff || $user->company_id === null) {
            return view('dashboard');
        }

        return view('home', ['home' => $home->summary()]);
    }
}
