<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No esquema antigo, apagar um utilizador apagava em cascata os internamentos,
 * cirurgias, agendamentos, etc. que ele tinha criado ou editado.
 * Passa a: SET NULL nas colunas opcionais, RESTRICT nas obrigatórias.
 */
return new class extends Migration
{
    /** tabela => [coluna => obrigatória?] */
    private const CHAVES = [
        'agendamentos' => ['created_by_id' => false, 'updated_by_id' => false, 'deleted_by_id' => false, 'responsavel_id' => true],
        'bloco_operatorios' => ['created_by_id' => false, 'updated_by_id' => false, 'deleted_by_id' => false],
        'bloco_operatorio_c_r_s' => ['created_by_id' => false, 'updated_by_id' => false, 'deleted_by_id' => false],
        'exame_complementares' => ['created_by_id' => false, 'updated_by_id' => false, 'deleted_by_id' => false],
        'internamentos' => ['created_by_id' => false, 'updated_by_id' => false, 'deleted_by_id' => false],
        'intervencao_descricaos' => ['created_by_id' => true, 'updated_by_id' => true, 'deleted_by_id' => false],
        'lista_de_esperas' => ['created_by_id' => false, 'updated_by_id' => false, 'deleted_by_id' => false],
        'referenciacao_centro_de_referencias' => ['created_by_id' => true, 'updated_by_id' => false, 'deleted_by_id' => false],
        'reuniao_decisao_terapeuticas' => ['created_by_id' => false, 'updated_by_id' => false, 'deleted_by_id' => false],
    ];

    public function up(): void
    {
        foreach (self::CHAVES as $tabela => $colunas) {
            if (! Schema::hasTable($tabela)) {
                continue;
            }

            Schema::table($tabela, function (Blueprint $table) use ($colunas) {
                foreach (array_keys($colunas) as $coluna) {
                    $table->dropForeign([$coluna]);
                }
            });

            Schema::table($tabela, function (Blueprint $table) use ($colunas) {
                foreach ($colunas as $coluna => $obrigatoria) {
                    $fk = $table->foreign($coluna)->references('id')->on('users')->cascadeOnUpdate();
                    $obrigatoria ? $fk->restrictOnDelete() : $fk->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::CHAVES as $tabela => $colunas) {
            if (! Schema::hasTable($tabela)) {
                continue;
            }

            Schema::table($tabela, function (Blueprint $table) use ($colunas) {
                foreach (array_keys($colunas) as $coluna) {
                    $table->dropForeign([$coluna]);
                }
            });

            Schema::table($tabela, function (Blueprint $table) use ($colunas) {
                foreach (array_keys($colunas) as $coluna) {
                    $table->foreign($coluna)->references('id')->on('users')->cascadeOnUpdate()->cascadeOnDelete();
                }
            });
        }
    }
};
