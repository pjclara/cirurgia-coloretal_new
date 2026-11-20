<?php

namespace App\Http\Controllers;

use App\Models\Diagnostico;
use App\Models\Internamento;
use App\Models\Referenciacao;
use App\Models\Utente;
use App\Support\Cirurgias;
use App\Support\DiagnosticosCr;
use App\Support\Dominios;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Referenciação de utentes ao centro de referência: lista e registo (formulário em modal). */
class ReferenciacaoController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->montarLista($request, null);
    }

    /** A lista de referenciação com o formulário de novo utente numa modal por cima; ao criar, segue para a referenciação rápida. */
    public function novoUtente(Request $request, UtenteController $formulario): Response
    {
        return $this->montarLista($request, null, null, $formulario->propsCriacao($request) + [
            'fechar_url' => '/referenciacao',
            'origem' => 'referenciacao',
        ]);
    }

    public function criar(Request $request, Utente $utente): Response
    {
        return $this->montarLista($request, $this->formulario($utente, null));
    }

    public function editar(Request $request, Referenciacao $referenciacao): Response
    {
        return $this->montarLista($request, $this->formulario($referenciacao->utente, $referenciacao));
    }

    public function guardar(Request $request, Utente $utente): RedirectResponse
    {
        $dados = $this->validar($request);

        DB::transaction(function () use ($dados, $utente) {
            $referenciacao = new Referenciacao(collect($dados)->except('diagnosticos')->all());
            $referenciacao->utente_id = $utente->id;
            $referenciacao->save();
            $this->gravarDiagnosticos($referenciacao, $dados['diagnosticos'] ?? []);
        });
        DiagnosticosCr::sincronizar($utente->id);

        return redirect()->route('referenciacao.index')->with('sucesso', 'Utente referenciado ao centro de referência.');
    }

    /** Sinalização rápida: o utente fica marcado como referenciado ao centro; o resto preenche-se depois. */
    public function sinalizar(Request $request, Utente $utente): RedirectResponse
    {
        $minima = config('clinico.data_minima');
        $dados = $request->validate([
            'data_de_referenciacao' => ['required', 'date_format:Y-m-d', "after_or_equal:$minima", 'before_or_equal:today'],
            'origem_id' => ['required', 'integer', Rule::exists('origem_da_referenciacaos', 'id')],
            'responsavel_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('activo', true)],
            'comentarios' => ['nullable', 'string', 'max:2000'],
        ], ['required' => 'Preencha este campo.', 'before_or_equal' => 'A data não pode ser futura.']);

        if ($utente->referenciacoesAtivas()->exists()) {
            return back()->with('erro', 'Este utente já está sinalizado para o centro de referência.');
        }

        $referenciacao = new Referenciacao(collect($dados)->except('responsavel_id')->all());
        $referenciacao->utente_id = $utente->id;
        // O médico escolhido; sem escolha, quem está a registar.
        $referenciacao->responsavel_id = $dados['responsavel_id'] ?? $request->user()->id;
        $referenciacao->save();
        // Os diagnósticos CR que o utente já tem nos internamentos ficam logo associados (e vice-versa).
        DiagnosticosCr::sincronizar($utente->id);

        return back()->with('sucesso', 'Utente sinalizado para o centro de referência.');
    }

    /** Regista uma decisão terapêutica (reunião DT) nesta referenciação. */
    public function guardarDecisao(Request $request, Referenciacao $referenciacao): RedirectResponse
    {
        $agora = now();
        DB::table('reuniao_decisao_terapeuticas')->insert($this->validarDecisao($request) + [
            'referenciacao_id' => $referenciacao->id,
            'created_by_id' => $request->user()->id,
            'updated_by_id' => $request->user()->id,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);

        return back()->with('sucesso', 'Decisão terapêutica registada.');
    }

    public function atualizarDecisao(Request $request, int $decisao): RedirectResponse
    {
        $existe = DB::table('reuniao_decisao_terapeuticas')->where('id', $decisao)->whereNull('deleted_at')->exists();
        abort_unless($existe, 404);

        DB::table('reuniao_decisao_terapeuticas')->where('id', $decisao)->update($this->validarDecisao($request) + [
            'updated_by_id' => $request->user()->id,
            'updated_at' => now(),
        ]);

        return back()->with('sucesso', 'Decisão terapêutica atualizada.');
    }

    private function validarDecisao(Request $request): array
    {
        $minima = config('clinico.data_minima');
        $dados = $request->validate([
            'data_de_decisao_terapeutica' => ['required', 'date_format:Y-m-d', "after_or_equal:$minima", 'before_or_equal:'.now()->addYear()->format('Y-m-d')],
            'estado_consulta_id' => ['required', 'integer', Rule::exists('estado_consultas', 'id')],
            'timming_id' => ['nullable', 'integer', Rule::exists('timmings', 'id')],
            'resumo' => ['nullable', 'string', 'max:5000'],
            'recados' => ['nullable', 'string', 'max:5000'],
            'comentarios' => ['nullable', 'string', 'max:5000'],
        ], ['required' => 'Preencha este campo.', 'date_format' => 'Data inválida.']);

        return collect($dados)->map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v)->all();
    }

    public function atualizar(Request $request, Referenciacao $referenciacao): RedirectResponse
    {
        $dados = $this->validar($request);

        DB::transaction(function () use ($dados, $referenciacao) {
            $referenciacao->update(collect($dados)->except('diagnosticos')->all());
            $this->gravarDiagnosticos($referenciacao, $dados['diagnosticos'] ?? []);
        });
        DiagnosticosCr::sincronizar($referenciacao->utente_id);

        return redirect()->route('referenciacao.index')->with('sucesso', 'Referenciação atualizada.');
    }

    public function apagar(Request $request, Referenciacao $referenciacao): RedirectResponse
    {
        $referenciacao->forceFill(['deleted_by_id' => $request->user()->id])->saveQuietly();
        $referenciacao->delete();

        return redirect()->route('referenciacao.index')->with('sucesso', 'Referenciação apagada.');
    }

    // ---- Auxiliares ----

    /** O internamento do utente numa modal por cima da lista; ao fechar ou gravar volta à referenciação que a abriu. */
    public function internamento(Request $request, Internamento $internamento): Response
    {
        abort_unless($request->user()->can('show internamento'), 403);
        $retorno = (string) $request->query('retorno');

        return $this->montarLista($request, null, app(InternamentoController::class)->propsEdicao($request, $internamento) + [
            'fechar_url' => str_starts_with($retorno, '/referenciacao') ? $retorno : '/referenciacao',
            'origem' => 'referenciacao',
        ]);
    }

    private function montarLista(Request $request, ?array $modal, ?array $modalInternamento = null, ?array $modalUtente = null): Response
    {
        $situacoes = ['referenciado', 'no_centro', 'saida', 'todas'];
        $situacao = in_array($request->query('situacao'), $situacoes, true) ? $request->query('situacao') : 'todas';
        $q = trim((string) $request->query('q', ''));
        // Ano e trimestre da data de referenciação (valores inválidos ignoram-se; o trimestre só conta com ano).
        $anos = DB::table('referenciacao_centro_de_referencias')->whereNull('deleted_at')->selectRaw('distinct year(data_de_referenciacao) as ano')
            ->orderByDesc('ano')->pluck('ano')->map(fn ($a) => (string) $a)->all();
        $ano = in_array($request->query('ano'), $anos, true) ? $request->query('ano') : '';
        $trimestre = $ano !== '' && in_array($request->query('trimestre'), ['1', '2', '3', '4'], true) ? $request->query('trimestre') : '';
        $de = $ate = '';
        if ($ano !== '') {
            $inicio = \Illuminate\Support\Carbon::create((int) $ano, $trimestre !== '' ? ((int) $trimestre - 1) * 3 + 1 : 1, 1);
            [$de, $ate] = [$inicio->format('Y-m-d'), $inicio->copy()->addMonthsNoOverflow($trimestre !== '' ? 3 : 12)->subDay()->format('Y-m-d')];
        }

        $lista = Referenciacao::query()
            ->with(['utente:id,nome,numero_processo', 'responsavel:id,name,abrev', 'diagnosticos:id,nome,abrev'])
            ->when($situacao === 'referenciado', fn ($query) => $query->whereNull('data_de_entrada')->whereNull('data_de_saida'))
            ->when($situacao === 'no_centro', fn ($query) => $query->whereNotNull('data_de_entrada')->whereNull('data_de_saida'))
            ->when($situacao === 'saida', fn ($query) => $query->whereNotNull('data_de_saida'))
            ->when($de !== '', fn ($query) => $query->whereDate('data_de_referenciacao', '>=', $de))
            ->when($ate !== '', fn ($query) => $query->whereDate('data_de_referenciacao', '<=', $ate))
            ->when($q !== '', fn ($query) => $query->whereHas('utente', fn ($u) => $u->pesquisar($q)))
            ->orderByDesc('data_de_referenciacao')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Referenciacao $r) => $this->resumo($r));

        $podeReferenciar = $request->user()->can('create referenciacao');

        // Utente acabado de criar (ou escolhido) para sinalizar de imediato, se ainda não estiver sinalizado.
        $referenciar = null;
        if ($podeReferenciar && ctype_digit((string) $request->query('referenciar'))) {
            $utente = Utente::find((int) $request->query('referenciar'));
            if ($utente && ! $utente->referenciacoesAtivas()->exists()) {
                $referenciar = ['id' => $utente->id, 'nome' => $utente->nome, 'numero_processo' => $utente->numero_processo];
            }
        }

        return Inertia::render('referenciacao/index', [
            'lista' => $lista,
            'filtros' => ['situacao' => $situacao, 'q' => $q, 'ano' => $ano, 'trimestre' => $trimestre],
            'anos' => $anos,
            'modal' => $modal,
            'modalInternamento' => $modalInternamento,
            'modalUtente' => $modalUtente,
            'origens' => $podeReferenciar ? Dominios::lista('origem_da_referenciacaos') : [],
            'responsaveis' => $podeReferenciar ? Dominios::responsaveis() : [],
            'referenciar' => $referenciar,
        ]);
    }

    /** Linha de listagem; também usada na ficha do utente. */
    public static function resumo(Referenciacao $r): array
    {
        return [
            'id' => $r->id,
            'utente' => ['id' => $r->utente?->id, 'nome' => $r->utente?->nome, 'numero_processo' => $r->utente?->numero_processo],
            'data_de_referenciacao' => $r->data_de_referenciacao?->format('Y-m-d'),
            'origem' => Dominios::nome('origem_da_referenciacaos', $r->origem_id),
            'data_de_entrada' => $r->data_de_entrada?->format('Y-m-d'),
            'data_de_saida' => $r->data_de_saida?->format('Y-m-d'),
            'destino' => Dominios::nome('destinos', $r->destino_id),
            'responsavel' => $r->responsavel?->abrev ?: $r->responsavel?->name,
            'situacao' => $r->situacao(),
            'diagnosticos' => $r->diagnosticos->map(fn ($d) => trim($d->abrev ?: $d->nome))->values(),
        ];
    }

    /**
     * Os internamentos não apagados do utente, do mais recente para o mais antigo, com os diagnósticos e as cirurgias.
     *
     * @return list<array<string, mixed>>
     */
    private function internamentosDoUtente(Utente $utente): array
    {
        $internamentos = $utente->internamentos()->with('diagnosticos:id,nome,centro_de_referencia')->get();
        $cirurgias = Cirurgias::porInternamento($internamentos->pluck('id'));

        return $internamentos->map(fn (Internamento $i) => [
            'id' => $i->id,
            'data_de_entrada' => $i->data_de_entrada?->format('Y-m-d'),
            'data_de_alta' => $i->data_de_alta?->format('Y-m-d'),
            'motivo' => $i->motivo_internamento,
            'destino' => Dominios::nome('destinos', $i->destino_id),
            'diagnosticos' => $i->diagnosticos->map(fn ($d) => ['nome' => trim($d->nome), 'cr' => (bool) $d->centro_de_referencia])->values(),
            'cirurgias' => $cirurgias->get($i->id, collect())->values(),
        ])->values()->all();
    }

    private function formulario(Utente $utente, ?Referenciacao $referenciacao): array
    {
        $referenciacao?->load('diagnosticos:id');

        return [
            'referenciacao' => $referenciacao ? [
                'id' => $referenciacao->id,
                'data_de_diagnostico' => $referenciacao->data_de_diagnostico?->format('Y-m-d'),
                'data_de_referenciacao' => $referenciacao->data_de_referenciacao?->format('Y-m-d'),
                'origem_id' => $referenciacao->origem_id,
                'data_de_entrada' => $referenciacao->data_de_entrada?->format('Y-m-d'),
                'data_de_saida' => $referenciacao->data_de_saida?->format('Y-m-d'),
                'destino_id' => $referenciacao->destino_id,
                'responsavel_id' => $referenciacao->responsavel_id,
                'comentarios' => $referenciacao->comentarios ?? '',
                'diagnosticos' => $referenciacao->diagnosticos->map(fn ($d) => [
                    'diagnostico_id' => $d->id,
                    'modo_id' => $d->pivot->modo_id,
                    'histologia_id' => $d->pivot->histologia_id,
                    'data_diagnostico' => $d->pivot->data_diagnostico ? substr((string) $d->pivot->data_diagnostico, 0, 10) : '',
                ])->values(),
            ] : null,
            'utente' => ['id' => $utente->id, 'nome' => $utente->nome, 'numero_processo' => $utente->numero_processo],
            'dominios' => [
                'origens' => Dominios::lista('origem_da_referenciacaos'),
                'destinos' => Dominios::lista('destinos'),
                'modos' => Dominios::lista('diagnostico_referenciacao_modos'),
                'histologias' => Dominios::lista('histologias'),
                // Só os diagnósticos do centro de referência, mais «Outro Colorectal» (e os que esta referenciação já tem).
                'diagnosticos' => collect(Dominios::diagnosticos())->whereIn('id', $this->diagnosticosPermitidos($referenciacao))->values()->all(),
                'responsaveis' => Dominios::responsaveis($referenciacao?->responsavel_id),
                'estados_consulta' => Dominios::lista('estado_consultas'),
                'timmings' => Dominios::lista('timmings'),
            ],
            // Os internamentos do utente, para ter o contexto clínico à vista (só com permissão para os ver).
            'internamentos' => request()->user()->can('show internamento') ? $this->internamentosDoUtente($utente) : null,
            // Diagnósticos CR já registados nos internamentos do utente e que a referenciação ainda não tem.
            'diagnosticos_do_utente' => DiagnosticosCr::doUtente($utente->id)
                ->reject(fn ($d) => $referenciacao?->diagnosticos->contains('id', $d->id))->values(),
            // Decisões terapêuticas (reunião DT) do utente, de todas as suas referenciações; só ao editar uma referenciação.
            'decisoes' => $referenciacao ? $this->decisoesDoUtente($utente) : null,
            'hoje' => now()->format('Y-m-d'),
            'fechar_url' => '/referenciacao',
        ];
    }

    /** Decisões terapêuticas do utente, da mais recente para a mais antiga. */
    private function decisoesDoUtente(Utente $utente): array
    {
        return DB::table('reuniao_decisao_terapeuticas as d')
            ->join('referenciacao_centro_de_referencias as r', 'r.id', '=', 'd.referenciacao_id')
            ->leftJoin('estado_consultas as e', 'e.id', '=', 'd.estado_consulta_id')
            ->leftJoin('timmings as t', 't.id', '=', 'd.timming_id')
            ->where('r.utente_id', $utente->id)->whereNull('d.deleted_at')
            ->orderByDesc('d.data_de_decisao_terapeutica')->orderByDesc('d.id')
            ->get(['d.id', 'd.data_de_decisao_terapeutica as data', 'd.estado_consulta_id as estado_id', 'e.nome as estado', 'd.timming_id', 't.nome as timming', 'd.resumo', 'd.recados', 'd.comentarios'])
            ->map(fn ($d) => [
                'id' => $d->id,
                'data' => substr((string) $d->data, 0, 10),
                'estado' => $d->estado,
                'estado_id' => $d->estado_id,
                'timming' => $d->timming,
                'timming_id' => $d->timming_id,
                'resumo' => $d->resumo,
                'recados' => $d->recados,
                'comentarios' => $d->comentarios,
            ])->all();
    }

    /**
     * Os diagnósticos que se podem escolher numa referenciação: os do centro de referência, «Outro Colorectal» e os que a própria
     * referenciação já tenha (para não perder o que existia antes desta regra).
     *
     * @return list<int>
     */
    private function diagnosticosPermitidos(?Referenciacao $referenciacao): array
    {
        return collect(DB::table('diagnosticos')->where('centro_de_referencia', 1)->pluck('id'))
            ->push(Diagnostico::OUTRO)
            ->merge($referenciacao ? $referenciacao->diagnosticos()->pluck('diagnosticos.id') : [])
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function validar(Request $request): array
    {
        $minima = config('clinico.data_minima');

        $dados = $request->validate([
            'data_de_referenciacao' => ['required', 'date_format:Y-m-d', "after_or_equal:$minima", 'before_or_equal:today'],
            'data_de_diagnostico' => ['nullable', 'date_format:Y-m-d', "after_or_equal:$minima", 'before_or_equal:data_de_referenciacao'],
            'origem_id' => ['required', 'integer', Rule::exists('origem_da_referenciacaos', 'id')],
            'data_de_entrada' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_de_referenciacao', 'before_or_equal:today'],
            'data_de_saida' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_de_entrada', 'before_or_equal:today', 'required_with:destino_id'],
            'destino_id' => ['nullable', 'integer', 'required_with:data_de_saida', Rule::exists('destinos', 'id')],
            'responsavel_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'comentarios' => ['nullable', 'string', 'max:20000'],
            'diagnosticos' => ['nullable', 'array'],
            'diagnosticos.*.diagnostico_id' => ['required', 'integer', 'distinct', Rule::in($this->diagnosticosPermitidos($request->route('referenciacao')))],
            'diagnosticos.*.modo_id' => ['required', 'integer', Rule::exists('diagnostico_referenciacao_modos', 'id')],
            'diagnosticos.*.histologia_id' => ['nullable', 'integer', Rule::exists('histologias', 'id')],
            'diagnosticos.*.data_diagnostico' => ['nullable', 'date_format:Y-m-d', "after_or_equal:$minima", 'before_or_equal:today'],
        ], [
            'required' => 'Preencha este campo.',
            'required_with' => 'Preencha este campo.',
            'data_de_saida.after_or_equal' => 'A saída não pode ser anterior à entrada.',
            'data_de_entrada.after_or_equal' => 'A entrada não pode ser anterior à data de referenciação.',
            'data_de_diagnostico.before_or_equal' => 'O diagnóstico não pode ser posterior à referenciação.',
            'before_or_equal' => 'A data não pode ser futura.',
            'distinct' => 'Diagnóstico repetido.',
        ]);

        // A saída só faz sentido depois de o utente entrar no centro.
        if (! empty($dados['data_de_saida']) && empty($dados['data_de_entrada'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['data_de_entrada' => 'Preencha a data de entrada antes da saída.']);
        }

        return $dados;
    }

    private function gravarDiagnosticos(Referenciacao $referenciacao, array $diagnosticos): void
    {
        $referenciacao->diagnosticos()->sync(collect($diagnosticos)->mapWithKeys(fn ($d) => [
            (int) $d['diagnostico_id'] => [
                'modo_id' => (int) $d['modo_id'],
                'histologia_id' => ! empty($d['histologia_id']) ? (int) $d['histologia_id'] : null,
                'data_diagnostico' => ! empty($d['data_diagnostico']) ? $d['data_diagnostico'] : null,
            ],
        ])->all());
    }
}
