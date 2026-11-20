<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Acrescenta a abordagem «Robótica» às abordagens cirúrgicas. Se já existir, não faz nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('tipo_de_abordagems')->where('nome', 'Robótica')->exists()) {
            DB::table('tipo_de_abordagems')->insert(['nome' => 'Robótica']);
        }
    }

    public function down(): void
    {
        $id = DB::table('tipo_de_abordagems')->where('nome', 'Robótica')->value('id');

        if ($id && ! DB::table('bloco_operatorios')->where('tipo_de_abordagem_id', $id)->exists()) {
            DB::table('tipo_de_abordagems')->where('id', $id)->delete();
        }
    }
};
