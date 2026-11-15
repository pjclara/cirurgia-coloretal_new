<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A lista de espera passa a poder ter os mesmos estados do agendamento (estado_de_agendamentos):
 * 1 Proposto, 2 Pendente, 3 Agendado, 4 Cancelado, 5 Operado, 6 Outro.
 * Até agora a coluna só admitia 1 a 4, por isso quem era operado ficava na lista como "Agendado".
 *
 * Alarga só o conjunto de valores permitidos; não altera nenhuma inscrição.
 * Corre antes de 2026_11_16_000003, que alinha o estado da lista com o do agendamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE lista_de_esperas MODIFY estado_lista_espera ENUM('1','2','3','4','5','6') NOT NULL DEFAULT '1'");
    }

    public function down(): void
    {
        // Sem equivalente em 1 a 4: Operado volta a Agendado e Outro a Cancelado (já não estão por agendar).
        DB::table('lista_de_esperas')->where('estado_lista_espera', '5')->update(['estado_lista_espera' => '3']);
        DB::table('lista_de_esperas')->where('estado_lista_espera', '6')->update(['estado_lista_espera' => '4']);

        DB::statement("ALTER TABLE lista_de_esperas MODIFY estado_lista_espera ENUM('1','2','3','4') NOT NULL DEFAULT '1'");
    }
};
