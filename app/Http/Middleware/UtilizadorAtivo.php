<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Termina a sessão de contas desativadas (users.activo = 0),
 * incluindo sessões que já estavam abertas quando a conta foi desativada.
 */
class UtilizadorAtivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilizador = $request->user();

        if ($utilizador && ! $utilizador->activo) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->withErrors(['email' => 'Esta conta está desativada.']);
        }

        return $next($request);
    }
}
