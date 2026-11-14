<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Escreve na tabela `audits` já existente, no mesmo formato da auditoria antiga
 * (event, auditable_type, auditable_id, old_values, new_values, url, ip, user agent).
 *
 * Um modelo pode declarar `protected array $naoAuditar = [...]` para excluir colunas.
 */
trait RegistaAuditoria
{
    public static function bootRegistaAuditoria(): void
    {
        static::created(function ($modelo) {
            $modelo->auditar('created', [], $modelo->filtrarAuditaveis($modelo->getAttributes()));
        });

        static::updated(function ($modelo) {
            $novos = $modelo->filtrarAuditaveis($modelo->getChanges());
            if ($novos === []) {
                return;
            }
            $antigos = array_intersect_key($modelo->getRawOriginal(), $novos);
            $modelo->auditar('updated', $antigos, $novos);
        });

        static::deleted(function ($modelo) {
            $modelo->auditar('deleted', $modelo->filtrarAuditaveis($modelo->getRawOriginal()), []);
        });
    }

    public function auditar(string $evento, array $antigos, array $novos): void
    {
        $pedido = app()->runningInConsole() ? null : request();
        $utilizador = Auth::user();

        DB::table('audits')->insert([
            'user_type' => $utilizador ? $utilizador->getMorphClass() : null,
            'user_id' => $utilizador?->getKey(),
            'event' => $evento,
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'old_values' => json_encode($antigos, JSON_UNESCAPED_UNICODE),
            'new_values' => json_encode($novos, JSON_UNESCAPED_UNICODE),
            'url' => $pedido ? Str::limit($pedido->fullUrl(), 2000, '') : 'console',
            'ip_address' => $pedido?->ip(),
            'user_agent' => $pedido ? Str::limit((string) $pedido->userAgent(), 1000, '') : null,
            'tags' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function filtrarAuditaveis(array $atributos): array
    {
        $excluir = array_merge(
            ['updated_at', 'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'],
            property_exists($this, 'naoAuditar') ? $this->naoAuditar : [],
        );

        return array_diff_key($atributos, array_flip($excluir));
    }
}
