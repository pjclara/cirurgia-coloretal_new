<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Registo na tabela `audits` para o que não passa por um modelo com auditoria própria (perfis, catálogos). */
class Auditoria
{
    /**
     * @param  string  $tipo  classe do que mudou (ex.: `App\Models\Diagnostico`)
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $depois
     */
    public static function registar(string $tipo, int|string $id, string $evento, array $antes, array $depois): void
    {
        $pedido = app()->runningInConsole() ? null : request();
        $utilizador = $pedido?->user();

        DB::table('audits')->insert([
            'user_type' => $utilizador?->getMorphClass(),
            'user_id' => $utilizador?->getKey(),
            'event' => $evento,
            'auditable_type' => $tipo,
            'auditable_id' => $id,
            'old_values' => json_encode($antes, JSON_UNESCAPED_UNICODE),
            'new_values' => json_encode($depois, JSON_UNESCAPED_UNICODE),
            'url' => $pedido ? Str::limit($pedido->fullUrl(), 2000, '') : 'console',
            'ip_address' => $pedido?->ip(),
            'user_agent' => $pedido ? Str::limit((string) $pedido->userAgent(), 1000, '') : null,
            'tags' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
