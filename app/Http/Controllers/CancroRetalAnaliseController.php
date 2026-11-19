<?php

namespace App\Http\Controllers;

use App\Models\Complicacao;
use App\Models\Internamento;
use App\Models\Morbilidade;
use App\Support\Intervalo;
use App\Support\ReferenciadoAoCentro;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Indicadores de qualidade da cirurgia do cancro retal nos doentes do centro de referência, por trimestre, com as metas do centro de referência.
 *
 * Uma cirurgia retal é uma cirurgia com ressecção anterior do reto, amputação abdomino-perineal ou ressecção
 * reto-sigmoideia. A conservação de esfíncter exclui a ressecção reto-sigmoideia; os restantes indicadores incluem-na.
 * A readmissão aos 30 dias conta as altas de quem não faleceu e já completou 30 dias após a alta, seguidas de uma nova
 * entrada do mesmo utente nesses 30 dias, seja qual for a origem.
 */
class CancroRetalAnaliseController extends Controller
{
    public const RESSECCAO_ANTERIOR = 1;

    public const AMPUTACAO_ABDOMINO_PERINEAL = 24;

    public const RESSECCAO_RETO_SIGMOIDEIA = 88;

    /** Deiscência da anastomose na tabela `complicacaos`. */
    public const DEISCENCIA = 28;

    /** Metas: 'min' = o valor deve ficar acima; 'max' = deve ficar abaixo. */
    public const METAS = [
        'conservacao' => ['min' => 75],
        'deiscencia' => ['max' => 15],
        'mortalidade' => ['max' => 3],
        'morbilidade' => ['max' => 20],
        'readmissao' => ['max' => 10],
    ];

    public function __invoke(Request $request): Response
    {
        $hoje = now()->startOfDay();
        [$trimestres, $de, $ate, $cirurgias] = $this->intervalo($request, $hoje);

        return Inertia::render('analise/cancro-retal', [
            'trimestres' => $trimestres,
            'de' => $de,
            'ate' => $ate,
            'geradoEm' => now()->format('d/m/Y H:i'),
            'metas' => self::METAS,
            'total' => $cirurgias->count(),
            'totalConservacao' => $cirurgias->where('na_conservacao', true)->count(),
            'indicadores' => [
                'conservacao' => $this->indicador($cirurgias, 'conservacao', 'na_conservacao', 'conservou', 'min', $hoje, $trimestres, $de, $ate),
                'deiscencia' => $this->indicador($cirurgias, 'deiscencia', null, 'deiscencia', 'max', $hoje, $trimestres, $de, $ate),
                'mortalidade' => $this->indicador($cirurgias, 'mortalidade', null, 'obito', 'max', $hoje, $trimestres, $de, $ate),
                'morbilidade' => $this->indicador($cirurgias, 'morbilidade', null, 'grave', 'max', $hoje, $trimestres, $de, $ate),
                'readmissao' => $this->indicador($cirurgias, 'readmissao', 'na_readmissao', 'readmitido', 'max', $hoje, $trimestres, $de, $ate),
            ],
        ]);
    }

    /** Doentes referenciados operados com dados em falta para os indicadores. */
    public function qualidade(Request $request): Response
    {
        $hoje = now()->startOfDay();
        [$trimestres, $de, $ate, $cirurgias] = $this->intervalo($request, $hoje);

        return Inertia::render('analise/qualidade-dados', [
            'trimestres' => $trimestres,
            'de' => $de,
            'ate' => $ate,
            'geradoEm' => now()->format('d/m/Y H:i'),
            'qualidade' => $this->dadosEmFalta($cirurgias, $hoje, ctype_digit((string) $request->query('responsavel')) ? (int) $request->query('responsavel') : null),
        ]);
    }

    /**
     * Trimestres disponíveis (da primeira cirurgia ao trimestre em curso), intervalo escolhido e cirurgias nele.
     * Por omissão, o último trimestre completo (o em curso só se for o único).
     *
     * @return array{0: list<string>, 1: ?string, 2: ?string, 3: Collection}
     */
    private function intervalo(Request $request, Carbon $hoje): array
    {
        $todas = $this->cirurgias($hoje);
        ['trimestres' => $trimestres, 'de' => $de, 'ate' => $ate] = Intervalo::trimestres($request, $todas->first()['linha']->data ?? null, $hoje);

        return [$trimestres, $de, $ate, $todas->filter(fn ($c) => $c['trimestre'] >= $de && $c['trimestre'] <= $ate)->values()];
    }

