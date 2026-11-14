<?php

namespace App\Services;

use App\Models\Complicacao;
use App\Models\Internamento;
use Illuminate\Support\Facades\DB;

/**
 * Grava um internamento com os seus diagnósticos e complicações numa única transação.
 */
class GuardarInternamento
{
    public function executar(Internamento $internamento, array $dados): Internamento
    {
        return DB::transaction(function () use ($internamento, $dados) {
            $complicacoes = collect($dados['complicacoes'] ?? [])
                ->mapWithKeys(fn ($c) => [(int) $c['id'] => array_map('strval', $c['resolucoes'] ?? [])]);

            $temComplicacaoReal = $complicacoes->keys()
                ->contains(fn ($id) => $id !== Complicacao::SEM_COMPLICACOES);

            // Clavien-Dindo só existe quando há complicação; óbito com complicação é grau 5.
            if (! $temComplicacaoReal) {
                $dados['clavien_dindo_id'] = null;
            } elseif ((int) ($dados['destino_id'] ?? 0) === Internamento::DESTINO_FALECIDO) {
                $dados['clavien_dindo_id'] = Internamento::CLAVIEN_GRAU_5;
            }

            $diagnosticosAntes = $internamento->exists
                ? $internamento->diagnosticos()->pluck('diagnosticos.id')->sort()->values()->all()
                : [];
            $complicacoesAntes = $internamento->exists
                ? $internamento->complicacoes()->pluck('complicacaos.id')->sort()->values()->all()
                : [];

            $internamento->fill(collect($dados)->except(['diagnosticos', 'complicacoes'])->all());
            $internamento->save();

            $diagnosticos = collect($dados['diagnosticos'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $internamento->diagnosticos()->sync($diagnosticos->all());

            $internamento->complicacoes()->sync(
                $complicacoes->map(fn ($resolucoes) => [
                    'resolucao' => $resolucoes === [] ? null : json_encode(array_values($resolucoes)),
                ])->all()
            );

            // As tabelas de ligação não geram eventos de modelo: auditam-se aqui.
            $complicacoesDepois = $complicacoes->keys()->sort()->values()->all();
            $antigos = [];
            $novos = [];
            if ($diagnosticosAntes !== $diagnosticos->all()) {
                $antigos['diagnosticos'] = $diagnosticosAntes;
                $novos['diagnosticos'] = $diagnosticos->all();
            }
            if ($complicacoesAntes !== $complicacoesDepois) {
                $antigos['complicacoes'] = $complicacoesAntes;
                $novos['complicacoes'] = $complicacoesDepois;
            }
            if ($novos !== [] && ! $internamento->wasRecentlyCreated) {
                $internamento->auditar('updated', $antigos, $novos);
            }

            return $internamento;
        });
    }
}
