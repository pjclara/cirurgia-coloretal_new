<?php

namespace App\Services;

use App\Models\Complicacao;
use App\Models\Internamento;
use App\Models\Morbilidade;
use App\Models\Referenciacao;
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

            // Com cirurgia, a alta fica pendente até à revisão aos 30 dias.
            $tem30Dias = collect($dados['morbilidade']['30_dias']['complicacoes'] ?? [])->isNotEmpty();
            if (! $tem30Dias && $internamento->temCirurgias()) {
                $internamento->estado_da_alta_id = Internamento::ALTA_PENDENTE;
            }

            // Sem cirurgia não há revisão aos 30 dias: com data de alta, a alta fica logo concluída.
            if ($internamento->data_de_alta && ! $internamento->temCirurgias()) {
                $internamento->estado_da_alta_id = Internamento::ALTA_CONCLUIDA;
            }

            $internamento->save();

            $diagnosticos = collect($dados['diagnosticos'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $internamento->diagnosticos()->sync($diagnosticos->all());
            $this->associarDiagnosticosAoCentro($internamento, $diagnosticos->all());

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

    /**
     * Os diagnósticos do internamento marcados como «centro de referência» passam também para a referenciação ativa
     * do utente (a mais recente, sem saída), se ainda lá não estiverem e se o utilizador puder atualizar referenciações. O modo fica «Outro» e a histologia por
     * preencher, para completar na referenciação; nada se remove.
     *
     * @param  list<int>  $diagnosticos
     */
    private function associarDiagnosticosAoCentro(Internamento $internamento, array $diagnosticos): void
    {
        // Só quem pode atualizar referenciações altera os diagnósticos delas (a migration cobre o histórico).
        if ($diagnosticos === [] || ! auth()->user()?->can('update referenciacao')) {
            return;
        }

        $referenciacao = Referenciacao::where('utente_id', $internamento->utente_id)->whereNull('data_de_saida')
            ->orderByDesc('data_de_referenciacao')->orderByDesc('id')->first();
        if (! $referenciacao) {
            return;
        }

        $jaAssociados = $referenciacao->diagnosticos()->pluck('diagnosticos.id')->all();
        $novos = DB::table('diagnosticos')->whereIn('id', $diagnosticos)->where('centro_de_referencia', 1)
            ->whereNotIn('id', $jaAssociados)->pluck('id');
        if ($novos->isEmpty()) {
            return;
        }

        $referenciacao->diagnosticos()->attach($novos->mapWithKeys(fn ($id) => [
            (int) $id => ['modo_id' => Referenciacao::MODO_OUTRO, 'histologia_id' => null, 'data_diagnostico' => null],
        ])->all());
        $referenciacao->auditar('updated', ['diagnosticos' => $jaAssociados], ['diagnosticos' => array_merge($jaAssociados, $novos->map(fn ($id) => (int) $id)->all())]);
    }

    /**
     * Circuito simplificado: regista só a morbilidade aos 30 dias, sem tocar no resto do internamento.
     * O grau escolhido à mão prevalece; sem ele, calcula-se pelas resoluções.
     */
    public function registarSeguimento(Internamento $internamento, array $dados): Morbilidade
    {
        return DB::transaction(function () use ($internamento, $dados) {
            $antes = $this->resumoMorbilidade($internamento);

            $complicacoes = collect($dados['complicacoes'])
                ->mapWithKeys(fn ($c) => [(int) $c['id'] => array_map('strval', $c['resolucoes'] ?? [])]);
            $temReal = $complicacoes->keys()->contains(fn ($id) => $id !== Complicacao::SEM_COMPLICACOES);
            $clavien = null; // calculado a seguir, com as resoluções da alta e dos 30 dias

            $morbilidade = $internamento->morbilidades()->firstOrNew(['momento' => Morbilidade::AOS_30_DIAS]);
            $morbilidade->fill(['data_registo' => $dados['data_registo'], 'clavien_dindo_id' => $clavien])->save();
            $morbilidade->complicacoes()->sync(
                $complicacoes->map(fn ($resolucoes) => ['resolucao' => $resolucoes === [] ? null : json_encode(array_values($resolucoes))])->all()
            );

            Morbilidade::recalcularGrauDefinitivo($internamento);

            $depois = $this->resumoMorbilidade($internamento);
            if ($antes !== $depois) {
                $internamento->auditar('updated', ['morbilidade' => $antes], ['morbilidade' => $depois]);
            }

            // Revisão aos 30 dias feita: a alta de quem teve cirurgia deixa de estar pendente.
            if ((int) $internamento->estado_da_alta_id === Internamento::ALTA_PENDENTE && $internamento->data_de_alta && $internamento->temCirurgias()) {
                $internamento->update(['estado_da_alta_id' => Internamento::ALTA_CONCLUIDA]);
            }

            return $morbilidade;
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
            // Na alta não se guarda grau: o grau definitivo calcula-se aos 30 dias. Óbito com complicação é grau 5.
            $clavien = $temReal && $momento === Morbilidade::NA_ALTA && $obito ? Internamento::CLAVIEN_GRAU_5 : null;

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

        Morbilidade::recalcularGrauDefinitivo($internamento);
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
