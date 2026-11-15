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
 * Estado do agendamento -> estado da lista: o mesmo (1 Proposto, 2 Pendente, 3 Agendado, 4 Cancelado, 5 Operado, 6 Outro),
 * o que exige a coluna alargada por 2026_11_16_000002.
 *
 * O estado do agendamento está em `estado_de_agendamento_id` (registos novos) ou, nos antigos, em `estado_de_agendamento`.
 *
 * Reversível: guarda o estado anterior de cada inscrição alterada em `lista_de_espera_estado_anterior`.
 * Pode correr mais do que uma vez (por exemplo depois de 2026_11_16_000001 marcar agendamentos como operados):
 * a tabela de cópia reaproveita-se e guarda sempre o estado ORIGINAL, o do primeiro alinhamento.
 * Faça um backup da base de dados antes de correr.
 */
return new class extends Migration
{
    /** estado do agendamento => estado da lista de espera */
    private const MAPA = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6];

    public function up(): void
    {
        if (! Schema::hasTable('lista_de_espera_estado_anterior')) {
            Schema::create('lista_de_espera_estado_anterior', function (Blueprint $tabela) {
                $tabela->unsignedBigInteger('lista_de_espera_id')->primary();
                $tabela->string('anterior', 1);
                $tabela->string('novo', 1);
                $tabela->timestamp('alterado_em')->useCurrent();
            });
        }

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
     * `$guardarAnterior` regista o estado antigo para o `down()` (nos testes não se cria a tabela);
     * `$simular` só conta, sem gravar.
     */
    public function alinhar(bool $guardarAnterior, bool $simular = false): int
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
                ->each(function ($lista) use ($efetivo, $guardarAnterior, $simular, &$alteradas) {
                    $novo = self::MAPA[$efetivo[$lista->id]] ?? null;
                    if ($novo === null || (int) $lista->estado_lista_espera === $novo) {
                        return;
                    }

                    $alteradas++;
                    if ($simular) {
                        return;
                    }

                    if ($guardarAnterior) {
                        // Se já houve um alinhamento anterior, mantém-se o estado original e só se actualiza o "novo".
                        DB::table('lista_de_espera_estado_anterior')->upsert([[
                            'lista_de_espera_id' => $lista->id,
                            'anterior' => (string) $lista->estado_lista_espera,
                            'novo' => (string) $novo,
                        ]], ['lista_de_espera_id'], ['novo']);
                    }
                    DB::table('lista_de_esperas')->where('id', $lista->id)->update(['estado_lista_espera' => (string) $novo]);
                });
        }

        return $alteradas;
    }
};
