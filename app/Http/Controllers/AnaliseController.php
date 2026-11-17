<?php

namespace App\Http\Controllers;

use App\Models\Internamento;
use App\Models\ListaDeEspera;
use App\Support\Dominios;
use App\Support\Intervalo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Avaliação analítica da base de dados: indicadores de internamento, morbilidade,
 * cirurgia e lista de espera, com filtro de período.
 */
class AnaliseController extends Controller
{
    private const PERIODOS = ['12' => 12, '24' => 24, 'todos' => null];

    /** "Complicação real": qualquer complicação que não seja "Sem complicações". */
    public const COM_COMPLICACAO = 'exists (select 1 from morbilidades m join complicacao_morbilidade c on c.morbilidade_id = m.id
        where m.internamento_id = i.id and m.momento in (\'alta\', \'30_dias\') and c.complicacao_id <> '.\App\Models\Complicacao::SEM_COMPLICACOES.')';

    public function __invoke(Request $request): Response
    {
        $periodo = array_key_exists($request->query('periodo'), self::PERIODOS) ? $request->query('periodo') : '12';
        $meses = self::PERIODOS[$periodo];
        $hoje = now()->startOfDay();
        $desde = $meses ? $hoje->copy()->subMonths($meses) : null;
        $ate = null;
        $anteriorDesde = $desde ? $desde->copy()->subMonths($meses) : null;
        $minima = config('clinico.data_minima');

        // Intervalo de datas escolhido: sobrepõe-se ao período; o período anterior tem a mesma duração.
        [$de, $ateTexto] = Intervalo::datas($request);
        if ($de || $ateTexto) {
            $periodo = 'personalizado';
            $desde = $de ? Carbon::parse($de)->startOfDay() : Carbon::parse($minima)->startOfDay();
            $ate = $ateTexto ? Carbon::parse($ateTexto)->endOfDay() : $hoje->copy()->endOfDay();
            $anteriorDesde = $desde->copy()->subDays((int) $desde->diffInDays($ate->copy()->startOfDay()) + 1);
        }

        // Internamentos válidos (sem apagados e com datas plausíveis).
        $internamentos = fn () => DB::table('internamentos as i')->whereNull('i.deleted_at')->where('i.data_de_entrada', '>=', $minima);

        $entradas = $internamentos()->when($desde, fn ($q) => $q->where('i.data_de_entrada', '>=', $desde))
            ->when($ate, fn ($q) => $q->where('i.data_de_entrada', '<=', $ate))->count();
        $entradasAntes = $desde
            ? $internamentos()->where('i.data_de_entrada', '>=', $anteriorDesde)->where('i.data_de_entrada', '<', $desde)->count()
            : null;

        $altas = $internamentos()
            ->whereNotNull('i.data_de_alta')->whereColumn('i.data_de_alta', '>=', 'i.data_de_entrada')
            ->when($desde, fn ($q) => $q->where('i.data_de_alta', '>=', $desde))
            ->when($ate, fn ($q) => $q->where('i.data_de_alta', '<=', $ate))
            ->selectRaw('count(*) as total, sum(i.destino_id = ?) as obitos, avg(datediff(i.data_de_alta, i.data_de_entrada)) as demora, sum('.self::COM_COMPLICACAO.') as com_complicacao', [Internamento::DESTINO_FALECIDO])
            ->first();
        $totalAltas = (int) $altas->total;

        $cirurgias = DB::table('bloco_operatorios as b')->whereNull('b.deleted_at')->where('b.data_de_inicio', '>=', $minima)->where('b.data_de_inicio', '<=', $hoje->copy()->endOfDay())
            ->when($desde, fn ($q) => $q->where('b.data_de_inicio', '>=', $desde))
            ->when($ate, fn ($q) => $q->where('b.data_de_inicio', '<=', $ate));

        return Inertia::render('dashboard', [
            'periodo' => $periodo,
            'intervalo' => ['de' => $de, 'ate' => $ateTexto],
            'geradoEm' => now()->format('d/m/Y H:i'),
            'indicadores' => [
                'internamentos' => ['valor' => $entradas, 'anterior' => $entradasAntes],
                'altas' => $totalAltas,
                'demora' => $totalAltas > 0 ? round((float) $altas->demora, 1) : null,
                'complicacoes' => $totalAltas > 0 ? round(100 * $altas->com_complicacao / $totalAltas, 1) : null,
                'mortalidade' => $totalAltas > 0 ? round(100 * $altas->obitos / $totalAltas, 1) : null,
                'cirurgias' => (clone $cirurgias)->count(),
                'seguimentos_em_atraso' => $this->seguimentosEmAtraso($hoje),
            ],
            'mensal' => $this->internamentosPorMes($internamentos, $hoje),
            'anual' => $this->serieAnual($internamentos, $minima, $hoje),
            'clavien' => $this->distribuicaoClavien($internamentos, $desde, $ate),
            'intervencoes' => $this->topIntervencoes($desde, $minima, $hoje, $ate),
            'diagnosticos' => $this->topDiagnosticos($internamentos, $desde, $ate),
            'espera' => $this->listaDeEspera($hoje),
        ]);
    }

    /** Altas há mais de 30 dias (até 90) sem registo de morbilidade aos 30 dias, exceto falecidos. */
    private function seguimentosEmAtraso(Carbon $hoje): int
    {
        return Internamento::query()->comAlta()
            ->where(fn ($q) => $q->whereNull('destino_id')->orWhere('destino_id', '<>', Internamento::DESTINO_FALECIDO))
            ->whereDoesntHave('morbilidades', fn ($q) => $q->where('momento', '30_dias'))
            ->whereDate('data_de_alta', '>=', $hoje->copy()->subDays(90))
            ->whereDate('data_de_alta', '<', $hoje->copy()->subDays(30))
            ->count();
    }

    /** Entradas nos últimos 24 meses, com os meses sem entradas a zero. */
    private function internamentosPorMes(callable $internamentos, Carbon $hoje): array
    {
        $inicio = $hoje->copy()->startOfMonth()->subMonths(23);
        $contagens = $internamentos()->where('i.data_de_entrada', '>=', $inicio)
            ->selectRaw("date_format(i.data_de_entrada, '%Y-%m') as ym, count(*) as n")->groupBy('ym')->pluck('n', 'ym');

        return collect(range(0, 23))->map(function ($n) use ($inicio, $contagens) {
            $mes = $inicio->copy()->addMonths($n);

            return ['rotulo' => $mes->format('Y-m'), 'valor' => (int) ($contagens[$mes->format('Y-m')] ?? 0)];
        })->all();
    }

    /** Por ano: internamentos, cirurgias, demora média e % de altas com complicações. */
    private function serieAnual(callable $internamentos, string $minima, Carbon $hoje): array
    {
        $anoMinimo = (int) substr($minima, 0, 4);
        $entradas = $internamentos()->selectRaw('year(i.data_de_entrada) as ano, count(*) as n')->groupBy('ano')->pluck('n', 'ano');
        $altas = $internamentos()->whereNotNull('i.data_de_alta')->whereColumn('i.data_de_alta', '>=', 'i.data_de_entrada')
            ->selectRaw('year(i.data_de_alta) as ano, count(*) as n, avg(datediff(i.data_de_alta, i.data_de_entrada)) as demora, sum('.self::COM_COMPLICACAO.') as com')
            ->groupBy('ano')->get()->keyBy('ano');
        $cirurgias = DB::table('bloco_operatorios')->whereNull('deleted_at')->where('data_de_inicio', '>=', $minima)
            ->selectRaw('year(data_de_inicio) as ano, count(*) as n')->groupBy('ano')->pluck('n', 'ano');

        return collect(range($anoMinimo, (int) $hoje->format('Y')))
            ->filter(fn ($ano) => ($entradas[$ano] ?? 0) > 0 || ($cirurgias[$ano] ?? 0) > 0)
            ->map(fn ($ano) => [
                'ano' => $ano,
                'internamentos' => (int) ($entradas[$ano] ?? 0),
                'cirurgias' => (int) ($cirurgias[$ano] ?? 0),
                'demora' => isset($altas[$ano]) ? round((float) $altas[$ano]->demora, 1) : null,
                'complicacoes' => isset($altas[$ano]) && $altas[$ano]->n > 0 ? round(100 * $altas[$ano]->com / $altas[$ano]->n, 1) : null,
            ])->values()->all();
    }

    /** Internamentos por grau mais grave de Clavien-Dindo (graus 1 a 5; 3 e 4 juntam A e B). */
    private function distribuicaoClavien(callable $internamentos, ?Carbon $desde, ?Carbon $ate = null): array
    {
        $graus = $internamentos()->join('morbilidades as m', 'm.internamento_id', '=', 'i.id')
            // Grau definitivo: o dos 30 dias (ou o da alta, em quem faleceu).
            ->where(fn ($q) => $q->where('m.momento', '30_dias')
                ->orWhere(fn ($q) => $q->where('m.momento', 'alta')->where('i.destino_id', \App\Models\Internamento::DESTINO_FALECIDO)))
            ->whereNotNull('m.clavien_dindo_id')->where('m.clavien_dindo_id', '<>', \App\Models\Morbilidade::CLAVIEN_A_AGUARDAR)
            ->when($desde, fn ($q) => $q->where('i.data_de_entrada', '>=', $desde))
            ->when($ate, fn ($q) => $q->where('i.data_de_entrada', '<=', $ate))
            ->groupBy('i.id')->selectRaw('max(m.clavien_dindo_id) as grau')->pluck('grau');

        // ids 1 e 2 = graus 1 e 2; 3 e 4 = 3A e 3B; 5 e 6 = 4A e 4B; 7 = grau 5
        $grupo = fn ($id) => match (true) { $id <= 2 => (string) $id, $id <= 4 => '3', $id <= 6 => '4', default => '5' };
        $contagem = $graus->map(fn ($g) => $grupo((int) $g))->countBy();

        return collect(['1', '2', '3', '4', '5'])->map(fn ($g) => ['rotulo' => "Grau $g", 'valor' => (int) ($contagem[$g] ?? 0)])->all();
    }

    private function topIntervencoes(?Carbon $desde, string $minima, Carbon $hoje, ?Carbon $ate = null): array
    {
        return DB::table('bloco_operatorio_intervencao as bi')
            ->join('bloco_operatorios as b', 'b.id', '=', 'bi.bloco_operatorio_id')
            ->join('intervencaos as t', 't.id', '=', 'bi.intervencao_id')
            ->whereNull('b.deleted_at')->where('b.data_de_inicio', '>=', $minima)->where('b.data_de_inicio', '<=', $hoje->copy()->endOfDay())
            ->when($desde, fn ($q) => $q->where('b.data_de_inicio', '>=', $desde))
            ->when($ate, fn ($q) => $q->where('b.data_de_inicio', '<=', $ate))
            ->groupBy('t.id', 't.nome')->orderByRaw('count(*) desc')->orderBy('t.nome')->limit(10)
            ->get(['t.nome', DB::raw('count(*) as n')])
            ->map(fn ($r) => ['rotulo' => trim($r->nome), 'valor' => (int) $r->n])->all();
    }

    private function topDiagnosticos(callable $internamentos, ?Carbon $desde, ?Carbon $ate = null): array
    {
        return $internamentos()
            ->join('diagnostico_internamento as di', 'di.internamento_id', '=', 'i.id')
            ->join('diagnosticos as d', 'd.id', '=', 'di.diagnostico_id')
            ->when($desde, fn ($q) => $q->where('i.data_de_entrada', '>=', $desde))
            ->when($ate, fn ($q) => $q->where('i.data_de_entrada', '<=', $ate))
            ->groupBy('d.id', 'd.nome')->orderByRaw('count(*) desc')->orderBy('d.nome')->limit(10)
            ->get(['d.nome', DB::raw('count(*) as n')])
            ->map(fn ($r) => ['rotulo' => trim($r->nome), 'valor' => (int) $r->n])->all();
    }

    /** Inscrições ativas (propostas ou pendentes): total, espera mediana, por prioridade e por tempo de espera. */
    private function listaDeEspera(Carbon $hoje): array
    {
        $ativas = DB::table('lista_de_esperas')->whereNull('deleted_at')
            ->whereIn(DB::raw('CAST(estado_lista_espera AS UNSIGNED)'), [ListaDeEspera::ESTADO_PROPOSTO, ListaDeEspera::ESTADO_PENDENTE])
            ->whereNotNull('data_de_lista');

        $dias = (clone $ativas)->selectRaw('datediff(?, data_de_lista) as d, prioridade_id', [$hoje->format('Y-m-d')])->get();
        $faixas = ['Menos de 30 dias' => fn ($d) => $d < 30, '30 a 89 dias' => fn ($d) => $d >= 30 && $d < 90, '90 a 179 dias' => fn ($d) => $d >= 90 && $d < 180, '180 a 364 dias' => fn ($d) => $d >= 180 && $d < 365, '1 ano ou mais' => fn ($d) => $d >= 365];
        $ordenados = $dias->pluck('d')->map(fn ($d) => (int) $d)->sort()->values();

        return [
            'total' => $dias->count(),
            'mediana_dias' => $ordenados->isEmpty() ? null : (int) $ordenados[intdiv($ordenados->count(), 2)],
            'por_prioridade' => collect(ListaDeEspera::PRIORIDADES)
                ->map(fn ($nome, $id) => ['rotulo' => $nome, 'valor' => $dias->where('prioridade_id', $id)->count()])
                ->filter(fn ($p) => $p['valor'] > 0)->values()->all(),
            'por_espera' => collect($faixas)->map(fn ($fn, $rotulo) => ['rotulo' => $rotulo, 'valor' => $dias->filter(fn ($r) => $fn((int) $r->d))->count()])->values()->all(),
        ];
    }
}
