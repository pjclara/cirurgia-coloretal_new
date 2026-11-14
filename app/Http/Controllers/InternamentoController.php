<?php

namespace App\Http\Controllers;

use App\Http\Requests\InternamentoRequest;
use App\Models\Internamento;
use App\Models\Morbilidade;
use App\Models\Utente;
use App\Services\GuardarInternamento;
use App\Support\Dominios;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InternamentoController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->montarIndex($request, null);
    }

    /** A lista de internamentos, opcionalmente com o formulário de registo numa modal por cima. */
    private function montarIndex(Request $request, ?array $modal): Response
    {
        $filtros = [
            'estado' => in_array($request->query('estado'), ['internados', 'alta', 'todos'], true) ? $request->query('estado') : 'todos',
            'q' => trim((string) $request->query('q', '')),
            'ano' => ctype_digit((string) $request->query('ano')) ? (int) $request->query('ano') : null,
            'localizacao_id' => ctype_digit((string) $request->query('localizacao_id')) ? (int) $request->query('localizacao_id') : null,
            'responsavel_id' => ctype_digit((string) $request->query('responsavel_id')) ? (int) $request->query('responsavel_id') : null,
        ];

        $internamentos = Internamento::query()
            ->with(['utente:id,nome,numero_processo', 'responsavel:id,name,abrev', 'morbilidades:id,internamento_id,clavien_dindo_id'])
            ->when($filtros['estado'] === 'internados', fn ($q) => $q->internados())
            ->when($filtros['estado'] === 'alta', fn ($q) => $q->comAlta())
            ->when($filtros['ano'], fn ($q, $ano) => $q->whereYear('data_de_entrada', $ano))
            ->when($filtros['localizacao_id'], fn ($q, $id) => $q->where('localizacao_id', $id))
            ->when($filtros['responsavel_id'], fn ($q, $id) => $q->where('responsavel_id', $id))
            ->when($filtros['q'] !== '', function ($q) use ($filtros) {
                $termo = $filtros['q'];
                $q->where(function ($sub) use ($termo) {
                    $sub->where('motivo_internamento', 'like', '%'.addcslashes($termo, '%_\\').'%')
                        ->orWhereHas('utente', fn ($u) => $u->pesquisar($termo));
                });
            })
            ->orderByDesc('data_de_entrada')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Internamento $i) => [
                'id' => $i->id,
                'utente' => ['id' => $i->utente?->id, 'nome' => $i->utente?->nome, 'numero_processo' => $i->utente?->numero_processo],
                'cama' => $i->cama,
                'localizacao' => Dominios::nome('localizacaos', $i->localizacao_id),
                'data_de_entrada' => $i->data_de_entrada?->format('Y-m-d'),
                'data_de_alta' => $i->data_de_alta?->format('Y-m-d'),
                'motivo' => $i->motivo_internamento,
                'responsavel' => $i->responsavel?->abrev ?: $i->responsavel?->name,
                'destino' => Dominios::nome('destinos', $i->destino_id),
                'clavien' => Dominios::nome('clavien_dindos', $i->claviemMaisGrave()),
                'bloqueado' => $i->bloquear_tabela,
            ]);

        return Inertia::render('internamentos/index', [
            'internamentos' => $internamentos,
            'filtros' => $filtros,
            'localizacoes' => Dominios::lista('localizacaos'),
            'responsaveis' => Dominios::responsaveis(),
            'anos' => range((int) now()->format('Y'), (int) substr(config('clinico.data_minima'), 0, 4)),
            'modal' => $modal,
        ]);
    }

    /** Altas cujo seguimento aos 30 dias (morbilidade) ainda não foi registado. */
    public function seguimentos(Request $request): Response
    {
        $periodos = ['90' => 90, '365' => 365, 'todos' => null];
        $periodo = array_key_exists($request->query('periodo'), $periodos) ? $request->query('periodo') : '90';
        $estado = in_array($request->query('estado'), ['em_atraso', 'a_decorrer'], true) ? $request->query('estado') : 'todos';
        $hoje = now()->startOfDay();
        $limiteAtraso = $hoje->copy()->subDays(30); // alta anterior a esta data: 30 dias já passaram

        $base = Internamento::query()
            ->comAlta()
            ->where(fn ($q) => $q->whereNull('destino_id')->orWhere('destino_id', '<>', Internamento::DESTINO_FALECIDO))
            ->whereDoesntHave('morbilidades', fn ($q) => $q->where('momento', Morbilidade::AOS_30_DIAS))
            ->when($periodos[$periodo], fn ($q, $dias) => $q->whereDate('data_de_alta', '>=', $hoje->copy()->subDays($dias)));

        $emAtraso = (clone $base)->whereDate('data_de_alta', '<', $limiteAtraso)->count();
        $aDecorrer = (clone $base)->whereDate('data_de_alta', '>=', $limiteAtraso)->count();

        $lista = (clone $base)
            ->with(['utente:id,nome,numero_processo', 'responsavel:id,name,abrev', 'morbilidades:id,internamento_id,clavien_dindo_id'])
            ->when($estado === 'em_atraso', fn ($q) => $q->whereDate('data_de_alta', '<', $limiteAtraso))
            ->when($estado === 'a_decorrer', fn ($q) => $q->whereDate('data_de_alta', '>=', $limiteAtraso))
            ->orderBy('data_de_alta')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (Internamento $i) use ($hoje) {
                $prevista = $i->data_de_alta->copy()->addDays(30);

                return [
                    'id' => $i->id,
                    'utente' => ['id' => $i->utente?->id, 'nome' => $i->utente?->nome, 'numero_processo' => $i->utente?->numero_processo],
                    'motivo' => $i->motivo_internamento,
                    'data_de_alta' => $i->data_de_alta->format('Y-m-d'),
                    'prevista' => $prevista->format('Y-m-d'),
                    // positivo = dias de atraso; negativo = dias que faltam
                    'dias_de_atraso' => (int) $prevista->startOfDay()->diffInDays($hoje, false),
                    'responsavel' => $i->responsavel?->abrev ?: $i->responsavel?->name,
                    'clavien' => Dominios::nome('clavien_dindos', $i->claviemMaisGrave()),
                ];
            });

        return Inertia::render('internamentos/seguimentos', [
            'seguimentos' => $lista,
            'filtros' => ['estado' => $estado, 'periodo' => $periodo],
            'contagens' => ['em_atraso' => $emAtraso, 'a_decorrer' => $aDecorrer],
        ]);
    }

    public function create(Request $request): Response
    {
        $utente = ctype_digit((string) $request->query('utente'))
            ? Utente::find((int) $request->query('utente'))
            : null;

        return $this->montarIndex($request, [
            'internamento' => null,
            'utente' => $utente ? $this->resumoUtente($utente) : null,
            'dominios' => $this->dominios(),
            'cirurgias' => 0,
            'listaCirurgias' => [],
            'podeNovaCirurgia' => false,
            'podeEditar' => true,
            'podeDesbloquear' => $request->user()->eAdministrador(),
            'podeApagar' => false,
            'limites' => $this->limites(),
        ]);
    }

    public function store(InternamentoRequest $request, GuardarInternamento $guardar): RedirectResponse
    {
        $internamento = $guardar->executar(new Internamento, $request->validated());

        return redirect()
            ->route('internamentos.edit', $internamento)
            ->with('sucesso', 'Internamento criado.');
    }

    public function edit(Request $request, Internamento $internamento): Response
    {
        $internamento->load(['utente', 'diagnosticos:id', 'morbilidades.complicacoes:id']);
        $utilizador = $request->user();

        $bloqueadoParaMim = $internamento->bloquear_tabela && ! $utilizador->eAdministrador();

        return Inertia::render('internamentos/formulario', [
            'internamento' => [
                'id' => $internamento->id,
                'utente_id' => $internamento->utente_id,
                'cama' => $internamento->cama,
                'localizacao_id' => $internamento->localizacao_id,
                'data_de_entrada' => $internamento->data_de_entrada?->format('Y-m-d'),
                'origem_do_internamento_id' => $internamento->origem_do_internamento_id,
                'responsavel_id' => $internamento->responsavel_id,
                'motivo_internamento' => $internamento->motivo_internamento,
                'caso_social_id' => $internamento->caso_social_id,
                'comentarios' => $internamento->comentarios ?? '',
                'bloquear_tabela' => $internamento->bloquear_tabela,
                'diagnosticos' => $internamento->diagnosticos->pluck('id')->values(),
                'morbilidade' => collect(Morbilidade::MOMENTOS)->mapWithKeys(function ($momento) use ($internamento) {
                    $m = $internamento->morbilidades->firstWhere('momento', $momento);

                    return [$momento => [
                        'complicacoes' => $m ? $m->complicacoes->map(fn ($c) => [
                            'id' => $c->id,
                            'resolucoes' => collect(json_decode($c->pivot->resolucao ?? '[]', true) ?: [])->map(fn ($r) => (int) $r)->values(),
                        ])->values() : [],
                        'clavien_dindo_id' => $m?->clavien_dindo_id,
                        'data_registo' => $m?->data_registo?->format('Y-m-d'),
                    ]];
                }),
                'data_de_alta' => $internamento->data_de_alta?->format('Y-m-d'),
                'data_de_saida' => $internamento->data_de_saida?->format('Y-m-d'),
                'estado_da_alta_id' => $internamento->estado_da_alta_id ?? Internamento::ALTA_PENDENTE,
                'destino_id' => $internamento->destino_id,
            ],
            'utente' => $internamento->utente ? $this->resumoUtente($internamento->utente) : null,
            'dominios' => $this->dominios($internamento->responsavel_id),
            'cirurgias' => DB::table('bloco_operatorios')
                ->where('internamento_id', $internamento->id)
                ->whereNull('deleted_at')
                ->count(),
            'listaCirurgias' => \App\Support\Cirurgias::porInternamento([$internamento->id])->get($internamento->id, collect())->values(),
            'podeNovaCirurgia' => $utilizador->can('create internamento') && ! $bloqueadoParaMim,
            'podeEditar' => $utilizador->can('update internamento') && ! $bloqueadoParaMim,
            'podeDesbloquear' => $utilizador->eAdministrador(),
            'podeApagar' => $utilizador->can('delete internamento') && ! $bloqueadoParaMim,
            'limites' => $this->limites(),
        ]);
    }

    public function update(InternamentoRequest $request, Internamento $internamento, GuardarInternamento $guardar): RedirectResponse
    {
        $this->impedirSeBloqueado($request, $internamento);

        $guardar->executar($internamento, $request->validated());

        return redirect()
            ->route('internamentos.edit', $internamento)
            ->with('sucesso', 'Alterações guardadas.');
    }

    public function destroy(Request $request, Internamento $internamento): RedirectResponse
    {
        $this->impedirSeBloqueado($request, $internamento);

        $cirurgias = DB::table('bloco_operatorios')
            ->where('internamento_id', $internamento->id)
            ->whereNull('deleted_at')
            ->count();

        if ($cirurgias > 0) {
            return back()->with('erro', "Este internamento tem $cirurgias cirurgia(s) registada(s) e não pode ser apagado.");
        }

        $internamento->deleted_by_id = $request->user()->id;
        $internamento->saveQuietly();
        $internamento->delete();

        return redirect()
            ->route('internamentos.index')
            ->with('sucesso', 'Internamento apagado.');
    }

    private function impedirSeBloqueado(Request $request, Internamento $internamento): void
    {
        abort_if(
            $internamento->bloquear_tabela && ! $request->user()->eAdministrador(),
            403,
            'Este registo está bloqueado. Peça a um administrador para o desbloquear.'
        );
    }

    private function resumoUtente(Utente $utente): array
    {
        return [
            'id' => $utente->id,
            'nome' => $utente->nome,
            'numero_processo' => $utente->numero_processo,
            'data_nascimento' => $utente->data_nascimento?->format('Y-m-d'),
            'sexo' => Dominios::nome('sexos', $utente->sexo_id),
        ];
    }

    private function dominios(?int $responsavelAtual = null): array
    {
        return [
            'localizacoes' => Dominios::lista('localizacaos'),
            'origens' => Dominios::lista('origem_do_internamentos'),
            'estados_da_alta' => Dominios::lista('estado_da_altas'),
            'destinos' => Dominios::lista('destinos'),
            'casos_sociais' => Dominios::lista('caso_socials'),
            'clavien' => Dominios::lista('clavien_dindos'),
            'resolucoes' => Dominios::lista('resolucao_complicacaos'),
            'diagnosticos' => Dominios::diagnosticos(),
            'complicacoes' => Dominios::complicacoes(),
            'responsaveis' => Dominios::responsaveis($responsavelAtual),
        ];
    }

    private function limites(): array
    {
        return [
            'data_minima' => config('clinico.data_minima'),
            'hoje' => now()->format('Y-m-d'),
        ];
    }
}
