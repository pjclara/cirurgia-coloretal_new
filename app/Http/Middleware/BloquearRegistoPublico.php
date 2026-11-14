<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O starter kit traz o registo público de contas ligado.
 * Numa aplicação com dados clínicos as contas são criadas pela administração,
 * por isso /register responde 404.
 */
class BloquearRegistoPublico
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->is('register', 'register/*'), 404);

        return $next($request);
    }
}
