<?php

namespace App\Support;

use App\Models\Internamento;
use App\Models\ListaDeEspera;
use App\Models\Referenciacao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Diagnósticos «centro de referência» já registados nos internamentos de um utente e a sua passagem para a referenciação. */
class DiagnosticosCr
{
    /** Estados da lista de espera em que a inscrição ainda conta: proposto, pendente e agendado. */
    private const ESTADOS_ATIVOS = [1, 2, 3];

    /**
     * Os diagnósticos CR registados nos internamentos não apagados do utente e nas suas inscrições ativas em lista de espera.
     *
     * @return Collection<int, object{id: int, nome: string}>
     */
    public static function doUtente(int $utenteId): Collection
    {
        $internamentos = DB::table('diagnosticos as d')
            ->join('diagnostico_internamento as di', 'di.diagnostico_id', '=', 'd.id')
            ->join('internamentos as i', 'i.id', '=', 'di.internamento_id')
            ->where('i.utente_id', $utenteId)->whereNull('i.deleted_at')->where('d.centro_de_referencia', 1)
            ->select('d.id', 'd.nome');
        $esperas = DB::table('diagnosticos as d')
            ->join('diagnostico_lista_de_espera as dl', 'dl.diagnostico_id', '=', 'd.id')
            ->join('lista_de_esperas as l', 'l.id', '=', 'dl.lista_de_espera_id')
            ->where('l.utente_id', $utenteId)->whereNull('l.deleted_at')->where('d.centro_de_referencia', 1)
            ->whereIn(DB::raw('CAST(l.estado_lista_espera AS UNSIGNED)'), self::ESTADOS_ATIVOS)
            ->select('d.id', 'd.nome');

        return $internamentos->union($esperas)->get()->sortBy('nome')->unique('id')->values()
            ->map(fn ($d) => (object) ['id' => (int) $d->id, 'nome' => trim($d->nome)]);
    }

    /**
     * Acrescenta à referenciação os diagnósticos CR (dos ids dados) que ainda não tem. O modo fica «Outro» e a histologia
     * e a data por preencher; nada se remove. Devolve os ids acrescentados.
     *
     * @param  iterable<int>  $ids
     * @return list<int>
     */
    public static function associar(Referenciacao $referenciacao, iterable $ids): array
    {
        $jaAssociados = $referenciacao->diagnosticos()->pluck('diagnosticos.id')->all();
        $novos = DB::table('diagnosticos')->whereIn('id', collect($ids)->all())->where('centro_de_referencia', 1)
            ->whereNotIn('id', $jaAssociados)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($novos === []) {
            return [];
        }

        $referenciacao->diagnosticos()->attach(collect($novos)->mapWithKeys(fn ($id) => [
            $id => ['modo_id' => Referenciacao::MODO_OUTRO, 'histologia_id' => null, 'data_diagnostico' => null],
        ])->all());
        $referenciacao->auditar('updated', ['diagnosticos' => $jaAssociados], ['diagnosticos' => array_merge($jaAssociados, $novos)]);

        return $novos;
    }

    /**
     * Internamento -> referenciação: os diagnósticos CR dos internamentos do utente passam para a sua referenciação ativa
     * (a mais recente, sem saída), se ainda lá não estiverem. Devolve os ids acrescentados.
     *
     * @return list<int>
     */
    public static function levarParaReferenciacao(int $utenteId): array
    {
        $referenciacao = Referenciacao::where('utente_id', $utenteId)->whereNull('data_de_saida')
            ->orderByDesc('data_de_referenciacao')->orderByDesc('id')->first();

        return $referenciacao ? self::associar($referenciacao, self::doUtente($utenteId)->pluck('id')) : [];
    }

    /**
     * Referenciação -> internamentos: os diagnósticos CR das referenciações do utente passam para os seus internamentos com
     * cirurgia que ainda não têm nenhum diagnóstico CR (os sem cirurgia podem ser de outra causa; o que já tem diagnóstico
     * CR fica). Com `$soInternamento`, só esse internamento. Devolve o número de internamentos alterados.
     */
    public static function levarParaInternamentos(int $utenteId, ?int $soInternamento = null): int
    {
        $ids = self::idsDasReferenciacoes($utenteId);
        if ($ids === []) {
            return 0;
        }

        $internamentos = DB::table('internamentos as i')->where('i.utente_id', $utenteId)->whereNull('i.deleted_at')
            ->when($soInternamento, fn ($q, $id) => $q->where('i.id', $id))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('bloco_operatorios as b')->whereColumn('b.internamento_id', 'i.id')->whereNull('b.deleted_at'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('diagnostico_internamento as di')
                ->join('diagnosticos as d', 'd.id', '=', 'di.diagnostico_id')->whereColumn('di.internamento_id', 'i.id')->where('d.centro_de_referencia', 1))
            ->pluck('i.id');

        foreach ($internamentos as $id) {
            DB::table('diagnostico_internamento')->insert(collect($ids)->map(fn ($d) => ['internamento_id' => $id, 'diagnostico_id' => $d])->all());
            Internamento::find($id)?->auditar('updated', ['diagnosticos' => []], ['diagnosticos' => $ids]);
        }

        return $internamentos->count();
    }

