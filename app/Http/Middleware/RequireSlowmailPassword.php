<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Toute l'interface est derrière le mot de passe unique SLOWMAIL_PASSWORD. */
class RequireSlowmailPassword
{
    public const SESSION_KEY = 'slowmail.authenticated';

    public function handle(Request $request, Closure $next): Response
    {
        // Sans mot de passe configuré, personne n'entre, même avec une session existante.
        if ((string) config('slowmail.password') === '' || $request->session()->get(self::SESSION_KEY) !== true) {
            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
