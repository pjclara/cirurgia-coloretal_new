<?php

namespace App\Http\Controllers;

use App\Models\Internamento;
use App\Support\Intervalo;
use App\Support\ReferenciadoAoCentro;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Análise dos doentes referenciados ao centro de referência (CR), a partir do histórico de referenciações,
 * com a comparação dos seus internamentos no serviço com os dos restantes doentes.
 */
class ReferenciacaoAnaliseController extends Controller
{
    /** Primeiro ano mostrado na série anual (antes disto há muito poucos registos). */
    private const ANO_INICIAL = 2017;

    public function __invoke(Request $request): Response
    {
        $hoje = now()->startOfDay();

        // Período por trimestres (por omissão, o último completo); o anterior tem o mesmo número de trimestres.
        $primeira = DB::table('referenciacao_centro_de_referencias')->whereNull('deleted_at')->min('data_de_referenciacao');
        ['trimestres' => $trimestres, 'de' => $de, 'ate' => $ateTrimestre, 'desde' => $desde, 'fim' => $ate, 'anteriorDesde' => $anteriorDesde] = Intervalo::trimestres($request, $primeira, $hoje);

        $referenciacoes = fn () => DB::table('referenciacao_centro_de_referencias as r')->whereNull('r.deleted_at');
        $noPeriodo = fn () => $referenciacoes()->when($desde, fn ($q) => $q->where('r.data_de_referenciacao', '>=', $desde))
            ->when($ate, fn ($q) => $q->where('r.data_de_referenciacao', '<=', $ate));

        $total = $noPeriodo()->count();
        $anterior = $desde
            ? $referenciacoes()->where('r.data_de_referenciacao', '>=', $anteriorDesde)->where('r.data_de_referenciacao', '<', $desde)->count()
            : null;

        $situacao = $noPeriodo()->selectRaw('
            sum(r.data_de_entrada is null and r.data_de_saida is null) as referenciados,
            sum(r.data_de_entrada is not null and r.data_de_saida is null) as no_centro,
            sum(r.data_de_saida is not null) as saidas,
            count(distinct r.utente_id) as doentes')->first();

        $diasAteEntrada = $noPeriodo()->whereNotNull('r.data_de_entrada')->whereColumn('r.data_de_entrada', '>=', 'r.data_de_referenciacao')
            ->selectRaw('datediff(r.data_de_entrada, r.data_de_referenciacao) as dias')->pluck('dias')->map(fn ($d) => (int) $d);
        $diasDiagnostico = $noPeriodo()->whereNotNull('r.data_de_diagnostico')->whereColumn('r.data_de_referenciacao', '>=', 'r.data_de_diagnostico')
            ->selectRaw('datediff(r.data_de_referenciacao, r.data_de_diagnostico) as dias')->pluck('dias')->map(fn ($d) => (int) $d);

        // No serviço: já foram internados / operados depois de referenciados?
        $noServico = $noPeriodo()->selectRaw('
            sum(exists (select 1 from internamentos i where i.utente_id = r.utente_id and i.deleted_at is null and i.data_de_entrada >= r.data_de_referenciacao)) as internados,
            sum(exists (select 1 from internamentos i join bloco_operatorios b on b.internamento_id = i.id
                where i.utente_id = r.utente_id and i.deleted_at is null and b.deleted_at is null and b.data_de_inicio >= r.data_de_referenciacao)) as operados')->first();

        return Inertia::render('referenciacao/analise', [
            'trimestres' => $trimestres,
            'de' => $de,
            'ate' => $ateTrimestre,
            'geradoEm' => now()->format('d/m/Y H:i'),
            'indicadores' => [
                'referenciacoes' => ['valor' => $total, 'anterior' => $anterior],
                'doentes' => (int) $situacao->doentes,
                'referenciados' => (int) $situacao->referenciados,
                'no_centro' => (int) $situacao->no_centro,
                'saidas' => (int) $situacao->saidas,
                'ate_entrada' => $this->mediana($diasAteEntrada),
                'diagnostico_ate_referenciacao' => $this->mediana($diasDiagnostico),
                'internados' => (int) $noServico->internados,
                'operados' => (int) $noServico->operados,
            ],
            'anual' => $this->porAno($referenciacoes),
            'mensal' => $this->porMes($referenciacoes, $hoje, $desde, $ate),
            'origens' => $this->contagem($noPeriodo()->join('origem_da_referenciacaos as o', 'o.id', '=', 'r.origem_id'), 'o.nome'),
            'responsaveis' => $this->contagem($noPeriodo()->join('users as u', 'u.id', '=', 'r.responsavel_id'), 'u.name', 10),
            'diagnosticos' => $this->contagem($this->diagnosticosDoPeriodo($noPeriodo)->join('diagnosticos as d', 'd.id', '=', 'dr.diagnostico_id'), 'd.nome', 10),
            'modos' => $this->contagem($this->diagnosticosDoPeriodo($noPeriodo)->join('diagnostico_referenciacao_modos as m', 'm.id', '=', 'dr.modo_id'), 'm.nome'),
            'histologias' => $this->contagem($this->diagnosticosDoPeriodo($noPeriodo)->join('histologias as h', 'h.id', '=', 'dr.histologia_id'), 'h.nome', 8),
            'tempos' => $this->faixasDeEspera($diasAteEntrada),
            'comparacao' => $this->comparacao($desde, $ate),
        ]);
    }

    /** Diagnósticos das referenciações do período (uma linha por diagnóstico). */
    private function diagnosticosDoPeriodo(callable $noPeriodo)
    {
        return $noPeriodo()->join('diagnostico_referenciacao as dr', 'dr.referenciacao_id', '=', 'r.id');
    }

    /** Contagem por uma coluna de texto, do maior para o menor. */
    private function contagem($consulta, string $coluna, ?int $limite = null): array
    {
        return $consulta->selectRaw("trim($coluna) as rotulo, count(*) as n")->groupBy('rotulo')->orderByDesc('n')->orderBy('rotulo')
            ->when($limite, fn ($q) => $q->limit($limite))->get()
            ->map(fn ($l) => ['rotulo' => $l->rotulo, 'valor' => (int) $l->n])->all();
    }

    /** Referenciações por ano (a partir de ANO_INICIAL), com os anos sem registos a zero. */
    private function porAno(callable $referenciacoes): array
    {
        $porAno = $referenciacoes()->selectRaw('year(r.data_de_referenciacao) as ano, count(*) as n')->groupBy('ano')->pluck('n', 'ano');
        $atual = (int) now()->format('Y');

        return collect(range(self::ANO_INICIAL, $atual))->map(fn ($ano) => ['rotulo' => (string) $ano, 'valor' => (int) ($porAno[$ano] ?? 0)])->all();
    }

    /** Referenciações por mês nos trimestres escolhidos (24 meses sem período), com os meses sem registos a zero. */
    private function porMes(callable $referenciacoes, Carbon $hoje, ?Carbon $desde, ?Carbon $ate): array
    {
        $inicio = $desde ? $desde->copy()->startOfMonth() : $hoje->copy()->startOfMonth()->subMonths(23);
        $fim = $ate ?? $hoje;
        $contagens = $referenciacoes()->where('r.data_de_referenciacao', '>=', $inicio)->where('r.data_de_referenciacao', '<=', $fim)
            ->selectRaw("date_format(r.data_de_referenciacao, '%Y-%m') as ym, count(*) as n")->groupBy('ym')->pluck('n', 'ym');

        $meses = [];
        for ($mes = $inicio->copy(); $mes->lte($fim); $mes->addMonthNoOverflow()) {
            $meses[] = ['rotulo' => $mes->format('Y-m'), 'valor' => (int) ($contagens[$mes->format('Y-m')] ?? 0)];
        }

        return $meses;
    }

    /** Dias entre a referenciação e a entrada no centro, por faixas. */
    private function faixasDeEspera(Collection $dias): array
    {
        $faixas = ['Até 7 dias' => fn ($d) => $d <= 7, '8 a 14 dias' => fn ($d) => $d >= 8 && $d <= 14, '15 a 30 dias' => fn ($d) => $d >= 15 && $d <= 30,
            '31 a 60 dias' => fn ($d) => $d >= 31 && $d <= 60, 'Mais de 60 dias' => fn ($d) => $d > 60];

        return collect($faixas)->map(fn ($regra, $rotulo) => ['rotulo' => $rotulo, 'valor' => $dias->filter($regra)->count()])->values()->all();
    }

    /**
     * Internamentos do serviço no período: doentes já referenciados ao CR contra os restantes.
     * É uma comparação simples, sem ajuste por idade, diagnóstico ou gravidade.
     */
    private function comparacao(?Carbon $desde, ?Carbon $ate = null): array
    {
        $linhas = DB::table('internamentos as i')->whereNull('i.deleted_at')->where('i.data_de_entrada', '>=', config('clinico.data_minima'))
            ->when($desde, fn ($q) => $q->where('i.data_de_entrada', '>=', $desde))
            ->when($ate, fn ($q) => $q->where('i.data_de_entrada', '<=', $ate))
            ->selectRaw('('.ReferenciadoAoCentro::existe('i.utente_id', 'i.data_de_entrada').') as cr, count(*) as n,
                sum(i.data_de_alta is not null and i.data_de_alta >= i.data_de_entrada) as altas,
                avg(case when i.data_de_alta is not null and i.data_de_alta >= i.data_de_entrada then datediff(i.data_de_alta, i.data_de_entrada) end) as demora,
                sum(i.data_de_alta is not null and i.data_de_alta >= i.data_de_entrada and i.destino_id = ?) as obitos,
                sum(i.data_de_alta is not null and i.data_de_alta >= i.data_de_entrada and '.AnaliseController::COM_COMPLICACAO.') as com_complicacao,
                sum(exists (select 1 from bloco_operatorios b where b.internamento_id = i.id and b.deleted_at is null)) as operados', [Internamento::DESTINO_FALECIDO])
            ->groupBy('cr')->get()->keyBy('cr');

        $grupo = function ($l) {
            if (! $l) {
                return ['internamentos' => 0, 'demora' => null, 'complicacoes' => null, 'mortalidade' => null, 'operados' => null];
            }
            $pc = fn ($parte, $todo) => $todo > 0 ? round(100 * $parte / $todo, 1) : null;

            return [
                'internamentos' => (int) $l->n,
                'demora' => $l->altas > 0 ? round((float) $l->demora, 1) : null,
                'complicacoes' => $pc($l->com_complicacao, $l->altas),
                'mortalidade' => $pc($l->obitos, $l->altas),
                'operados' => $pc($l->operados, $l->n),
            ];
        };

        return ['cr' => $grupo($linhas[1] ?? null), 'restantes' => $grupo($linhas[0] ?? null)];
    }

    private function mediana(Collection $valores): ?float
    {
        if ($valores->isEmpty()) {
            return null;
        }
        $ordenados = $valores->sort()->values();
        $meio = intdiv($ordenados->count(), 2);

        return $ordenados->count() % 2 ? (float) $ordenados[$meio] : ($ordenados[$meio - 1] + $ordenados[$meio]) / 2;
    }
}
