<?php

use App\Support\DiagnosticosCr;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Sincroniza os diagnósticos «centro de referência» entre os internamentos e as referenciações de todos os utentes
 * referenciados, pela mesma regra que a aplicação já aplica sozinha a cada alteração (`App\Support\DiagnosticosCr`):
 *  - internamentos -> referenciação ativa mais recente (o que lhe falta; modo «Outro»);
 *  - referenciações -> internamentos com cirurgia que ainda não têm nenhum diagnóstico CR.
 *
 * Só acrescenta, nunca remove, e pode correr-se mais do que uma vez. Não tem reversão: o que a aplicação mantém
 * sozinha não se desfaz. Também se pode correr à mão: `php artisan diagnosticos-cr:sincronizar`.
 * Faça um backup da base de dados antes de correr.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['diagnostico_internamento', 'diagnostico_referenciacao', 'referenciacao_centro_de_referencias', 'bloco_operatorios'] as $tabela) {
            if (! Schema::hasTable($tabela)) {
                return;
            }
        }
        if (! Schema::hasColumn('diagnosticos', 'centro_de_referencia')) {
            return;
        }

        $r = DiagnosticosCr::sincronizarTodos();
        echo "  Diagnósticos CR: {$r['utentes']} utentes verificados, {$r['referenciacoes']} diagnósticos acrescentados a referenciações, {$r['internamentos']} internamentos atualizados.\n";
    }

    public function down(): void
    {
        // Sem volta: a aplicação mantém esta sincronização sozinha.
    }
};
