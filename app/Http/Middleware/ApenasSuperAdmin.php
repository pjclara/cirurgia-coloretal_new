<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Páginas reservadas ao perfil superAdmin. */
class ApenasSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->hasRole('superAdmin'), 403, 'Área reservada ao superAdmin.');

        return $next($request);
    }
}
