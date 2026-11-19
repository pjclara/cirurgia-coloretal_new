<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permite utentes sem data de nascimento, sexo e concelho (por completar). Serve para os doentes que só se conhecem
 * pelo nome e pelo número de processo (ex.: a importação da triagem): em vez de inventar valores, ficam em branco até
 * alguém os preencher na ficha, onde continuam obrigatórios ao guardar.
 *
 * Reversível enquanto nenhum utente tiver estes campos vazios; se houver, o `down` não os altera.
 * Faça um backup da base de dados antes de correr.
 */
return new class extends Migration
{
    private const COLUNAS = [
        'data_nascimento' => 'DATE',
        'sexo_id' => 'BIGINT UNSIGNED',
        'concelho_id' => 'BIGINT UNSIGNED',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('utentes')) {
            return;
        }
        foreach (self::COLUNAS as $coluna => $tipo) {
            if (Schema::hasColumn('utentes', $coluna)) {
                DB::statement("ALTER TABLE utentes MODIFY $coluna $tipo NULL");
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('utentes')) {
            return;
        }
        foreach (self::COLUNAS as $coluna => $tipo) {
            if (Schema::hasColumn('utentes', $coluna) && ! DB::table('utentes')->whereNull($coluna)->exists()) {
                DB::statement("ALTER TABLE utentes MODIFY $coluna $tipo NOT NULL");
            }
        }
    }
};
