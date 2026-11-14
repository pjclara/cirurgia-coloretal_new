<?php

namespace App\Services;

use App\Models\Complicacao;
use App\Models\Internamento;
use App\Models\Morbilidade;
use Illuminate\Support\Facades\DB;

/**
 * Grava um internamento com os seus diagnósticos e complicações numa única transação.
 */
class GuardarInternamento
{
    public function executar(Internamento $internamento, array $dados): Internamento
    {
        return DB::transaction(function () use ($internamento, $dados) {
            $diagnosticosAntes = $internamento->exists
                ? $internamento->diagnosticos()->pluck('diagnosticos.id')->sort()->values()->all()
                : [];
            $morbilidadeAntes = $internamento->exists ? $this->resumoMorbilidade($internamento) : [];

            $internamento->fill(collect($dados)->except(['diagnosticos', 'morbilidade'])->all());
            $internamento->save();

            $diagnosticos = collect($dados['diagnosticos'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $internamento->diagnosticos()->sync($diagnosticos->all());

            $this->gravarMorbilidade($internamento, $dados['morbilidade'] ?? [], (int) ($dados['destino_id'] ?? 0) === Internamento::DESTINO_FALECIDO);

            // As tabelas de ligação não geram eventos de modelo: auditam-se aqui.
            $morbilidadeDepois = $this->resumoMorbilidade($internamento);
            $antigos = [];
            $novos = [];
            if ($diagnosticosAntes !== $diagnosticos->all()) {
                $antigos['diagnosticos'] = $diagnosticosAntes;
                $novos['diagnosticos'] = $diagnosticos->all();
            }
            if ($morbilidadeAntes !== $morbilidadeDepois) {
                $antigos['morbilidade'] = $morbilidadeAntes;
                $novos['morbilidade'] = $morbilidadeDepois;
            }
            if ($novos !== [] && ! $internamento->wasRecentlyCreated) {
                $internamento->auditar('updated', $antigos, $novos);
            }

            return $internamento;
        });
    }

    /** Cria, atualiza ou remove o registo de cada momento; sem complicações escolhidas, o momento fica por registar. */
    private function gravarMorbilidade(Internamento $internamento, array $blocos, bool $obito): void
    {
        foreach (Morbilidade::MOMENTOS as $momento) {
            $complicacoes = collect($blocos[$momento]['complicacoes'] ?? [])
                ->mapWithKeys(fn ($c) => [(int) $c['id'] => array_map('strval', $c['resolucoes'] ?? [])]);

            if ($complicacoes->isEmpty()) {
                $internamento->morbilidades()->where('momento', $momento)->get()->each->delete();

                continue;
            }

            $temReal = $complicacoes->keys()->contains(fn ($id) => $id !== Complicacao::SEM_COMPLICACOES);
            // Clavien-Dindo só existe quando há complicação; óbito com complicação na alta é grau 5.
            $clavien = ! $temReal ? null : ($momento === Morbilidade::NA_ALTA && $obito
                ? Internamento::CLAVIEN_GRAU_5
                : ($blocos[$momento]['clavien_dindo_id'] ?? null));

            $morbilidade = $internamento->morbilidades()->firstOrNew(['momento' => $momento]);
            $morbilidade->fill([
                'data_registo' => $blocos[$momento]['data_registo'] ?? null,
                'clavien_dindo_id' => $clavien,
            ])->save();

            $morbilidade->complicacoes()->sync(
                $complicacoes->map(fn ($resolucoes) => [
                    'resolucao' => $resolucoes === [] ? null : json_encode(array_values($resolucoes)),
                ])->all()
            );
        }
    }

    /** Forma comparável do estado da morbilidade, para auditar alterações. */
    private function resumoMorbilidade(Internamento $internamento): array
    {
        return $internamento->morbilidades()->with('complicacoes:id')->get()
            ->sortBy('momento')
            ->mapWithKeys(fn (Morbilidade $m) => [$m->momento => [
                'complicacoes' => $m->complicacoes->pluck('id')->sort()->values()->all(),
                'clavien' => $m->clavien_dindo_id,
            ]])
            ->all();
    }
}
