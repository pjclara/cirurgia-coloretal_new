<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retira a coluna `data_consulta_seguinte` das referenciações, se existir: a consulta seguinte passou a ser a data de
 * entrada no centro de referência (campo que já existia), por isso não há um campo à parte. Só interessa às bases onde
 * uma versão intermédia a chegou a criar; nas restantes não faz nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('referenciacao_centro_de_referencias', 'data_consulta_seguinte')) {
            Schema::table('referenciacao_centro_de_referencias', function (Blueprint $tabela) {
                $tabela->dropColumn('data_consulta_seguinte');
            });
        }
    }

    public function down(): void
    {
        // Sem volta: o campo deixou de existir.
    }
};
