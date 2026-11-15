<?php

namespace App\Services;

use App\Models\Agendamento;
use App\Models\ListaDeEspera;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mantém coerentes a cirurgia (bloco operatório), o agendamento e a lista de espera:
 * se o utente tem uma cirurgia registada por volta do dia agendado, o agendamento passa a "Operado"
 * e a inscrição na lista de espera também.
 *
 * Correspondência (a mesma da migração que corrigiu os registos antigos):
 *  - o mesmo utente (agendamento -> lista de espera -> utente; cirurgia -> internamento -> utente);
 *  - cirurgia até TOLERANCIA_DIAS dias antes ou depois do dia agendado, e já realizada (não futura);
 *  - cada cirurgia fica associada a um só agendamento: o mais próximo da sua data (empate: o mais antigo);
 *  - agendamentos cancelados nunca passam a operados; os já operados ficam como estão.
 *
 * Só avança (marca operados). Apagar ou mudar uma cirurgia não repõe o agendamento, porque o estado
 * anterior não se sabe e pode ter sido posto à mão.
 */
class SincronizarOperados
{
    public const TOLERANCIA_DIAS = 3;

    /** Marca como operados os agendamentos do utente que têm cirurgia registada; devolve os que mudaram. */
    public function paraUtente(int $utenteId): Collection
    {
        $agendamentos = Agendamento::query()
            ->with('espera')
            ->whereIn('lista_de_espera_id', ListaDeEspera::query()->where('utente_id', $utenteId)->select('id'))
            ->orderBy('start')->orderBy('id')
            ->get()
            ->reject(fn (Agendamento $a) => $a->estado() === ListaDeEspera::ESTADO_CANCELADO)
            ->values();

        if ($agendamentos->isEmpty()) {
            return collect();
        }

        $datas = DB::table('bloco_operatorios as b')->join('internamentos as i', 'i.id', '=', 'b.internamento_id')
            ->where('i.utente_id', $utenteId)->whereNull('b.deleted_at')->whereNull('i.deleted_at')
            ->where('b.data_de_inicio', '<=', now()->format('Y-m-d'))
            ->orderBy('b.data_de_inicio')->orderBy('b.id')
            ->pluck('b.data_de_inicio');

        $usados = [];
        $marcar = collect();
        foreach ($datas as $data) {
            $dia = strtotime(substr((string) $data, 0, 10));
            $melhor = null;
            $melhorDistancia = PHP_INT_MAX;
            foreach ($agendamentos as $a) {
                if (isset($usados[$a->id])) {
                    continue;
                }
                $distancia = abs((int) round(($a->start->copy()->startOfDay()->timestamp - $dia) / 86400));
                if ($distancia <= self::TOLERANCIA_DIAS && $distancia < $melhorDistancia) {
                    $melhor = $a;
                    $melhorDistancia = $distancia;
                }
            }
            if ($melhor) {
                $usados[$melhor->id] = true;
                if ($melhor->estado() !== ListaDeEspera::ESTADO_OPERADO) {
                    $marcar->push($melhor);
                }
            }
        }

        $this->marcarOperados($marcar);

        return $marcar;
    }

    /**
     * Passa os agendamentos a "Operado" e a inscrição na lista de espera também.
     *
     * @param  iterable<Agendamento>  $agendamentos
     */
    public function marcarOperados(iterable $agendamentos): void
    {
        DB::transaction(function () use ($agendamentos) {
            foreach ($agendamentos as $agendamento) {
                // O campo antigo só admite 1 a 4: Operado guarda-se lá como Agendado; vale o estado_de_agendamento_id.
                $agendamento->update([
                    'estado_de_agendamento_id' => ListaDeEspera::ESTADO_OPERADO,
                    'estado_de_agendamento' => (string) ListaDeEspera::ESTADO_AGENDADO,
                ]);
                $agendamento->espera?->update(['estado_lista_espera' => (string) ListaDeEspera::ESTADO_OPERADO]);
            }
        });
    }
}
