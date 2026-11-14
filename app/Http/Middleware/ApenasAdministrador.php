<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Só deixa passar contas com um dos roles de config('clinico.roles_administracao'). */
class ApenasAdministrador
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->eAdministrador(), 403, 'Área reservada a administradores.');

        return $next($request);
    }
}
