<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Cirurgias (bloco operatório) agrupadas por internamento, para quadro e ficha do utente. */
class Cirurgias
{
    /**
     * @param  mixed  $internamentoIds  lista de ids ou subconsulta
     * @return Collection<int, Collection> internamento_id => lista de cirurgias
     */
    public static function porInternamento(mixed $internamentoIds): Collection
    {
        $blocos = DB::table('bloco_operatorios as b')
            ->leftJoin('tipo_de_cirurgias as t', 't.id', '=', 'b.tipo_de_cirurgia_id')
            ->whereNull('b.deleted_at')
            ->whereIn('b.internamento_id', $internamentoIds)
            ->orderBy('b.data_de_inicio')
            ->get(['b.id', 'b.internamento_id', 'b.data_de_inicio', 't.nome as tipo']);

        $intervencoes = DB::table('bloco_operatorio_intervencao as bi')
            ->join('intervencaos as i', 'i.id', '=', 'bi.intervencao_id')
            ->whereIn('bi.bloco_operatorio_id', $blocos->pluck('id'))
            ->get(['bi.bloco_operatorio_id', 'i.nome', 'i.abrev'])
            ->groupBy('bloco_operatorio_id');

        return $blocos->groupBy('internamento_id')->map(fn ($linhas) => $linhas->map(fn ($c) => [
            'id' => $c->id,
            'data' => $c->data_de_inicio,
            'tipo' => $c->tipo,
            'intervencoes' => ($intervencoes[$c->id] ?? collect())->map(fn ($i) => trim($i->abrev ?: $i->nome))->values(),
        ])->values());
    }
}
