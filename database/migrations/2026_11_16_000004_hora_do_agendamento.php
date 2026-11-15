<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hora da cirurgia no agendamento, para ordenar os doentes por dia, sala e hora.
 *
 * A hora guarda-se numa coluna própria e não em `start`: nos registos antigos a hora de `start` não é fiável
 * (muitos a 00:00, centenas a 01:00 ou 08:00, e outras horas soltas). `start` continua a ser só o dia.
 * Os agendamentos existentes ficam sem hora (null) e vão para o fim da ordenação do dia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agendamentos', 'hora')) {
            Schema::table('agendamentos', function (Blueprint $tabela) {
                $tabela->time('hora')->nullable()->after('periodo_de_agendamento_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agendamentos', 'hora')) {
            Schema::table('agendamentos', function (Blueprint $tabela) {
                $tabela->dropColumn('hora');
            });
        }
    }
};
