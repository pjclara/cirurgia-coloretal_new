<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acrescenta à referenciação ao centro de referência a data da consulta seguinte (opcional).
 * Reversível: o `down` retira a coluna (e a data com ela). Faça um backup da base de dados antes de correr.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('referenciacao_centro_de_referencias') && ! Schema::hasColumn('referenciacao_centro_de_referencias', 'data_consulta_seguinte')) {
            Schema::table('referenciacao_centro_de_referencias', function (Blueprint $tabela) {
                $tabela->date('data_consulta_seguinte')->nullable()->after('data_de_saida');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('referenciacao_centro_de_referencias', 'data_consulta_seguinte')) {
            Schema::table('referenciacao_centro_de_referencias', function (Blueprint $tabela) {
                $tabela->dropColumn('data_consulta_seguinte');
            });
        }
    }
};
