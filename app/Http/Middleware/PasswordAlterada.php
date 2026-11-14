<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Obriga as contas marcadas com must_change_password a definir uma password nova
 * antes de acederem a qualquer página clínica.
 */
class PasswordAlterada
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilizador = $request->user();

        if ($utilizador && $utilizador->must_change_password && ! $request->routeIs('conta.password.*')) {
            return redirect()->route('conta.password.editar');
        }

        return $next($request);
    }
}
