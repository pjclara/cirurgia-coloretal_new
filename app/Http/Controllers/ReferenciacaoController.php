<?php

namespace App\Http\Controllers;

use App\Models\Referenciacao;
use App\Models\Utente;
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

        return redirect()->route('referenciacao.index')->with('sucesso', 'Utente referenciado ao centro de referência.');
    }

    public function atualizar(Request $request, Referenciacao $referenciacao): RedirectResponse
    {
        $dados = $this->validar($request);

        DB::transaction(function () use ($dados, $referenciacao) {
            $referenciacao->update(collect($dados)->except('diagnosticos')->all());
            $this->gravarDiagnosticos($referenciacao, $dados['diagnosticos'] ?? []);
        });

        return redirect()->route('referenciacao.index')->with('sucesso', 'Referenciação atualizada.');
    }

    public function apagar(Request $request, Referenciacao $referenciacao): RedirectResponse
    {
        $referenciacao->forceFill(['deleted_by_id' => $request->user()->id])->saveQuietly();
        $referenciacao->delete();

        return redirect()->route('referenciacao.index')->with('sucesso', 'Referenciação apagada.');
    }

    // ---- Auxiliares ----

    private function montarLista(Request $request, ?array $modal): Response
    {
        $situacoes = ['referenciado', 'no_centro', 'saida', 'todas'];
        $situacao = in_array($request->query('situacao'), $situacoes, true) ? $request->query('situacao') : 'todas';
        $q = trim((string) $request->query('q', ''));

        $lista = Referenciacao::query()
            ->with(['utente:id,nome,numero_processo', 'responsavel:id,name,abrev', 'diagnosticos:id,nome,abrev'])
            ->when($situacao === 'referenciado', fn ($query) => $query->whereNull('data_de_entrada')->whereNull('data_de_saida'))
            ->when($situacao === 'no_centro', fn ($query) => $query->whereNotNull('data_de_entrada')->whereNull('data_de_saida'))
            ->when($situacao === 'saida', fn ($query) => $query->whereNotNull('data_de_saida'))
            ->when($q !== '', fn ($query) => $query->whereHas('utente', fn ($u) => $u->pesquisar($q)))
            ->orderByDesc('data_de_referenciacao')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Referenciacao $r) => $this->resumo($r));

        return Inertia::render('referenciacao/index', [
            'lista' => $lista,
            'filtros' => ['situacao' => $situacao, 'q' => $q],
            'modal' => $modal,
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
                'diagnosticos' => Dominios::diagnosticos(),
                'responsaveis' => Dominios::responsaveis($referenciacao?->responsavel_id),
            ],
            'hoje' => now()->format('Y-m-d'),
            'fechar_url' => '/referenciacao',
        ];
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
            'diagnosticos.*.diagnostico_id' => ['required', 'integer', 'distinct', Rule::exists('diagnosticos', 'id')],
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
