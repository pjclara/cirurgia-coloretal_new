<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelas de ligação do internamento, bloco e lista de espera:
 *  - guarda em `registos_orfaos` as linhas que apontam para registos inexistentes e remove-as;
 *  - cria chave primária composta onde não existe;
 *  - cria as chaves estrangeiras em falta.
 *
 * As restantes tabelas de ligação (centro de referência) ficam para a entrega desse módulo.
 */
return new class extends Migration
{
    /**
     * tabela => [ [coluna, tabela pai, apagar em cascata?], ... , 'pk' => bool ]
     */
    private function ligacoes(): array
    {
        return [
            'complicacao_internamento' => [
                'pk' => true,
                'fks' => [['complicacao_id', 'complicacaos', false], ['internamento_id', 'internamentos', true]],
            ],
            'diagnostico_internamento' => [
                'pk' => true,
                'fks' => [['diagnostico_id', 'diagnosticos', false], ['internamento_id', 'internamentos', true]],
            ],
            'diagnostico_lista_de_espera' => [
                'pk' => true,
                'fks' => [['diagnostico_id', 'diagnosticos', false], ['lista_de_espera_id', 'lista_de_esperas', true]],
            ],
            'intervencao_lista_de_espera' => [
                'pk' => false, // já tem `id`
                'fks' => [['intervencao_id', 'intervencaos', false], ['lista_de_espera_id', 'lista_de_esperas', true]],
            ],
            'bloco_operatorio_intervencao' => [
                'pk' => false, // já tem `id`
                'fks' => [['intervencao_id', 'intervencaos', false], ['bloco_operatorio_id', 'bloco_operatorios', true]],
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('registos_orfaos')) {
            Schema::create('registos_orfaos', function (Blueprint $table) {
                $table->id();
                $table->string('tabela');
                $table->string('motivo');
                $table->json('dados');
                $table->timestamp('created_at')->useCurrent();
            });
        }

        foreach ($this->ligacoes() as $tabela => $definicao) {
            // 1. Órfãos: cópia de segurança e remoção.
            foreach ($definicao['fks'] as [$coluna, $pai]) {
                $orfaos = DB::table($tabela)
                    ->whereNotExists(function ($q) use ($tabela, $coluna, $pai) {
                        $q->select(DB::raw(1))->from($pai)->whereColumn("$pai.id", "$tabela.$coluna");
                    })
                    ->get();

                foreach ($orfaos as $linha) {
                    DB::table('registos_orfaos')->insert([
                        'tabela' => $tabela,
                        'motivo' => "$coluna sem registo em $pai",
                        'dados' => json_encode($linha, JSON_UNESCAPED_UNICODE),
                    ]);
                }

                if ($orfaos->isNotEmpty()) {
                    DB::table($tabela)
                        ->whereNotExists(function ($q) use ($tabela, $coluna, $pai) {
                            $q->select(DB::raw(1))->from($pai)->whereColumn("$pai.id", "$tabela.$coluna");
                        })
                        ->delete();
                }
            }

            // 2. Chave primária composta (só nas tabelas sem `id`).
            if ($definicao['pk']) {
                $colunas = array_map(fn ($fk) => $fk[0], $definicao['fks']);

                $duplicados = DB::table($tabela)
                    ->select($colunas)
                    ->groupBy($colunas)
                    ->havingRaw('COUNT(*) > 1')
                    ->get()
                    ->count();

                if ($duplicados > 0) {
                    throw new RuntimeException(
                        "A tabela $tabela tem $duplicados pares duplicados. Remova-os antes de voltar a correr a migração."
                    );
                }

                Schema::table($tabela, fn (Blueprint $table) => $table->primary($colunas));
            }

            // 3. Chaves estrangeiras.
            Schema::table($tabela, function (Blueprint $table) use ($definicao) {
                foreach ($definicao['fks'] as [$coluna, $pai, $cascata]) {
                    $fk = $table->foreign($coluna)->references('id')->on($pai);
                    $cascata ? $fk->cascadeOnDelete() : $fk->restrictOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->ligacoes() as $tabela => $definicao) {
            Schema::table($tabela, function (Blueprint $table) use ($definicao) {
                foreach ($definicao['fks'] as [$coluna]) {
                    $table->dropForeign([$coluna]);
                }
            });

            if ($definicao['pk']) {
                Schema::table($tabela, fn (Blueprint $table) => $table->dropPrimary());
            }
        }
        // `registos_orfaos` fica: é a única cópia das linhas removidas.
    }
};
