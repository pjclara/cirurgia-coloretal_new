<?php

namespace App\Http\Controllers;

use App\Http\Requests\InternamentoRequest;
use App\Models\Complicacao;
use App\Models\BlocoOperatorio;
use App\Models\Internamento;
use App\Models\Morbilidade;
use App\Models\Utente;
use App\Services\GuardarInternamento;
use App\Support\Cirurgias;
use App\Support\Dominios;
use App\Support\Intervalo;
use App\Support\ReferenciadoAoCentro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InternamentoController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->montarIndex($request, null);
    }

    /** A lista de internamentos com alta, opcionalmente com o formulário de registo numa modal por cima. */
    private function montarIndex(Request $request, ?array $modal, ?array $modalCirurgia = null): Response
    {
        $filtros = [
            'q' => trim((string) $request->query('q', '')),
            'ano' => ctype_digit((string) $request->query('ano')) ? (int) $request->query('ano') : null,
            'responsavel_id' => ctype_digit((string) $request->query('responsavel_id')) ? (int) $request->query('responsavel_id') : null,
        ];
        $filtros['cr'] = $request->boolean('cr'); // só os doentes sinalizados para o centro de referência
        $filtros['cirurgia'] = $request->boolean('cirurgia'); // só internamentos com cirurgia registada

        $internamentos = Internamento::query()
            ->with(['utente:id,nome,numero_processo', 'utente.referenciacoesAtivas:id,utente_id', 'diagnosticos:id,nome', 'responsavel:id,name,abrev', 'morbilidades:id,internamento_id,momento,clavien_dindo_id'])
            ->when($filtros['cr'], fn ($q) => $q->whereHas('utente.referenciacoesAtivas'))
            ->when($filtros['cirurgia'], fn ($q) => $q->whereExists(fn ($c) => $c->selectRaw('1')->from('bloco_operatorios as b')
                ->whereColumn('b.internamento_id', 'internamentos.id')->whereNull('b.deleted_at')))
            ->comAlta() // esta lista é só para gerir altas; os internados estão no quadro
            ->when($filtros['ano'], fn ($q, $ano) => $q->whereYear('data_de_entrada', $ano))
            ->when($filtros['responsavel_id'], fn ($q, $id) => $q->where('responsavel_id', $id))
            ->when($filtros['q'] !== '', function ($q) use ($filtros) {
                $termo = $filtros['q'];
                $q->where(function ($sub) use ($termo) {
                    $sub->whereHas('diagnosticos', fn ($d) => $d->where('nome', 'like', '%'.addcslashes($termo, '%_\\').'%'))
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
                'diagnosticos' => $i->diagnosticos->pluck('nome')->map(fn ($n) => trim($n))->values()->all(),
                'responsavel' => $i->responsavel?->abrev ?: $i->responsavel?->name,
                'destino' => Dominios::nome('destinos', $i->destino_id),
                'clavien' => Dominios::nome('clavien_dindos', $i->claviemMaisGrave()),
                'bloqueado' => $i->bloquear_tabela,
                'centro_referencia' => (bool) $i->utente?->referenciacoesAtivas->isNotEmpty(),
            ]);

        return Inertia::render('internamentos/index', [
            'internamentos' => $internamentos,
            'filtros' => $filtros,
            'responsaveis' => Dominios::responsaveis(),
            'anos' => range((int) now()->format('Y'), (int) substr(config('clinico.data_minima'), 0, 4)),
            'modal' => $modal,
            'modalCirurgia' => $modalCirurgia,
        ]);
    }

    /** Altas cujo seguimento aos 30 dias (morbilidade) ainda não foi registado. */
    /** O doente está referenciado ao centro de referência até à data da alta do internamento. */
    private static function doenteCr(): string
    {
        return ReferenciadoAoCentro::existe('internamentos.utente_id', 'internamentos.data_de_alta');
    }

    public function seguimentos(Request $request): Response
    {
        return $this->montarSeguimentos($request, null);
    }

    /** O internamento numa modal por cima dos seguimentos; ao fechar ou gravar volta à lista, com os mesmos filtros. */
    public function abrirNosSeguimentos(Request $request, Internamento $internamento): Response
    {
        $consulta = $request->getQueryString();

        return $this->montarSeguimentos($request, $this->propsEdicao($request, $internamento) + [
            'fechar_url' => '/internamentos/seguimentos'.($consulta ? '?'.$consulta : ''),
            'origem' => 'seguimentos',
        ]);
    }

    /** Uma cirurgia existente numa modal por cima da lista; ao fechar ou gravar volta ao internamento (também em modal). */
    public function cirurgiaNaLista(Request $request, BlocoOperatorio $bloco, CirurgiaController $formulario): Response
    {
        return $this->montarIndex($request, null, $formulario->propsFormulario($request, $bloco->internamento, $bloco) + $this->voltarAoInternamento($request, $bloco->internamento_id, 'lista'));
    }

    /** O formulário de nova cirurgia deste internamento numa modal por cima da lista. */
    public function novaCirurgiaNaLista(Request $request, Internamento $internamento, CirurgiaController $formulario): Response
    {
        return $this->montarIndex($request, null, $formulario->propsFormulario($request, $internamento, null) + $this->voltarAoInternamento($request, $internamento->id, 'lista'));
    }

    /** Uma cirurgia existente numa modal por cima dos seguimentos; ao fechar ou gravar volta ao internamento (também em modal). */
    public function cirurgiaNosSeguimentos(Request $request, BlocoOperatorio $bloco, CirurgiaController $formulario): Response
    {
        return $this->montarSeguimentos($request, null, $formulario->propsFormulario($request, $bloco->internamento, $bloco) + $this->voltarAoInternamento($request, $bloco->internamento_id));
    }

    /** O formulário de nova cirurgia deste internamento numa modal por cima dos seguimentos. */
    public function novaCirurgiaNosSeguimentos(Request $request, Internamento $internamento, CirurgiaController $formulario): Response
    {
        return $this->montarSeguimentos($request, null, $formulario->propsFormulario($request, $internamento, null) + $this->voltarAoInternamento($request, $internamento->id));
    }

    /** @return array{fechar_url: string, origem: string} */
    private function voltarAoInternamento(Request $request, int $internamentoId, string $origem = 'seguimentos'): array
    {
        $consulta = $request->getQueryString();
        $base = $origem === 'lista' ? "/internamentos/$internamentoId/editar" : "/internamentos/seguimentos/$internamentoId";

        return ['fechar_url' => $base.($consulta ? '?'.$consulta : ''), 'origem' => $origem];
    }

    private function montarSeguimentos(Request $request, ?array $modal, ?array $modalCirurgia = null): Response
    {
        $estado = in_array($request->query('estado'), ['em_atraso', 'a_decorrer'], true) ? $request->query('estado') : 'todos';
        $responsavelId = ctype_digit((string) $request->query('responsavel_id')) ? (int) $request->query('responsavel_id') : null;
        // A identificação dos doentes do CR só é visível a quem pode ver referenciações.
        $verCr = $request->user()->can('show referenciacao');
        $soCr = $verCr && $request->boolean('cr');
        $q = trim((string) $request->query('q', ''));
        $utilizador = $request->user();
        $hoje = now()->startOfDay();
        $limiteAtraso = $hoje->copy()->subDays(30); // alta anterior a esta data: 30 dias já passaram

        // Período por trimestres, como nas análises: por omissão, o ano em curso.
        $primeiraAlta = Internamento::query()->comAlta()->min('data_de_alta');
        $intervalo = Intervalo::trimestres($request, $primeiraAlta, $hoje);
        $trimestres = $intervalo['trimestres'];
        $de = $intervalo['de'];
        $ate = $intervalo['ate'];

        $base = Internamento::query()
            ->comAlta()
            ->where(fn ($q) => $q->whereNull('destino_id')->orWhere('destino_id', '<>', Internamento::DESTINO_FALECIDO))
            ->whereDoesntHave('morbilidades', fn ($q) => $q->where('momento', Morbilidade::AOS_30_DIAS))
            // Só quem foi operado tem seguimento aos 30 dias.
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('bloco_operatorios as b')
                ->whereColumn('b.internamento_id', 'internamentos.id')->whereNull('b.deleted_at'))
            ->when($responsavelId, fn ($q, $id) => $q->where('responsavel_id', $id))
            ->when($soCr, fn ($q) => $q->whereRaw(self::doenteCr()))
            ->when($q !== '', fn ($consulta) => $consulta->whereHas('utente', fn ($u) => $u->pesquisar($q)))
            ->when($intervalo['desde'], fn ($q) => $q->whereDate('data_de_alta', '>=', $intervalo['desde'])->whereDate('data_de_alta', '<=', $intervalo['fim']));

        $emAtraso = (clone $base)->whereDate('data_de_alta', '<', $limiteAtraso)->count();
        $aDecorrer = (clone $base)->whereDate('data_de_alta', '>=', $limiteAtraso)->count();

        $lista = (clone $base)
            ->selectRaw('internamentos.*, '.($verCr ? '('.self::doenteCr().')' : '0').' as doente_cr')
            ->with(['utente:id,nome,numero_processo', 'diagnosticos:id,nome', 'responsavel:id,name,abrev', 'morbilidades:id,internamento_id,momento,clavien_dindo_id'])
            ->when($estado === 'em_atraso', fn ($q) => $q->whereDate('data_de_alta', '<', $limiteAtraso))
            ->when($estado === 'a_decorrer', fn ($q) => $q->whereDate('data_de_alta', '>=', $limiteAtraso))
            ->orderBy('data_de_alta')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (Internamento $i) use ($hoje, $utilizador) {
                $prevista = $i->data_de_alta->copy()->addDays(30);

                return [
                    'id' => $i->id,
                    'utente' => ['id' => $i->utente?->id, 'nome' => $i->utente?->nome, 'numero_processo' => $i->utente?->numero_processo],
                    'diagnosticos' => $i->diagnosticos->pluck('nome')->map(fn ($n) => trim($n))->values()->all(),
                    'data_de_alta' => $i->data_de_alta->format('Y-m-d'),
                    'prevista' => $prevista->format('Y-m-d'),
                    // positivo = dias de atraso; negativo = dias que faltam
                    'dias_de_atraso' => (int) $prevista->startOfDay()->diffInDays($hoje, false),
                    'responsavel' => $i->responsavel?->abrev ?: $i->responsavel?->name,
                    'cr' => (bool) $i->doente_cr,
                    'bloqueado' => $i->seguimentoBloqueadoPara($utilizador),
                ];
            });

        return Inertia::render('internamentos/seguimentos', [
            'modal' => $modal,
            'modalCirurgia' => $modalCirurgia,
            'podeRegistar' => $request->user()->can('update internamento'),
            'dominios' => [
                'complicacoes' => Dominios::complicacoes(),
                'resolucoes' => $this->resolucoesComGrau(),
                'clavien' => Dominios::lista('clavien_dindos'),
            ],
            'seguimentos' => $lista,
            'trimestres' => $trimestres,
            'filtros' => ['estado' => $estado, 'de' => $de, 'ate' => $ate, 'responsavel_id' => $responsavelId, 'cr' => $soCr, 'q' => $q],
            'responsaveis' => Dominios::responsaveis($responsavelId),
            'contagens' => ['em_atraso' => $emAtraso, 'a_decorrer' => $aDecorrer, 'cr' => $verCr ? (clone $base)->when(! $soCr, fn ($q) => $q->whereRaw(self::doenteCr()))->count() : null],
            'podeVerCr' => $verCr,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->montarIndex($request, $this->propsCriacao($request) + ['fechar_url' => '/internamentos']);
    }

    /** Dados do formulário de novo registo; usados na modal da lista e na do quadro. */
    public function propsCriacao(Request $request): array
    {
        $utente = ctype_digit((string) $request->query('utente'))
            ? Utente::find((int) $request->query('utente'))
            : null;

        return [
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
            // Ao registar, pergunta-se se o utente ainda não sinalizado deve ser sinalizado para o centro de referência.
            'referenciacao' => Dominios::sinalizacao($request->user()),
        ];
    }

    public function store(InternamentoRequest $request, GuardarInternamento $guardar): RedirectResponse
    {
        $internamento = $guardar->executar(new Internamento, $request->validated());

        // Registado a partir do quadro: fica no quadro.
        if ($request->input('origem') === 'quadro') {
            return redirect()->route('quadro')->with('sucesso', 'Internamento registado.');
        }

        return redirect()
            ->route('internamentos.edit', $internamento)
            ->with('sucesso', 'Internamento criado.');
    }

    /** A lista de internamentos com alta e o formulário do internamento numa modal por cima (editar a alta sem sair da lista). */
    public function editarNaLista(Request $request, Internamento $internamento): Response
    {
        $consulta = $request->getQueryString();

        return $this->montarIndex($request, $this->propsEdicao($request, $internamento) + [
            'fechar_url' => '/internamentos'.($consulta ? '?'.$consulta : ''),
            'origem' => 'lista',
        ]);
    }

    public function edit(Request $request, Internamento $internamento): Response
    {
        return Inertia::render('internamentos/formulario', $this->propsEdicao($request, $internamento));
    }

    /** Dados do formulário de edição; usados na página do internamento e na modal do quadro. */
    public function propsEdicao(Request $request, Internamento $internamento): array
    {
        $internamento->load(['utente', 'diagnosticos:id', 'morbilidades.complicacoes:id']);
        $utilizador = $request->user();

        $bloqueadoParaMim = $internamento->bloqueadoPara($utilizador);

        return [
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
            'listaCirurgias' => Cirurgias::porInternamento([$internamento->id])->get($internamento->id, collect())->values(),
            'podeNovaCirurgia' => $utilizador->can('create internamento') && ! $bloqueadoParaMim,
            'podeEditar' => $utilizador->can('update internamento') && ! $bloqueadoParaMim,
            'podeDesbloquear' => $utilizador->eAdministrador(),
            'podeApagar' => $utilizador->can('delete internamento') && ! $bloqueadoParaMim,
            'limites' => $this->limites(),
        ];
    }

    public function update(InternamentoRequest $request, Internamento $internamento, GuardarInternamento $guardar): RedirectResponse
    {
        $this->impedirSeBloqueado($request, $internamento);

        $guardar->executar($internamento, $request->validated());
        \App\Support\Pendentes::esquecer($request->user()->id); // o menu refaz as contagens

        // Gravado a partir de uma modal: volta à página que a abriu.
        if ($request->input('origem') === 'quadro') {
            return redirect()->route('quadro')->with('sucesso', 'Alterações guardadas.');
        }
        if ($request->input('origem') === 'lista') {
            return redirect()->route('internamentos.index')->with('sucesso', 'Alterações guardadas.');
        }
        // Gravado a partir de uma referenciação: volta à referenciação que abriu o internamento.
        if ($request->input('origem') === 'referenciacao') {
            $retorno = (string) $request->input('retorno');

            return redirect(str_starts_with($retorno, '/referenciacao') ? $retorno : '/referenciacao')->with('sucesso', 'Alterações guardadas.');
        }
        // Gravado a partir dos seguimentos: volta à lista, com os mesmos filtros.
        if ($request->input('origem') === 'seguimentos') {
            $retorno = (string) $request->input('retorno');

            return redirect(str_starts_with($retorno, '/internamentos/seguimentos') ? $retorno : '/internamentos/seguimentos')
                ->with('sucesso', 'Alterações guardadas.');
        }
        // Gravado a partir da «Qualidade dos dados»: volta lá, com os mesmos filtros (só endereços desta aplicação).
        if ($request->input('origem') === 'qualidade') {
            $retorno = (string) $request->input('retorno');

            return redirect(str_starts_with($retorno, '/analise/cancro-retal/qualidade') ? $retorno : '/analise/cancro-retal/qualidade')
                ->with('sucesso', 'Alterações guardadas.');
        }

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
            ->route($request->query('origem') === 'quadro' ? 'quadro' : 'internamentos.index')
            ->with('sucesso', 'Internamento apagado.');
    }

    private function resolucoesComGrau(): array
    {
        return DB::table('resolucao_complicacaos')->orderBy('id')->get(['id', 'nome', 'clavien_dindo_id'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'nome' => trim($r->nome), 'clavien_dindo_id' => $r->clavien_dindo_id ? (int) $r->clavien_dindo_id : null])
            ->all();
    }

    /** Regista a morbilidade aos 30 dias a partir da lista de seguimentos (sem abrir o formulário completo). */
    public function registarSeguimento(Request $request, Internamento $internamento, GuardarInternamento $guardar): RedirectResponse
    {
        abort_if($internamento->seguimentoBloqueadoPara($request->user()), 403, 'Este registo está bloqueado: só um administrador o pode alterar.');
        abort_if(! $internamento->data_de_alta, 422, 'Só é possível registar o seguimento aos 30 dias depois da alta.');

        $dados = $request->validate([
            'data_registo' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$internamento->data_de_alta->format('Y-m-d'), 'before_or_equal:today'],
            'complicacoes' => ['required', 'array', 'min:1'],
            'complicacoes.*.id' => ['required', 'integer', Rule::exists('complicacaos', 'id')],
            'complicacoes.*.resolucoes' => ['nullable', 'array'],
            'complicacoes.*.resolucoes.*' => ['integer', Rule::exists('resolucao_complicacaos', 'id')],
            'clavien_dindo_id' => ['nullable', 'integer', Rule::exists('clavien_dindos', 'id')],
        ], [
            'required' => 'Preencha este campo.',
            'complicacoes.required' => 'Escolha "Sem complicações" ou as complicações registadas.',
            'complicacoes.min' => 'Escolha "Sem complicações" ou as complicações registadas.',
            'data_registo.after_or_equal' => 'O seguimento não pode ser anterior à alta.',
            'before_or_equal' => 'A data não pode ser futura.',
        ]);

        $ids = collect($dados['complicacoes'])->pluck('id')->map(fn ($id) => (int) $id);
        $temSem = $ids->contains(Complicacao::SEM_COMPLICACOES);
        $temReal = $ids->contains(fn ($id) => $id !== Complicacao::SEM_COMPLICACOES);

        if ($ids->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages(['complicacoes' => 'Há complicações repetidas.']);
        }
        if ($temSem && $temReal) {
            throw ValidationException::withMessages(['complicacoes' => '"Sem complicações" não pode ser escolhida em conjunto com outras complicações.']);
        }

        $guardar->registarSeguimento($internamento, $dados);
        \App\Support\Pendentes::esquecer($request->user()->id);
        \App\Support\Pendentes::esquecer($internamento->responsavel_id);

        return back()->with('sucesso', 'Seguimento aos 30 dias registado.');
    }

    private function impedirSeBloqueado(Request $request, Internamento $internamento): void
    {
        abort_if(
            $internamento->bloqueadoPara($request->user()),
            403,
            'Só o responsável pelo internamento ou um administrador pode alterar este registo (e, se estiver bloqueado, só um administrador).'
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
            'sinalizado' => $utente->referenciacoesAtivas()->exists(),
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
            // Cada resolução traz o grau de Clavien-Dindo associado (calculado automaticamente no formulário).
            'resolucoes' => $this->resolucoesComGrau(),
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
