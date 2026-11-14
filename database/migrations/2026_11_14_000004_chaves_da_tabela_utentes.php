<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `utentes` não tinha nenhuma chave estrangeira nem índices de pesquisa.
 * Não se cria índice único em `numero_processo` porque existem fichas duplicadas
 * que têm de ser fundidas à mão (ver `php artisan dados:verificar`).
 */
return new class extends Migration
{
    private const DOMINIOS = [
        'sexo_id' => 'sexos',
        'estado_civil_id' => 'estado_civils',
        'etnia_id' => 'etnias',
        'concelho_id' => 'concelhos',
        'classe_social_id' => 'classe_socials',
    ];

    private const AUTORES = ['created_by_id', 'updated_by_id', 'deleted_by_id'];

    public function up(): void
    {
        foreach (self::DOMINIOS + array_fill_keys(self::AUTORES, 'users') as $coluna => $pai) {
            $orfaos = DB::table('utentes')
                ->whereNotNull($coluna)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($pai)->whereColumn("$pai.id", "utentes.$coluna"))
                ->count();

            if ($orfaos > 0) {
                throw new RuntimeException("utentes.$coluna tem $orfaos valores sem registo em $pai. Corrija-os antes de migrar.");
            }
        }

        Schema::table('utentes', function (Blueprint $table) {
            foreach (self::DOMINIOS as $coluna => $pai) {
                $table->foreign($coluna)->references('id')->on($pai)->restrictOnDelete();
            }
            foreach (self::AUTORES as $coluna) {
                $table->foreign($coluna)->references('id')->on('users')->nullOnDelete();
            }

            $table->index('numero_processo', 'utentes_numero_processo_index');
            $table->index('numero_utente', 'utentes_numero_utente_index');
            $table->index('nome', 'utentes_nome_index');
        });
    }

    public function down(): void
    {
        Schema::table('utentes', function (Blueprint $table) {
            foreach (array_merge(array_keys(self::DOMINIOS), self::AUTORES) as $coluna) {
                $table->dropForeign([$coluna]);
            }
            $table->dropIndex('utentes_numero_processo_index');
            $table->dropIndex('utentes_numero_utente_index');
            $table->dropIndex('utentes_nome_index');
        });
    }
};
