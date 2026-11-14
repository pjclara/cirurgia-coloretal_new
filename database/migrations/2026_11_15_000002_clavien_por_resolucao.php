<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada resolução de complicação fica associada a um grau de Clavien-Dindo, para o grau
 * ser calculado automaticamente (o mais alto entre as resoluções escolhidas).
 *
 * A associação é uma PROPOSTA baseada na definição de Clavien-Dindo e deve ser validada
 * clinicamente. Corrige-se na própria tabela (`resolucao_complicacaos.clavien_dindo_id`);
 * `null` = sem grau automático (o grau escolhe-se à mão).
 *
 * ids de clavien_dindos: 1 = grau 1, 2 = grau 2, 3 = 3A, 4 = 3B, 5 = 4A, 6 = 4B, 7 = grau 5.
 */
return new class extends Migration
{
    /** resolucao_complicacaos.id => clavien_dindos.id */
    private const PROPOSTA = [
        // Grau 1: sem tratamento farmacológico nem intervenção (permitidos diuréticos, eletrólitos, cuidados à cabeceira).
        4 => 1,    // Vigilância
        101 => 1,  // Diuréticos
        104 => 1,  // Colocação de SNG
        105 => 1,  // Correcção hidroelectrolítica
        106 => 1,  // Cuidado de pensos
        109 => 1,  // Lavagem vesical
        110 => 1,  // Algaliação
        115 => 1,  // Pensos
        // Grau 2: tratamento farmacológico, transfusão.
        3 => 2,    // Antibioterapia
        107 => 2,  // Transfusão de GV
        // Grau 3A: intervenção sem anestesia geral.
        2 => 3,    // Drenagem imagiológica
        111 => 3,  // Drenagem no leito
        112 => 3,  // Vacuoterapia
        // Grau 3B: intervenção sob anestesia geral.
        1 => 4,    // Drenagem cirúrgica
        102 => 4,  // Drenagem + ileostomia
        108 => 4,  // Intervenção cirúrgica
        // Grau 4A: falência de um órgão, cuidados intensivos.
        100 => 5,  // Ventilação não invasiva
        103 => 5,  // Internamento na SMI
        113 => 5,  // Ventilação invasiva
        // Sem proposta (grau manual): 99 Outra, 114 Sem resolução.
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('resolucao_complicacaos', 'clavien_dindo_id')) {
            Schema::table('resolucao_complicacaos', function (Blueprint $table) {
                $table->unsignedBigInteger('clavien_dindo_id')->nullable()->after('nome');
                $table->foreign('clavien_dindo_id')->references('id')->on('clavien_dindos');
            });
        }

        foreach (self::PROPOSTA as $resolucao => $grau) {
            DB::table('resolucao_complicacaos')->where('id', $resolucao)->update(['clavien_dindo_id' => $grau]);
        }
    }

    public function down(): void
    {
        Schema::table('resolucao_complicacaos', function (Blueprint $table) {
            $table->dropForeign(['clavien_dindo_id']);
            $table->dropColumn('clavien_dindo_id');
        });
    }
};
