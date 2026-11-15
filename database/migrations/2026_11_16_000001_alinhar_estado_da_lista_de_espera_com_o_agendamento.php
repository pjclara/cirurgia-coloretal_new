<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alinha `lista_de_esperas.estado_lista_espera` com o estado do agendamento de cada inscrição.
 *
 * Que agendamento conta: o mais recente que não esteja cancelado; se todos estiverem cancelados, o mais recente.
 * (Assim um reagendamento depois de um cancelamento não deixa a inscrição como cancelada.)
 * Inscrições sem agendamento ficam como estão, tal como as apagadas.
 *
 * Estado do agendamento -> estado da lista (a coluna da lista só admite 1 a 4):
 *   1 Proposto -> 1 · 2 Pendente -> 2 · 3 Agendado -> 3 · 4 Cancelado -> 4
 *   5 Operado  -> 3 (já não está por agendar; a lista não tem "Operado")
 *   6 Outro    -> não altera
 *
 * O estado do agendamento está em `estado_de_agendamento_id` (registos novos) ou, nos antigos, em `estado_de_agendamento`.
 *
 * Reversível: guarda o estado anterior de cada inscrição alterada em `lista_de_espera_estado_anterior`.
 * Faça um backup da base de dados antes de correr.
 */
return new class extends Migration
{
    /** estado do agendamento => estado da lista de espera */
    private const MAPA = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 3];

    public function up(): void
    {
        Schema::create('lista_de_espera_estado_anterior', function (Blueprint $tabela) {
            $tabela->unsignedBigInteger('lista_de_espera_id')->primary();
            $tabela->string('anterior', 1);
            $tabela->string('novo', 1);
            $tabela->timestamp('alterado_em')->useCurrent();
        });

        $this->alinhar(true);
    }

    public function down(): void
    {
        if (Schema::hasTable('lista_de_espera_estado_anterior')) {
            DB::table('lista_de_espera_estado_anterior')->orderBy('lista_de_espera_id')->each(
                fn ($l) => DB::table('lista_de_esperas')->where('id', $l->lista_de_espera_id)->update(['estado_lista_espera' => $l->anterior])
            );
        }

        Schema::dropIfExists('lista_de_espera_estado_anterior');
    }

    /**
     * Faz o alinhamento e devolve quantas inscrições mudaram de estado.
     * `$guardarAnterior` regista o estado antigo para o `down()` (nos testes não se cria a tabela).
     */
    public function alinhar(bool $guardarAnterior): int
    {
        // Do mais antigo para o mais recente: o último vence, mas um cancelado nunca substitui um que não o esteja.
        $efetivo = [];
        DB::table('agendamentos')->whereNull('deleted_at')->orderBy('start')->orderBy('id')
            ->get(['lista_de_espera_id', 'estado_de_agendamento', 'estado_de_agendamento_id'])
            ->each(function ($a) use (&$efetivo) {
                $estado = (int) ($a->estado_de_agendamento_id ?? $a->estado_de_agendamento);
                $atual = $efetivo[$a->lista_de_espera_id] ?? null;
                if ($estado === 4 && $atual !== null && $atual !== 4) {
                    return;
                }
                $efetivo[$a->lista_de_espera_id] = $estado;
            });

        $alteradas = 0;
        foreach (array_chunk(array_keys($efetivo), 500) as $ids) {
            DB::table('lista_de_esperas')->whereNull('deleted_at')->whereIn('id', $ids)->get(['id', 'estado_lista_espera'])
                ->each(function ($lista) use ($efetivo, $guardarAnterior, &$alteradas) {
                    $novo = self::MAPA[$efetivo[$lista->id]] ?? null;
                    if ($novo === null || (int) $lista->estado_lista_espera === $novo) {
                        return;
                    }

                    if ($guardarAnterior) {
                        DB::table('lista_de_espera_estado_anterior')->insert([
                            'lista_de_espera_id' => $lista->id,
                            'anterior' => (string) $lista->estado_lista_espera,
                            'novo' => (string) $novo,
                        ]);
                    }
                    DB::table('lista_de_esperas')->where('id', $lista->id)->update(['estado_lista_espera' => (string) $novo]);
                    $alteradas++;
                });
        }

        return $alteradas;
    }
};
