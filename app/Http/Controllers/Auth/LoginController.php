<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireSlowmailPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login', [
            'configured' => (string) config('slowmail.password') !== '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            ['password' => ['required', 'string']],
            ['password.required' => 'Saisissez le mot de passe.'],
        );

        $expected = (string) config('slowmail.password');

        if ($expected === '') {
            return back()->withErrors(['password' => 'SLOWMAIL_PASSWORD n\'est pas défini dans la configuration : connexion impossible.']);
        }

        if (! hash_equals($expected, $request->string('password')->toString())) {
            return back()->withErrors(['password' => 'Mot de passe incorrect.']);
        }

        $request->session()->regenerate();
        $request->session()->put(RequireSlowmailPassword::SESSION_KEY, true);

        return redirect()->intended(route('campaigns.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
