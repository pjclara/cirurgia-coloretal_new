<?php

namespace App\Providers;

use App\Http\Middleware\BloquearRegistoPublico;
use App\Http\Middleware\PasswordAlterada;
use App\Http\Middleware\UtilizadorAtivo;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

/**
 * Liga tudo o que é específico da aplicação clínica sem alterar os ficheiros do starter kit.
 * Registar em bootstrap/providers.php.
 */
class ClinicoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Depois do login, vai para o quadro do serviço (ver AuthenticatedSessionController).
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('clinico.ativo', UtilizadorAtivo::class);
        $router->aliasMiddleware('clinico.admin', \App\Http\Middleware\ApenasAdministrador::class);
        $router->aliasMiddleware('clinico.password', PasswordAlterada::class);
        $router->pushMiddlewareToGroup('web', BloquearRegistoPublico::class);

        // superAdmin passa em todas as verificações de permissão.
        Gate::before(fn (User $utilizador) => $utilizador->hasRole('superAdmin') ? true : null);

        // Último acesso, sem gerar auditoria.
        Event::listen(Login::class, function (Login $evento) {
            if ($evento->user instanceof User) {
                $evento->user->forceFill([
                    'last_login_time' => now(),
                    'last_login_ip' => request()->ip(),
                ])->saveQuietly();
            }
        });

        // Dados partilhados com todas as páginas React, numa única chave.
        Inertia::share('clinico', function () {
            $pedido = request();
            $utilizador = $pedido->user();

            return [
                'utilizador' => $utilizador ? [
                    'id' => $utilizador->id,
                    'nome' => $utilizador->name,
                    'abrev' => $utilizador->abrev,
                    'equipa' => $utilizador->equipa,
                    'roles' => $utilizador->getRoleNames()->values(),
                ] : null,
                'permissoes' => $utilizador ? $utilizador->getAllPermissions()->pluck('name')->values() : [],
                'administrador' => $utilizador ? $utilizador->eAdministrador() : false,
                'flash' => [
                    'sucesso' => $pedido->hasSession() ? $pedido->session()->get('sucesso') : null,
                    'erro' => $pedido->hasSession() ? $pedido->session()->get('erro') : null,
                ],
            ];
        });

        Route::middleware(['web', 'auth', 'clinico.ativo', 'clinico.password'])
            ->group(base_path('routes/clinico.php'));
    }
}