    /** Uma linha por cirurgia retal de doente referenciado ao CR até à data da cirurgia, com os indicadores já avaliados. */
    private function cirurgias(Carbon $hoje)
    {
        $tem = fn (string $ids) => "exists (select 1 from bloco_operatorio_intervencao bi where bi.bloco_operatorio_id = b.id and bi.intervencao_id in ($ids))";
        $resseccao = self::RESSECCAO_ANTERIOR;
        $amputacao = self::AMPUTACAO_ABDOMINO_PERINEAL;
        $retoSigmoideia = self::RESSECCAO_RETO_SIGMOIDEIA;
        $falecido = Internamento::DESTINO_FALECIDO;
        $deiscencia = self::DEISCENCIA;
        $semComplicacoes = Complicacao::SEM_COMPLICACOES;
        // Morbilidade grave: grau definitivo de Clavien-Dindo 3 ou superior (3A a 5), como no resto da aplicação.
        $grau = '(m.momento = \''.Morbilidade::AOS_30_DIAS."' or (m.momento = '".Morbilidade::NA_ALTA."' and i.destino_id = $falecido))";

        return DB::table('bloco_operatorios as b')
            ->join('internamentos as i', fn ($j) => $j->on('i.id', '=', 'b.internamento_id')->whereNull('i.deleted_at'))
            ->whereNull('b.deleted_at')
            ->where('b.data_de_inicio', '>=', config('clinico.data_minima'))
            ->where('b.data_de_inicio', '<=', $hoje->copy()->endOfDay())
            ->whereRaw($tem("$resseccao, $amputacao, $retoSigmoideia"))
            ->whereRaw(ReferenciadoAoCentro::existe('i.utente_id', 'b.data_de_inicio'))
            ->join('utentes as u', 'u.id', '=', 'i.utente_id')
            ->leftJoin('users as ur', 'ur.id', '=', 'i.responsavel_id')
            ->selectRaw("b.data_de_inicio as data, b.id as bloco_id, i.id as internamento_id, u.id as utente_id, u.nome as utente_nome,
                u.numero_processo, i.responsavel_id, coalesce(nullif(ur.abrev, ''), ur.name) as responsavel_nome, i.data_de_entrada, i.data_de_alta, i.destino_id,
                ({$tem((string) $amputacao)}) as tem_amputacao, ({$tem((string) $retoSigmoideia)}) as tem_reto_sigmoideia,
                exists (select 1 from morbilidades m where m.internamento_id = i.id and m.momento = 'alta') as morb_alta,
                exists (select 1 from morbilidades m join complicacao_morbilidade c on c.morbilidade_id = m.id
                    where m.internamento_id = i.id and m.momento = 'alta' and c.complicacao_id <> $semComplicacoes) as complicacao_alta,
                exists (select 1 from morbilidades m where m.internamento_id = i.id and m.momento = '30_dias') as morb_30,
                exists (select 1 from morbilidades m where m.internamento_id = i.id and m.momento = '30_dias'
                    and (m.clavien_dindo_id is null or m.clavien_dindo_id = ".Morbilidade::CLAVIEN_A_AGUARDAR.")) as clavien_em_falta,
                (i.data_de_alta is not null and i.data_de_alta <= date_sub(curdate(), interval 30 day)
                    and (i.destino_id is null or i.destino_id <> $falecido)) as na_readmissao,
                exists (select 1 from internamentos r where r.utente_id = i.utente_id and r.id <> i.id and r.deleted_at is null
                    and r.data_de_entrada >= i.data_de_alta and r.data_de_entrada <= date_add(i.data_de_alta, interval 30 day)) as readmitido,
                ({$tem("$resseccao, $amputacao")}) as na_conservacao,
                ({$tem((string) $resseccao)} and not {$tem((string) $amputacao)}) as conservou,
                (i.destino_id = $falecido) as obito,
                exists (select 1 from morbilidades m join complicacao_morbilidade c on c.morbilidade_id = m.id
                    where m.internamento_id = i.id and m.momento in ('alta', '30_dias') and c.complicacao_id = $deiscencia) as deiscencia,
                exists (select 1 from morbilidades m where m.internamento_id = i.id and $grau
                    and m.clavien_dindo_id between 3 and 7) as grave")
            ->orderBy('b.data_de_inicio')
            ->get()
            ->map(fn ($c) => [
                'linha' => $c,
                'trimestre' => Carbon::parse($c->data)->format('Y').' T'.Carbon::parse($c->data)->quarter,
                'na_conservacao' => (bool) $c->na_conservacao,
                'conservou' => (bool) $c->conservou,
                'obito' => (bool) $c->obito,
                'deiscencia' => (bool) $c->deiscencia,
                'grave' => (bool) $c->grave,
                'na_readmissao' => (bool) $c->na_readmissao,
                'readmitido' => (bool) $c->readmitido,
            ]);
    }

    /** Colunas da tabela de qualidade dos dados, por ordem. */
    public const CAMPOS = ['alta', 'destino', 'morbilidade_alta', 'seguimento_30', 'clavien_30', 'datas'];

    /**
     * Cirurgias do período a que falta algum dado necessário aos indicadores. `falta` lista os campos em falta;
     * o seguimento aos 30 dias só falta nos doentes vivos cuja alta foi há mais de 30 dias. `responsaveis` resume
     * por responsável do internamento (sempre sobre todo o período) e `$responsavel` restringe a lista (0 = sem responsável).
     *
     * @return array<string, mixed>
     */
    private function dadosEmFalta($cirurgias, Carbon $hoje, ?int $responsavel = null): array
    {
        $limite = $hoje->copy()->subDays(30)->format('Y-m-d');
        $todas = $cirurgias->map(function ($c) use ($limite, $hoje) {
            $l = $c['linha'];
            $falecido = (int) $l->destino_id === Internamento::DESTINO_FALECIDO;
            $falta = [];
            if ($l->data_de_alta === null) {
                $falta[] = 'alta';
            }
            if ($l->destino_id === null) {
                $falta[] = 'destino';
            }
            if (! $l->morb_alta) {
                $falta[] = 'morbilidade_alta';
            }
            if (! $falecido && ! $l->morb_30 && $l->data_de_alta !== null && substr($l->data_de_alta, 0, 10) <= $limite) {
                $falta[] = 'seguimento_30';
            }
            if ($l->clavien_em_falta) {
                $falta[] = 'clavien_30';
            }
            $dia = substr($l->data, 0, 10);
            if ($dia < substr($l->data_de_entrada, 0, 10) || ($l->data_de_alta !== null && $dia > substr($l->data_de_alta, 0, 10))) {
                $falta[] = 'datas';
            }

            // Prioridade do seguimento: onde a falta pode esconder uma complicação ou um óbito vem primeiro.
            $diasDesdeAlta = $l->data_de_alta === null ? null : (int) Carbon::parse(substr($l->data_de_alta, 0, 10))->diffInDays($hoje, false);
            if (array_intersect($falta, ['alta', 'destino']) !== []) {
                [$prioridade, $motivo] = ['alta', 'Sem alta ou destino: a mortalidade não é calculada'];
            } elseif ($l->complicacao_alta && array_intersect($falta, ['seguimento_30', 'clavien_30']) !== []) {
                [$prioridade, $motivo] = ['alta', 'Complicação na alta sem seguimento aos 30 dias completo'];
            } elseif (in_array('seguimento_30', $falta, true) && $diasDesdeAlta !== null && $diasDesdeAlta <= 90) {
                [$prioridade, $motivo] = ['media', 'Seguimento em atraso, ainda dentro dos 90 dias da alta'];
            } elseif ($falta !== []) {
                [$prioridade, $motivo] = ['baixa', in_array('seguimento_30', $falta, true) ? 'Seguimento em atraso há mais de 90 dias' : 'Registo incompleto'];
            } else {
                [$prioridade, $motivo] = [null, null];
            }

            return [
                'prioridade' => $prioridade,
                'motivo' => $motivo,
                'bloco' => $l->bloco_id,
                'internamento' => $l->internamento_id,
                'utente' => ['id' => $l->utente_id, 'nome' => $l->utente_nome, 'numero_processo' => $l->numero_processo],
                'responsavel' => ['id' => (int) $l->responsavel_id, 'nome' => $l->responsavel_nome ?: 'Sem responsável'],
                'data' => substr($l->data, 0, 10),
                'intervencao' => $l->tem_amputacao ? 'Amputação abdomino-perineal' : ($l->tem_reto_sigmoideia ? 'Resseção reto-sigmoideia' : 'Ressecção anterior do reto'),
                'falta' => $falta,
            ];
        });

        $contar = fn ($linhas) => collect(self::CAMPOS)->mapWithKeys(fn ($campo) => [$campo => $linhas->filter(fn ($l) => in_array($campo, $l['falta'], true))->count()])->all();
        $comFalta = fn ($linhas) => $linhas->filter(fn ($l) => $l['falta'] !== []);

        $responsaveis = $todas->groupBy(fn ($l) => $l['responsavel']['id'])->map(fn ($linhas) => [
            'id' => $linhas->first()['responsavel']['id'],
            'nome' => $linhas->first()['responsavel']['nome'],
            'cirurgias' => $linhas->count(),
            'com_falta' => $comFalta($linhas)->count(),
            'totais' => $contar($linhas),
        ])->sortBy([['com_falta', 'desc'], ['nome', 'asc']])->values()->all();

        $visiveis = $responsavel === null ? $todas : $todas->filter(fn ($l) => $l['responsavel']['id'] === $responsavel);
        $ordem = ['alta' => 0, 'media' => 1, 'baixa' => 2];
        $linhas = $comFalta($visiveis)->sortBy([fn ($a, $b) => $ordem[$a['prioridade']] <=> $ordem[$b['prioridade']], fn ($a, $b) => strcmp($b['data'], $a['data'])])->values();

        return [
            'prioridades' => collect($ordem)->map(fn ($n, $p) => $linhas->where('prioridade', $p)->count())->all(),
            'cirurgias' => $visiveis->count(),
            'totais' => $contar($linhas),
            'linhas' => $linhas->all(),
            'responsaveis' => $responsaveis,
            'responsavel' => $responsavel,
        ];
    }

    /**
     * Série trimestral de um indicador. `$denominador` é a marca que escolhe as cirurgias contadas
     * (todas, se `null`); `$evento` é a marca do que se conta.
     */
    private function indicador($cirurgias, string $chave, ?string $denominador, string $evento, string $sentido, Carbon $hoje, array $todosTrimestres, ?string $de, ?string $ate): array
    {
        $meta = self::METAS[$chave][$sentido];
        $universo = $denominador ? $cirurgias->where($denominador, true) : $cirurgias;
        $porTrimestre = $universo->groupBy('trimestre');
        $trimestreAtual = $hoje->format('Y').' T'.$hoje->quarter;

        // Todos os trimestres do intervalo, incluindo os sem cirurgias.
        $trimestres = array_values(array_filter($todosTrimestres, fn ($t) => $t >= $de && $t <= $ate));

        $serie = collect($trimestres)->mapWithKeys(fn ($t) => [$t => $porTrimestre->get($t, collect())])->map(function ($linhas, $trimestre) use ($evento, $sentido, $meta, $trimestreAtual) {
            $n = $linhas->count();
            $eventos = $linhas->where($evento, true)->count();
            $taxa = $n > 0 ? round(100 * $eventos / $n, 1) : null;

            return [
                'trimestre' => $trimestre,
                'n' => $n,
                'eventos' => $eventos,
                'taxa' => $taxa,
                'cumpre' => $taxa === null ? null : ($sentido === 'min' ? $taxa >= $meta : $taxa <= $meta),
                'incompleto' => $trimestre === $trimestreAtual,
            ];
        })->values();

        $n = $universo->count();
        $eventos = $universo->where($evento, true)->count();
        $taxa = $n > 0 ? round(100 * $eventos / $n, 1) : null;
        $completos = $serie->where('incompleto', false)->whereNotNull('taxa');
        $melhor = $completos->sortBy(fn ($t) => $sentido === 'min' ? -$t['taxa'] : $t['taxa'])->first();
        $pior = $completos->sortBy(fn ($t) => $sentido === 'min' ? $t['taxa'] : -$t['taxa'])->first();

        return [
            'n' => $n,
            'eventos' => $eventos,
            'taxa' => $taxa,
            'cumpre' => $taxa === null ? null : ($sentido === 'min' ? $taxa >= $meta : $taxa <= $meta),
            'melhor' => $melhor,
            'pior' => $pior,
            'serie' => $serie->all(),
        ];
    }
}