    /** @return list<int> os diagnósticos CR das referenciações não apagadas do utente. */
    private static function idsDasReferenciacoes(int $utenteId): array
    {
        return DB::table('diagnostico_referenciacao as dr')
            ->join('referenciacao_centro_de_referencias as r', 'r.id', '=', 'dr.referenciacao_id')
            ->join('diagnosticos as d', 'd.id', '=', 'dr.diagnostico_id')
            ->where('r.utente_id', $utenteId)->whereNull('r.deleted_at')->where('d.centro_de_referencia', 1)
            ->distinct()->pluck('dr.diagnostico_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Referenciação -> lista de espera: as inscrições ativas do utente que ainda não têm nenhum diagnóstico CR recebem os
     * das suas referenciações. Com `$soEspera`, só essa inscrição. Devolve o número de inscrições alteradas.
     */
    public static function levarParaListasDeEspera(int $utenteId, ?int $soEspera = null): int
    {
        $ids = self::idsDasReferenciacoes($utenteId);
        if ($ids === []) {
            return 0;
        }

        $esperas = DB::table('lista_de_esperas as l')->where('l.utente_id', $utenteId)->whereNull('l.deleted_at')
            ->whereIn(DB::raw('CAST(l.estado_lista_espera AS UNSIGNED)'), self::ESTADOS_ATIVOS)
            ->when($soEspera, fn ($q, $id) => $q->where('l.id', $id))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('diagnostico_lista_de_espera as dl')
                ->join('diagnosticos as d', 'd.id', '=', 'dl.diagnostico_id')->whereColumn('dl.lista_de_espera_id', 'l.id')->where('d.centro_de_referencia', 1))
            ->pluck('l.id');

        foreach ($esperas as $id) {
            DB::table('diagnostico_lista_de_espera')->insert(collect($ids)->map(fn ($d) => ['lista_de_espera_id' => $id, 'diagnostico_id' => $d])->all());
            ListaDeEspera::find($id)?->auditar('updated', ['diagnosticos' => []], ['diagnosticos' => $ids]);
        }

        return $esperas->count();
    }

    /**
     * Os três registos do utente (internamentos, referenciação e lista de espera), por esta regra: o que se grava num deles
     * passa para a referenciação e daí para os outros que ainda não têm diagnóstico CR; o registo que acabou de ser gravado
     * (`$origem`: 'internamento' ou 'lista') nunca é alterado por causa do que o utilizador acabou de escolher.
     * Idempotente: só acrescenta o que falta.
     */
    public static function sincronizar(int $utenteId, ?string $origem = null): void
    {
        self::levarParaReferenciacao($utenteId);
        if ($origem !== 'internamento') {
            self::levarParaInternamentos($utenteId);
        }
        if ($origem !== 'lista') {
            self::levarParaListasDeEspera($utenteId);
        }
    }

    /**
     * Todos os utentes com referenciação (para o histórico: migrações e comando).
     *
     * @return array{utentes: int, referenciacoes: int, internamentos: int, esperas: int}
     */
    public static function sincronizarTodos(): array
    {
        $resultado = ['utentes' => 0, 'referenciacoes' => 0, 'internamentos' => 0, 'esperas' => 0];
        DB::table('referenciacao_centro_de_referencias')->whereNull('deleted_at')->distinct()->orderBy('utente_id')->pluck('utente_id')
            ->each(function ($utente) use (&$resultado) {
                $resultado['utentes']++;
                $resultado['referenciacoes'] += count(self::levarParaReferenciacao((int) $utente));
                $resultado['internamentos'] += self::levarParaInternamentos((int) $utente);
                $resultado['esperas'] += self::levarParaListasDeEspera((int) $utente);
            });

        return $resultado;
    }
}
