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
            ->get(['bi.id as pivot_id', 'bi.intervencao_id', 'bi.bloco_operatorio_id', 'i.nome', 'i.abrev'])
            ->groupBy('bloco_operatorio_id');
        $emFalta = self::crEmFalta($blocos, $intervencoes);

        return $blocos->groupBy('internamento_id')->map(fn ($linhas) => $linhas->map(fn ($c) => [
            'id' => $c->id,
            'data' => $c->data_de_inicio,
            'tipo' => $c->tipo,
            'intervencoes' => ($intervencoes[$c->id] ?? collect())->map(fn ($i) => trim($i->abrev ?: $i->nome))->values(),
            // Dados do centro de referência por preencher ('cr', 'descricao'); vazio se não se aplicam ou estão completos.
            'cr_em_falta' => $emFalta[$c->id] ?? [],
        ])->values());
    }

    /**
     * Das cirurgias a que se aplicam os dados do CR (doente referenciado até à cirurgia, com ressecção), o que ainda falta preencher.
     *
     * @return array<int, list<string>> bloco_id => ['cr'?, 'descricao'?]
     */
    public static function crEmFalta(Collection $blocos, Collection $intervencoesPorBloco): array
    {
        if ($blocos->isEmpty()) {
            return [];
        }
        $utentes = DB::table('internamentos')->whereIn('id', $blocos->pluck('internamento_id')->unique())->pluck('utente_id', 'id');
        $desde = DB::table('referenciacao_centro_de_referencias')
            ->whereIn('utente_id', $utentes->unique())->whereNull('deleted_at')
            ->groupBy('utente_id')->selectRaw('utente_id, min(data_de_referenciacao) as desde')->pluck('desde', 'utente_id');
        $comCr = DB::table('bloco_operatorio_c_r_s')->whereNull('deleted_at')->whereIn('bloco_operatorio_id', $blocos->pluck('id'))->pluck('bloco_operatorio_id')->flip();
        $comDescricao = DB::table('intervencao_descricaos as d')
            ->join('bloco_operatorio_intervencao as p', 'p.id', '=', 'd.intervencao_id')
            ->whereNull('d.deleted_at')->whereIn('p.bloco_operatorio_id', $blocos->pluck('id'))
            ->pluck('p.bloco_operatorio_id')->flip();

        $resultado = [];
        foreach ($blocos as $c) {
            $referenciado = $desde[$utentes[$c->internamento_id] ?? 0] ?? null;
            $ids = ($intervencoesPorBloco[$c->id] ?? collect())->pluck('intervencao_id')->all();
            if (! $referenciado || substr((string) $referenciado, 0, 10) > substr((string) $c->data_de_inicio, 0, 10)
                || array_intersect(array_map('intval', $ids), CirurgiaCr::RESSECCOES) === []) {
                continue;
            }
            $falta = array_values(array_filter([isset($comCr[$c->id]) ? null : 'cr', isset($comDescricao[$c->id]) ? null : 'descricao']));
            if ($falta !== []) {
                $resultado[$c->id] = $falta;
            }
        }

        return $resultado;
    }
}
