<?php

namespace App\Http\Controllers;

use App\Models\Agendamento;
use App\Models\ListaDeEspera;
use App\Models\User;
use App\Models\Utente;
use App\Services\SincronizarOperados;
use App\Support\DiagnosticosCr;
use App\Support\Dominios;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Agendamento cirúrgico: agenda semanal, lista de espera e respetivos registos. */
class AgendamentoController extends Controller
{
    private const LOCAL_AMBULATORIO = 2;

    /** O período já não se escolhe: os agendamentos novos ficam de manhã. */
    private const PERIODO_POR_OMISSAO = 1;

    public function agenda(Request $request): Response
    {
        return $this->montarAgenda($request, null);
    }

    /** A agenda com o formulário de novo utente numa modal por cima; ao criar, segue para a inscrição em lista de espera. */
    public function novoUtente(Request $request, UtenteController $formulario): Response
    {
        return $this->montarAgenda($request, null, null, $formulario->propsCriacao($request) + [
            'fechar_url' => '/agendamento',
            'origem' => 'agendamento',
        ]);
    }

    private function montarAgenda(Request $request, ?array $modal, ?string $dataBase = null, ?array $modalUtente = null): Response
    {
        try {
            $data = $dataBase ?? $request->query('semana');
            $base = $data ? Carbon::createFromFormat('Y-m-d', (string) $data) : now();
        } catch (\Throwable) {
            $base = now();
        }
        $vista = $request->query('vista') === 'semana' ? 'semana' : 'mes';
        $semanaBase = $base->copy()->startOfWeek();
        $mesBase = $base->copy()->startOfMonth();
        if ($vista === 'mes') {
            $inicio = $mesBase->copy()->startOfWeek();
            $fim = $mesBase->copy()->endOfMonth()->endOfWeek();
        } else {
            $inicio = $semanaBase->copy();
            $fim = $inicio->copy()->addDays(6);
        }
        $nDias = (int) $inicio->copy()->startOfDay()->diffInDays($fim->copy()->startOfDay()) + 1;
        $mostrarCancelados = $request->boolean('cancelados');

        $agendamentos = Agendamento::query()
            ->with(['espera.utente:id,nome,numero_processo', 'espera.utente.referenciacoesAtivas', 'espera.intervencoes:id,nome,abrev', 'responsavel:id,name,abrev'])
            ->whereBetween('start', [$inicio->copy()->startOfDay(), $fim->copy()->endOfDay()])
            ->tap(fn ($q) => $this->ordenarPorDiaSalaEHora($q))
            ->get()
            ->filter(fn (Agendamento $a) => $mostrarCancelados || $a->estado() !== ListaDeEspera::ESTADO_CANCELADO)
            ->map(fn (Agendamento $a) => $this->resumo($a))
            ->groupBy('data');

        $dias = collect(range(0, $nDias - 1))->map(fn ($n) => [
            'data' => $inicio->copy()->addDays($n)->format('Y-m-d'),
            'agendamentos' => $agendamentos->get($inicio->copy()->addDays($n)->format('Y-m-d'), collect())->values(),
        ]);

        [$espera, $esperaFiltro, $esperaQ, $esperaTotal] = $this->listaParaArrastar($request);

        return Inertia::render('agendamento/agenda', [
            'dias' => $dias,
            'espera' => ['itens' => $espera, 'filtro' => $esperaFiltro, 'q' => $esperaQ, 'total' => $esperaTotal],
            'vista' => $vista,
            'semana' => $semanaBase->format('Y-m-d'),
            'anterior' => $vista === 'mes' ? $mesBase->copy()->subMonth()->format('Y-m-d') : $semanaBase->copy()->subWeek()->format('Y-m-d'),
            'seguinte' => $vista === 'mes' ? $mesBase->copy()->addMonth()->format('Y-m-d') : $semanaBase->copy()->addWeek()->format('Y-m-d'),
            'mes' => $mesBase->format('Y-m-d'),
            'hoje' => now()->format('Y-m-d'),
            'cancelados' => $mostrarCancelados,
            'estados' => Dominios::tabelaLista('estado_de_agendamentos'),
            'ambulatoriosPorValidar' => $request->user()->can('update agendamento')
                ? $this->ambulatoriosPorValidar()->limit(300)->get()->map(fn (Agendamento $a) => $this->resumo($a))->values()
                : [],
            'motivosCancelamento' => $this->motivosDeCancelamento(null),
            'modalUtente' => $modalUtente,
            'modal' => $modal ? $modal + ['fechar_url' => '/agendamento?semana='.$mesBase->format('Y-m-d')] : null,
        ]);
    }

    /**
     * Doentes em lista de espera por agendar, para o painel ao lado do calendário (arrastar para um dia).
     * Por omissão só os do próprio utilizador (responsável); "todos" mostra os de toda a equipa. A escolha fica na sessão.
     */
    private function listaParaArrastar(Request $request): array
    {
        if (in_array($request->query('espera'), ['meus', 'todos'], true)) {
            $request->session()->put('agenda.espera', $request->query('espera'));
        }
        $filtro = $request->session()->get('agenda.espera', 'meus');
        $q = trim((string) $request->query('espera_q', ''));

        $consulta = ListaDeEspera::query()
            ->porAgendar() // só se agenda quem não tem agendamento por cancelar
            ->when($filtro === 'meus', fn ($query) => $query->where('responsavel_id', $request->user()->id))
            ->when($q !== '', fn ($query) => $query->whereHas('utente', fn ($u) => $u->pesquisar($q)));

        $total = (clone $consulta)->count();
        $itens = $consulta
            ->with(['utente:id,nome,numero_processo', 'utente.referenciacoesAtivas', 'intervencoes:id,nome,abrev', 'responsavel:id,name,abrev'])
            ->orderByRaw('CASE prioridade_id WHEN 2 THEN 0 WHEN 3 THEN 1 WHEN 1 THEN 2 ELSE 3 END')
            ->orderBy('data_de_lista')
            ->limit(60)->get()
            ->map(fn (ListaDeEspera $e) => [
                'id' => $e->id,
                'utente' => ['id' => $e->utente?->id, 'nome' => $e->utente?->nome, 'numero_processo' => $e->utente?->numero_processo, 'cr' => $this->marcaCr($e->utente)],
                'data' => $e->data_de_lista?->format('Y-m-d'),
                'prioridade' => (int) $e->prioridade_id,
                'intervencoes' => $e->intervencoes->map(fn ($i) => trim($i->abrev ?: $i->nome))->values(),
                'responsavel' => $e->responsavel?->abrev ?: $e->responsavel?->name,
            ])->values()->all();

        return [$itens, $filtro, $q, $total];
    }

    /** Ordem dos agendamentos: dia, sala, hora (sem hora no fim), período e, por fim, o registo. */
    private function ordenarPorDiaSalaEHora($consulta): void
    {
        $consulta->orderByRaw('date(start)')
            ->orderBy('sala_de_agendamento_id')
            ->orderByRaw('hora is null')
            ->orderBy('hora')
            ->orderBy('periodo_de_agendamento_id')
            ->orderBy('id');
    }

    /** Mapa operatório do dia ou da semana, em formato próprio para imprimir. */
    public function mapa(Request $request): Response
    {
        $vista = $request->query('vista') === 'dia' ? 'dia' : 'semana';
        try {
            $base = $request->query('data') ? Carbon::createFromFormat('Y-m-d', (string) $request->query('data')) : now();
        } catch (\Throwable) {
            $base = now();
        }
        $inicio = $vista === 'dia' ? $base->copy()->startOfDay() : $base->copy()->startOfWeek();
        $nDias = $vista === 'dia' ? 1 : 7;
        $fim = $inicio->copy()->addDays($nDias - 1)->endOfDay();

        $agendamentos = Agendamento::query()
            ->with(['espera.utente:id,nome,numero_processo,data_nascimento', 'espera.utente.referenciacoesAtivas', 'espera.intervencoes:id,nome,abrev', 'responsavel:id,name,abrev'])
            ->whereBetween('start', [$inicio, $fim])
            ->tap(fn ($q) => $this->ordenarPorDiaSalaEHora($q))
            ->get()
            ->filter(fn (Agendamento $a) => $a->estado() !== ListaDeEspera::ESTADO_CANCELADO)
            ->map(fn (Agendamento $a) => $this->resumo($a) + [
                'idade' => ($nasc = $a->espera?->utente?->data_nascimento) ? (int) $nasc->diffInYears($a->start) : null,
            ])
            ->groupBy('data');

        // Só se mostram os dias com cirurgias.
        $dias = collect(range(0, $nDias - 1))
            ->map(fn ($n) => $inicio->copy()->addDays($n))
            ->map(fn ($d) => ['data' => $d->format('Y-m-d'), 'util' => $d->isWeekday(), 'agendamentos' => $agendamentos->get($d->format('Y-m-d'), collect())->values()])
            ->filter(fn ($d) => $d['agendamentos']->isNotEmpty())
            ->values();

        return Inertia::render('agendamento/mapa', [
            'dias' => $dias,
            'vista' => $vista,
            'inicio' => $inicio->format('Y-m-d'),
            'anterior' => $inicio->copy()->subDays($nDias)->format('Y-m-d'),
            'seguinte' => $inicio->copy()->addDays($nDias)->format('Y-m-d'),
            'geradoEm' => now()->format('d/m/Y H:i'),
        ]);
    }

    public function listaDeEspera(Request $request): Response
    {
        return $this->montarLista($request, null);
    }

    private function montarLista(Request $request, ?array $modal): Response
    {
        $estados = ['abertas' => [1, 2], 'agendadas' => [3], 'operadas' => [5], 'canceladas' => [4], 'todas' => null];
        $estado = array_key_exists($request->query('estado'), $estados) ? $request->query('estado') : 'abertas';
        $prioridade = in_array((int) $request->query('prioridade'), array_keys(ListaDeEspera::PRIORIDADES), true) ? (int) $request->query('prioridade') : null;
        $q = trim((string) $request->query('q', ''));
        // Ano da inscrição: só aceita anos que existem na lista.
        $anos = ListaDeEspera::query()->selectRaw('YEAR(data_de_lista) as ano')->distinct()->orderByDesc('ano')->pluck('ano')->map(fn ($a) => (int) $a)->all();
        $ano = in_array((int) $request->query('ano'), $anos, true) ? (int) $request->query('ano') : null;

        // Responsável: só os que têm inscrições.
        $responsaveis = User::query()->whereIn('id', ListaDeEspera::query()->select('responsavel_id'))->orderBy('name')
            ->get(['id', 'name', 'abrev'])->map(fn (User $u) => ['id' => $u->id, 'nome' => $u->name])->values()->all();
        $responsavel = in_array((int) $request->query('responsavel'), array_column($responsaveis, 'id'), true) ? (int) $request->query('responsavel') : null;

        $lista = ListaDeEspera::query()
            ->with(['utente:id,nome,numero_processo', 'utente.referenciacoesAtivas', 'intervencoes:id,nome,abrev', 'diagnosticos:id,nome', 'responsavel:id,name,abrev', 'agendamentos'])
            ->when($estados[$estado], fn ($query, $ids) => $query->whereIn(DB::raw('CAST(estado_lista_espera AS UNSIGNED)'), $ids))
            ->when($prioridade, fn ($query, $p) => $query->where('prioridade_id', $p))
            ->when($responsavel, fn ($query, $r) => $query->where('responsavel_id', $r))
            ->when($ano, fn ($query, $a) => $query->whereYear('data_de_lista', $a))
            ->when($q !== '', fn ($query) => $query->whereHas('utente', fn ($u) => $u->pesquisar($q)))
            ->orderByRaw('CASE prioridade_id WHEN 2 THEN 0 WHEN 3 THEN 1 WHEN 1 THEN 2 ELSE 3 END')
            ->orderBy('data_de_lista')
            ->paginate(25)
            ->withQueryString()
            ->through(function (ListaDeEspera $e) {
                $proximo = $e->agendamentos->filter(fn ($a) => $a->estado() !== ListaDeEspera::ESTADO_CANCELADO)->sortByDesc('start')->first();

                return [
                    'id' => $e->id,
                    'utente' => ['id' => $e->utente?->id, 'nome' => $e->utente?->nome, 'numero_processo' => $e->utente?->numero_processo, 'cr' => $this->marcaCr($e->utente)],
                    'data' => $e->data_de_lista?->format('Y-m-d'),
                    'prioridade' => (int) $e->prioridade_id,
                    'estado' => (int) $e->estado_lista_espera,
                    'intervencoes' => $e->intervencoes->map(fn ($i) => trim($i->abrev ?: $i->nome))->values(),
                    'diagnosticos' => $e->diagnosticos->map(fn ($d) => trim($d->nome))->values(),
                    'responsavel' => $e->responsavel?->abrev ?: $e->responsavel?->name,
                    'agendamento' => $proximo ? ['id' => $proximo->id, 'data' => $proximo->start?->format('Y-m-d')] : null,
                    'pode_agendar' => $e->podeAgendar(),
                ];
            });

        return Inertia::render('agendamento/lista-de-espera', [
            'lista' => $lista,
            'filtros' => ['estado' => $estado, 'prioridade' => $prioridade, 'q' => $q, 'ano' => $ano, 'responsavel' => $responsavel],
            'anos' => $anos,
            'responsaveis' => $responsaveis,
            'dataAlvo' => $this->dataAlvo($request),
            'modal' => $modal ? $modal + ['fechar_url' => '/agendamento/lista-de-espera'] : null,
            'prioridades' => $this->opcoesPrioridade(),
            'estados' => Dominios::tabelaLista('estado_de_agendamentos'),
        ]);
    }

    // ---- Lista de espera: registo ----

    public function criarInscricao(Request $request, Utente $utente): Response
    {
        return $this->inscricaoNaPagina($request, ['tipo' => 'inscricao'] + $this->formularioInscricao($utente, null));
    }

    public function editarInscricao(Request $request, ListaDeEspera $espera): Response
    {
        return $this->inscricaoNaPagina($request, ['tipo' => 'inscricao'] + $this->formularioInscricao($espera->utente, $espera));
    }

    /**
     * A inscrição abre por cima da página de onde se veio: a agenda (com os mesmos filtros) ou, por omissão, a lista de espera.
     * Ao gravar ou cancelar volta a essa página.
     */
    private function inscricaoNaPagina(Request $request, array $modal): Response
    {
        $retorno = $this->retornoDaAgenda($request);
        if ($retorno === null) {
            return $this->montarLista($request, $modal);
        }

        parse_str((string) parse_url($retorno, PHP_URL_QUERY), $filtros);
        $request->query->add(array_diff_key($filtros, $request->query->all()));

        return $this->montarAgenda($request, $modal + ['fechar_url' => $retorno, 'origem' => 'agenda', 'retorno' => $retorno]);
    }

    /** Endereço da agenda de onde se abriu uma inscrição (só desta secção), ou null se não veio da agenda. */
    private function retornoDaAgenda(Request $request): ?string
    {
        if ($request->input('origem', $request->query('origem')) !== 'agenda') {
            return null;
        }
        $retorno = (string) $request->input('retorno', $request->query('retorno'));

        return preg_match('#^/agendamento(\?[^\s]*)?$#', $retorno) ? $retorno : '/agendamento';
    }

    public function guardarInscricao(Request $request, Utente $utente): RedirectResponse
    {
        $dados = $this->validarInscricao($request);

        DB::transaction(function () use ($dados, $utente) {
            $espera = new ListaDeEspera(collect($dados)->except(['intervencoes', 'diagnosticos'])->all());
            $espera->utente_id = $utente->id;
            $espera->estado_lista_espera = ListaDeEspera::ESTADO_PENDENTE;
            $espera->save();
            $espera->intervencoes()->sync($dados['intervencoes']);
            $espera->diagnosticos()->sync($dados['diagnosticos'] ?? []);
        });
        // Os diagnósticos CR da inscrição passam sozinhos para a referenciação e para os internamentos do utente.
        DiagnosticosCr::sincronizar($utente->id, 'lista');

        return ($retorno = $this->retornoDaAgenda($request)) !== null
            ? redirect($retorno)->with('sucesso', 'Utente inscrito em lista de espera.')
            : redirect()->route('agendamento.espera')->with('sucesso', 'Utente inscrito em lista de espera.');
    }

    public function atualizarInscricao(Request $request, ListaDeEspera $espera): RedirectResponse
    {
        $this->impedirSeOperado($espera);

        $dados = $this->validarInscricao($request);

        DB::transaction(function () use ($dados, $espera) {
            $espera->update(collect($dados)->except(['intervencoes', 'diagnosticos'])->all());
            $espera->intervencoes()->sync($dados['intervencoes']);
            $espera->diagnosticos()->sync($dados['diagnosticos'] ?? []);
        });
        DiagnosticosCr::sincronizar($espera->utente_id, 'lista');

        return ($retorno = $this->retornoDaAgenda($request)) !== null
            ? redirect($retorno)->with('sucesso', 'Inscrição atualizada.')
            : redirect()->route('agendamento.espera')->with('sucesso', 'Inscrição atualizada.');
    }

    public function cancelarInscricao(Request $request, ListaDeEspera $espera): RedirectResponse
    {
        $this->impedirSeOperado($espera);

        // Com um agendamento (que não esteja cancelado) a inscrição não se cancela: cancela-se primeiro o agendamento.
        if ($espera->agendamentoAtivo()) {
            return back()->with('erro', 'Esta inscrição tem um agendamento: cancele primeiro o agendamento (e, nesse passo, escolha se a inscrição também se cancela).');
        }

        $espera->update(['estado_lista_espera' => ListaDeEspera::ESTADO_CANCELADO, 'cancelar_lista_espera' => ListaDeEspera::CANCELADA_DE_PROPOSITO]);

        return ($retorno = $this->retornoDaAgenda($request)) !== null
            ? redirect($retorno)->with('sucesso', 'Inscrição cancelada.')
            : redirect()->route('agendamento.espera')->with('sucesso', 'Inscrição cancelada.');
    }

    // ---- Agendamento: registo ----

    public function criarAgendamento(Request $request, ListaDeEspera $espera): Response|RedirectResponse
    {
        // Só se agenda de novo o que foi cancelado: se já há um agendamento, abre-se esse.
        if (! $espera->podeAgendar()) {
            return $this->recusarAgendar($espera);
        }

        $data = $this->dataAlvo($request);

        return $this->montarAgenda($request, ['tipo' => 'agendar'] + $this->formularioAgendamento($espera, null, $data), $data);
    }

    /** Doentes operados: o agendamento e a inscrição só se consultam; só um administrador os altera. */
    private function somenteConsulta(ListaDeEspera $espera, ?Agendamento $agendamento = null): bool
    {
        if (request()->user()->eAdministrador()) {
            return false;
        }

        return (int) $espera->estado_lista_espera === ListaDeEspera::ESTADO_OPERADO || $agendamento?->estado() === ListaDeEspera::ESTADO_OPERADO;
    }

    private function impedirSeOperado(ListaDeEspera $espera, ?Agendamento $agendamento = null): void
    {
        abort_if($this->somenteConsulta($espera, $agendamento), 403, 'Doente operado: o agendamento e a inscrição só se consultam; só um administrador os pode alterar.');
    }

    private function motivoDeNaoAgendar(ListaDeEspera $espera): string
    {
        return $espera->agendamentoAtivo()
            ? 'Esta inscrição já tem um agendamento: só se agenda de novo se for cancelado. Altere o que já existe.'
            : 'Esta inscrição não pode ser agendada (já foi operada ou foi cancelada).';
    }

    private function recusarAgendar(ListaDeEspera $espera): RedirectResponse
    {
        $existente = $espera->agendamentoAtivo();

        return ($existente ? redirect()->route('agendamento.edit', $existente) : redirect()->route('agendamento.espera'))
            ->with('erro', $this->motivoDeNaoAgendar($espera));
    }

    public function editarAgendamento(Request $request, Agendamento $agendamento): Response
    {
        return $this->montarAgenda($request, ['tipo' => 'agendar'] + $this->formularioAgendamento($agendamento->espera, $agendamento), $agendamento->start?->format('Y-m-d'));
    }

    public function guardarAgendamento(Request $request, ListaDeEspera $espera): RedirectResponse
    {
        if (! $espera->podeAgendar()) {
            return back()->withInput()->withErrors(['conflito' => $this->motivoDeNaoAgendar($espera)]);
        }

        $dados = $this->validarAgendamento($request);

        if ($aviso = $this->avisoDeConflito($request, $dados['data'], $dados['periodo_id'], $dados['local_id'], $dados['sala_id'] ?? null, (int) $dados['estado'], null)) {
            return back()->withInput()->withErrors(['conflito' => $aviso]);
        }

        DB::transaction(function () use ($dados, $espera, $request) {
            $agendamento = new Agendamento($this->paraGravar($dados));
            $agendamento->lista_de_espera_id = $espera->id;
            $agendamento->save();
            $this->espelharEstado($espera, $dados['estado'], $request->boolean('cancelar_inscricao'));
        });

        return redirect()->route('agendamento.agenda', ['semana' => $dados['data']])
            ->with('sucesso', 'Cirurgia agendada.'.$this->jaOperado($espera->utente_id));
    }

    public function atualizarAgendamento(Request $request, Agendamento $agendamento): RedirectResponse
    {
        $this->impedirSeOperado($agendamento->espera, $agendamento);

        // Quem já tinha período (manhã ou tarde) mantém-no.
        $dados = $this->validarAgendamento($request, $agendamento->periodo_de_agendamento_id ?? self::PERIODO_POR_OMISSAO, $agendamento->estado(), (int) $agendamento->motivo_cancelar_agendamento ?: null);

        if ($aviso = $this->avisoDeConflito($request, $dados['data'], $dados['periodo_id'], $dados['local_id'], $dados['sala_id'] ?? null, (int) $dados['estado'], $agendamento->id)) {
            return back()->withInput()->withErrors(['conflito' => $aviso]);
        }

        DB::transaction(function () use ($dados, $agendamento, $request) {
            $agendamento->update($this->paraGravar($dados));
            $this->espelharEstado($agendamento->espera, $dados['estado'], $request->boolean('cancelar_inscricao'));
        });

        return redirect()->route('agendamento.agenda', ['semana' => $dados['data']])
            ->with('sucesso', 'Agendamento atualizado.'.$this->jaOperado($agendamento->espera->utente_id));
    }

    public function apagarAgendamento(Request $request, Agendamento $agendamento): RedirectResponse
    {
        $this->impedirSeOperado($agendamento->espera, $agendamento);

        $agendamento->forceFill(['deleted_by_id' => $request->user()->id])->saveQuietly();
        $agendamento->delete();

        return redirect()->route('agendamento.agenda')->with('sucesso', 'Agendamento apagado.');
    }

    /** Ambulatórios (sem internamento) já passados que continuam por marcar como operados. */
    private function ambulatoriosPorValidar()
    {
        return Agendamento::query()
            ->with(['espera.utente:id,nome,numero_processo', 'espera.utente.referenciacoesAtivas', 'espera.intervencoes:id,nome,abrev', 'responsavel:id,name,abrev'])
            ->where('local_de_agendamento_id', self::LOCAL_AMBULATORIO)
            ->whereDate('start', '<', now()->format('Y-m-d'))
            ->whereIn(DB::raw('COALESCE(estado_de_agendamento_id, CAST(estado_de_agendamento AS UNSIGNED))'), [
                ListaDeEspera::ESTADO_PROPOSTO, ListaDeEspera::ESTADO_PENDENTE, ListaDeEspera::ESTADO_AGENDADO,
            ])
            ->orderByDesc('start')->orderByDesc('id');
    }

    /** Valida de uma vez os ambulatórios escolhidos: passam a operados (sem cirurgia registada, porque não há internamento). */
    public function validarAmbulatorios(Request $request, SincronizarOperados $operados): RedirectResponse
    {
        $dados = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:300'], 'ids.*' => ['integer']]);

        // Só os que ainda estão por validar: ignora ids de outros locais, futuros ou já fechados.
        $agendamentos = $this->ambulatoriosPorValidar()->whereIn('id', $dados['ids'])->get();
        $operados->marcarOperados($agendamentos);

        return back()->with('sucesso', $agendamentos->count() === 1 ? '1 ambulatório marcado como operado.' : $agendamentos->count().' ambulatórios marcados como operados.');
    }

    /**
     * Decide de uma vez os ambulatórios já passados: uns foram operados, outros cancelam-se, cada um com o seu motivo
     * (a inscrição dos cancelados fica cancelada, para se poder agendar de novo). Os que não estão por validar ignoram-se.
     */
    public function decidirAmbulatorios(Request $request, SincronizarOperados $operados): RedirectResponse
    {
        $dados = $request->validate([
            'operados' => ['sometimes', 'array', 'max:300'],
            'operados.*' => ['integer'],
            'cancelados' => ['sometimes', 'array', 'max:300'],
            'cancelar_inscricoes' => [Rule::requiredIf(count((array) $request->input('cancelados', [])) > 0), 'nullable', 'boolean'],
            'cancelados.*.id' => ['required', 'integer'],
            'cancelados.*.motivo' => ['required', 'integer', Rule::in(array_keys(Agendamento::MOTIVOS_CANCELAMENTO))],
        ], ['required' => 'Escolha o motivo do cancelamento.', 'in' => 'Motivo inválido.', 'cancelar_inscricoes.required' => 'Diga se as inscrições dos cancelados também se cancelam.']);

        $idsOperados = array_map('intval', $dados['operados'] ?? []);
        $motivos = collect($dados['cancelados'] ?? [])->mapWithKeys(fn ($c) => [(int) $c['id'] => (int) $c['motivo']]);
        if ($idsOperados === [] && $motivos->isEmpty()) {
            return back()->withErrors(['operados' => 'Escolha pelo menos um ambulatório.']);
        }

        // Só os que ainda estão por validar; um id nas duas listas conta como cancelado.
        $porValidar = $this->ambulatoriosPorValidar()->whereIn('id', [...$idsOperados, ...$motivos->keys()])->get()->keyBy('id');
        $paraOperar = $porValidar->only(array_diff($idsOperados, $motivos->keys()->all()))->values();
        $paraCancelar = $porValidar->only($motivos->keys()->all());

        DB::transaction(function () use ($operados, $paraOperar, $paraCancelar, $motivos, $request) {
            $operados->marcarOperados($paraOperar);
            foreach ($paraCancelar as $agendamento) {
                $agendamento->update([
                    'estado_de_agendamento_id' => ListaDeEspera::ESTADO_CANCELADO,
                    'estado_de_agendamento' => (string) ListaDeEspera::ESTADO_CANCELADO,
                    'motivo_cancelar_agendamento' => (string) $motivos[$agendamento->id],
                ]);
                $this->espelharEstado($agendamento->espera, ListaDeEspera::ESTADO_CANCELADO, $request->boolean('cancelar_inscricoes'));
            }
        });

        $partes = array_filter([
            $paraOperar->count() ? $paraOperar->count().($paraOperar->count() === 1 ? ' operado' : ' operados') : null,
            $paraCancelar->count() ? $paraCancelar->count().($paraCancelar->count() === 1 ? ' cancelado' : ' cancelados') : null,
        ]);

        return back()->with('sucesso', $partes === [] ? 'Nenhum ambulatório por validar.' : 'Ambulatórios validados: '.implode(' e ', $partes).'.');
    }

    /** Move um agendamento para outra data (arrastar no calendário). */
    public function moverAgendamento(Request $request, Agendamento $agendamento): RedirectResponse
    {
        $this->impedirSeOperado($agendamento->espera, $agendamento);

        $dados = $request->validate([
            'data' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYears(3)->format('Y-m-d')],
            'forcar' => ['sometimes', 'boolean'],
        ]);
        if ($aviso = $this->avisoDeConflito($request, $dados['data'], $agendamento->periodo_de_agendamento_id, $agendamento->local_de_agendamento_id, $agendamento->sala_de_agendamento_id, (int) $agendamento->estado(), $agendamento->id)) {
            return back()->withErrors(['conflito' => $aviso]);
        }

        $agendamento->update(['start' => $dados['data'].' 00:00:00', 'end' => $dados['data'].' 00:00:00']);

        return back()->with('sucesso', 'Agendamento movido para '.Carbon::parse($dados['data'])->format('d/m/Y').'.'.$this->jaOperado($agendamento->espera->utente_id));
    }

    private function dataAlvo(Request $request): ?string
    {
        $v = (string) $request->query('agendar_em', '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
    }

    // ---- Auxiliares ----

    private function resumo(Agendamento $a): array
    {
        return [
            'id' => $a->id,
            'data' => $a->start?->format('Y-m-d'),
            'utente' => ['id' => $a->espera?->utente?->id, 'nome' => $a->espera?->utente?->nome, 'numero_processo' => $a->espera?->utente?->numero_processo, 'cr' => $this->marcaCr($a->espera?->utente)],
            'intervencoes' => ($a->espera?->intervencoes ?? collect())->map(fn ($i) => trim($i->abrev ?: $i->nome))->values(),
            'hora' => $a->horaCurta(),
            'periodo' => Dominios::tabela('periodo_de_agendamentos', $a->periodo_de_agendamento_id),
            'local' => Dominios::tabela('local_de_agendamentos', $a->local_de_agendamento_id),
            'sala' => Dominios::tabela('sala_de_agendamentos', $a->sala_de_agendamento_id),
            'tipo' => Dominios::tabela('tipo_de_agendamentos', $a->tipo_de_agendamento_id),
            'categoria' => $a->categoria(),
            'estado' => $a->estado(),
            'responsavel' => $a->responsavel?->abrev ?: $a->responsavel?->name,
            'reserva_sangue' => in_array((string) $a->reserva_sangue, ['1', '2', '3'], true) ? (string) $a->reserva_sangue : null,
            'comentarios' => $a->comentarios,
        ];
    }

    /** O utente está referenciado ao CR (só se vê com permissão para ver referenciações). */
    private function marcaCr(?Utente $utente): bool
    {
        return $utente !== null && request()->user()->can('show referenciacao')
            && ($utente->relationLoaded('referenciacoesAtivas') ? $utente->referenciacoesAtivas->isNotEmpty() : $utente->referenciacoesAtivas()->exists());
    }

    private function opcoesPrioridade(): array
    {
        return collect(ListaDeEspera::PRIORIDADES)->map(fn ($nome, $id) => ['id' => $id, 'nome' => $nome])->values()->all();
    }

    private function formularioInscricao(Utente $utente, ?ListaDeEspera $espera): array
    {
        return [
            'espera' => $espera ? [
                'id' => $espera->id,
                'prioridade_id' => (int) $espera->prioridade_id,
                'data_de_lista' => $espera->data_de_lista?->format('Y-m-d'),
                'responsavel_id' => $espera->responsavel_id,
                'comentarios' => $espera->comentarios ?? '',
                'intervencoes' => $espera->intervencoes()->pluck('intervencaos.id')->map(fn ($i) => (int) $i)->values(),
                'diagnosticos' => $espera->diagnosticos()->pluck('diagnosticos.id')->map(fn ($i) => (int) $i)->values(),
                'estado' => (int) $espera->estado_lista_espera,
                'pode_agendar' => $espera->podeAgendar(),
                'somente_consulta' => $this->somenteConsulta($espera),
                'agendamentos' => $espera->agendamentos()->orderByDesc('start')->get()->map(fn ($a) => [
                    'id' => $a->id,
                    'data' => $a->start?->format('Y-m-d'),
                    'estado' => $a->estado(),
                ])->values(),
            ] : null,
            'utente' => ['id' => $utente->id, 'nome' => $utente->nome, 'numero_processo' => $utente->numero_processo, 'sinalizado' => $utente->referenciacoesAtivas()->exists()],
            // Diagnósticos CR que o utente já tem registados (internamentos, referenciação, outra inscrição): a inscrição nova já os traz.
            // Inscrições do utente ainda por operar (propostas, pendentes ou agendadas): para não se inscrever duas vezes.
            'inscricoes_ativas' => $espera ? [] : $utente->listaDeEspera()
                ->whereIn(DB::raw('CAST(estado_lista_espera AS UNSIGNED)'), [ListaDeEspera::ESTADO_PROPOSTO, ListaDeEspera::ESTADO_PENDENTE, ListaDeEspera::ESTADO_AGENDADO])
                ->with(['intervencoes:id,nome,abrev', 'agendamentos'])->orderBy('data_de_lista')->get()
                ->map(function (ListaDeEspera $e) {
                    $proximo = $e->agendamentos->filter(fn ($a) => $a->estado() !== ListaDeEspera::ESTADO_CANCELADO)->sortByDesc('start')->first();

                    return [
                        'id' => $e->id,
                        'data' => $e->data_de_lista?->format('Y-m-d'),
                        'estado' => (int) $e->estado_lista_espera,
                        'intervencoes' => $e->intervencoes->map(fn ($i) => trim($i->abrev ?: $i->nome))->values(),
                        'agendamento' => $proximo?->start?->format('Y-m-d'),
                        'pode_agendar' => $e->podeAgendar(),
                    ];
                })->values(),
            'diagnosticos_cr_do_utente' => $espera ? [] : DiagnosticosCr::doUtente($utente->id)->pluck('id')->values(),
            'sinalizacao' => Dominios::sinalizacao(request()->user()),
            'dominios' => [
                'prioridades' => $this->opcoesPrioridade(),
                'responsaveis' => Dominios::responsaveis($espera?->responsavel_id),
                'diagnosticos' => Dominios::diagnosticos(),
                'intervencoes' => $this->intervencoes(),
            ],
            'hoje' => now()->format('Y-m-d'),
            'estados' => Dominios::tabelaLista('estado_de_agendamentos'),
        ];
    }

    private function formularioAgendamento(ListaDeEspera $espera, ?Agendamento $agendamento, ?string $dataSugerida = null): array
    {
        $espera->load(['utente:id,nome,numero_processo', 'intervencoes:id,nome,abrev']);

        return [
            'agendamento' => $agendamento ? [
                'id' => $agendamento->id,
                'data' => $agendamento->start?->format('Y-m-d'),
                'periodo_id' => $agendamento->periodo_de_agendamento_id,
                'hora' => $agendamento->horaCurta() ?? '',
                'local_id' => $agendamento->local_de_agendamento_id,
                'sala_id' => $agendamento->sala_de_agendamento_id,
                'tipo_id' => $agendamento->tipo_de_agendamento_id,
                'responsavel_id' => $agendamento->responsavel_id,
                'estado' => $agendamento->estado() ?? ListaDeEspera::ESTADO_PENDENTE,
                'reserva_sangue' => in_array((string) $agendamento->reserva_sangue, ['1', '2', '3'], true) ? (string) $agendamento->reserva_sangue : null,
                'motivo_cancelamento' => $agendamento->motivo_cancelar_agendamento ? (int) $agendamento->motivo_cancelar_agendamento : null,
                'comentarios' => $agendamento->comentarios ?? '',
            ] : null,
            'somente_consulta' => $this->somenteConsulta($espera, $agendamento),
            'espera' => [
                'id' => $espera->id,
                'utente' => ['id' => $espera->utente?->id, 'nome' => $espera->utente?->nome, 'numero_processo' => $espera->utente?->numero_processo, 'sinalizado' => (bool) $espera->utente?->referenciacoesAtivas()->exists()],
                'intervencoes' => $espera->intervencoes->map(fn ($i) => trim($i->abrev ?: $i->nome))->values(),
                'responsavel_id' => $espera->responsavel_id,
            ],
            'dominios' => [
                'locais' => Dominios::tabelaLista('local_de_agendamentos'),
                'salas' => Dominios::tabelaLista('sala_de_agendamentos'),
                'tipos' => Dominios::tabelaLista('tipo_de_agendamentos'),
                'estados' => Dominios::tabelaLista('estado_de_agendamentos'),
                'motivos_cancelamento' => $this->motivosDeCancelamento($agendamento),
                'responsaveis' => Dominios::responsaveis($agendamento?->responsavel_id ?? $espera->responsavel_id),
            ],
            'sinalizacao' => Dominios::sinalizacao(request()->user()),
            'hoje' => now()->format('Y-m-d'),
            'dataSugerida' => $dataSugerida,
        ];
    }

    /** Motivos para escolher; um agendamento antigo com o motivo 6 mantém-no. */
    private function motivosDeCancelamento(?Agendamento $agendamento): array
    {
        $motivos = Agendamento::MOTIVOS_CANCELAMENTO;
        if ((int) $agendamento?->motivo_cancelar_agendamento === Agendamento::MOTIVO_ANTIGO) {
            $motivos[Agendamento::MOTIVO_ANTIGO] = 'Motivo antigo (sem descrição)';
        }

        return collect($motivos)->map(fn ($nome, $id) => ['id' => $id, 'nome' => $nome])->values()->all();
    }

    private function intervencoes(): array
    {
        return DB::table('intervencaos as i')
            ->leftJoin('grupo_intervencaos as g', 'g.id', '=', 'i.grupo_intervencao_id')
            ->orderBy('g.nome')->orderBy('i.nome')
            ->get(['i.id', 'i.nome', 'g.nome as grupo'])
            ->map(fn ($i) => ['id' => (int) $i->id, 'nome' => trim($i->nome), 'grupo' => $i->grupo ?? 'Outro'])
            ->all();
    }

    private function validarInscricao(Request $request): array
    {
        return $request->validate([
            'prioridade_id' => ['required', Rule::in(array_keys(ListaDeEspera::PRIORIDADES))],
            'data_de_lista' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'responsavel_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'comentarios' => ['nullable', 'string', 'max:2000'],
            'intervencoes' => ['required', 'array', 'min:1'],
            'intervencoes.*' => ['integer', Rule::exists('intervencaos', 'id')],
            'diagnosticos' => ['nullable', 'array'],
            'diagnosticos.*' => ['integer', Rule::exists('diagnosticos', 'id')],
        ], [
            'required' => 'Preencha este campo.',
            'intervencoes.required' => 'Escolha pelo menos uma intervenção.',
            'intervencoes.min' => 'Escolha pelo menos uma intervenção.',
            'before_or_equal' => 'A data não pode ser futura.',
        ]);
    }

    /** Estados que se escolhem ao agendar; um agendamento que já tem outro estado (pendente…) mantém o seu. */
    private const ESTADOS_DO_FORMULARIO = [ListaDeEspera::ESTADO_AGENDADO, ListaDeEspera::ESTADO_CANCELADO, ListaDeEspera::ESTADO_OPERADO];

    private function validarAgendamento(Request $request, int $periodoPorOmissao = self::PERIODO_POR_OMISSAO, ?int $estadoAtual = null, ?int $motivoAtual = null): array
    {
        $dados = $request->validate([
            'data' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYears(3)->format('Y-m-d')],
            'periodo_id' => ['nullable', 'integer', Rule::exists('periodo_de_agendamentos', 'id')], // já não se escolhe: por omissão, de manhã
            'hora' => ['nullable', 'date_format:H:i'],
            'local_id' => ['required', 'integer', Rule::exists('local_de_agendamentos', 'id')],
            'sala_id' => ['required', 'integer', Rule::exists('sala_de_agendamentos', 'id')], // a coluna é obrigatória na base
            'tipo_id' => ['required', 'integer', Rule::exists('tipo_de_agendamentos', 'id')],
            'responsavel_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'estado' => ['required', 'integer', Rule::in(array_filter([...self::ESTADOS_DO_FORMULARIO, $estadoAtual]))],
            'reserva_sangue' => ['required', 'integer', Rule::in([1, 2, 3])], // 1 não é necessário, 2 apenas classificação, 3 reserva de sangue
            // Ao cancelar diz-se porquê; o motivo antigo (6) só se mantém num agendamento que já o tem.
            'motivo_cancelamento' => [
                Rule::requiredIf((int) $request->input('estado') === ListaDeEspera::ESTADO_CANCELADO),
                'nullable', 'integer',
                Rule::in([...array_keys(Agendamento::MOTIVOS_CANCELAMENTO), ...($motivoAtual ? [$motivoAtual] : [])]),
            ],
            'comentarios' => ['nullable', 'string', 'max:2000'],
            // Ao cancelar pergunta-se sempre se a inscrição também se cancela (senão o doente volta à lista de espera).
            'cancelar_inscricao' => [Rule::requiredIf((int) $request->input('estado') === ListaDeEspera::ESTADO_CANCELADO), 'nullable', 'boolean'],
            'forcar' => ['sometimes', 'boolean'],
        ], ['required' => 'Preencha este campo.', 'cancelar_inscricao.required' => 'Diga se a inscrição em lista de espera também se cancela.']);
        $dados['periodo_id'] ??= $periodoPorOmissao;

        return $dados;
    }

    /**
     * Mensagem de aviso se a sala já estiver ocupada no mesmo dia e período por outra cirurgia ativa.
     * Só avisa uma vez: com `forcar` o utilizador confirma que quer marcar na mesma.
     */
    private function avisoDeConflito(Request $request, string $data, ?int $periodo, ?int $local, ?int $sala, int $estado, ?int $excluirId): ?string
    {
        if ($request->boolean('forcar') || ! $sala || $estado === ListaDeEspera::ESTADO_CANCELADO) {
            return null;
        }

        $ocupantes = Agendamento::query()
            ->with('espera.utente:id,nome')
            ->whereDate('start', $data)
            ->where('periodo_de_agendamento_id', $periodo)
            ->where('local_de_agendamento_id', $local)
            ->where('sala_de_agendamento_id', $sala)
            ->when($excluirId, fn ($q) => $q->whereKeyNot($excluirId))
            ->get()
            ->filter(fn (Agendamento $a) => $a->estado() !== ListaDeEspera::ESTADO_CANCELADO);

        if ($ocupantes->isEmpty()) {
            return null;
        }

        $nomes = $ocupantes->map(fn (Agendamento $a) => $a->espera?->utente?->nome ?? 'utente sem ficha')->implode(', ');
        $nomeSala = Dominios::tabela('sala_de_agendamentos', $sala) ?? $sala;
        $nomePeriodo = mb_strtolower((string) Dominios::tabela('periodo_de_agendamentos', $periodo));

        return "A sala $nomeSala já tem cirurgia marcada nesse dia, de $nomePeriodo: $nomes.";
    }

    private function paraGravar(array $d): array
    {
        $data = $d['data'].' 00:00:00';

        return [
            'start' => $data,
            'end' => $data,
            'periodo_de_agendamento_id' => $d['periodo_id'],
            'hora' => ! empty($d['hora']) ? $d['hora'].':00' : null,
            'local_de_agendamento_id' => $d['local_id'],
            'sala_de_agendamento_id' => $d['sala_id'] ?? null,
            'tipo_de_agendamento_id' => $d['tipo_id'],
            'responsavel_id' => $d['responsavel_id'],
            'estado_de_agendamento_id' => $d['estado'],
            // O campo antigo só admite 1 a 4: Operado e Outro guardam-se como Agendado; vale o estado_de_agendamento_id.
            'estado_de_agendamento' => (string) ($d['estado'] <= 4 ? $d['estado'] : ListaDeEspera::ESTADO_AGENDADO),
            'reserva_sangue' => (string) $d['reserva_sangue'],
            // Só um agendamento cancelado tem motivo; se voltar a agendado, limpa-se.
            'motivo_cancelar_agendamento' => (int) $d['estado'] === ListaDeEspera::ESTADO_CANCELADO && ! empty($d['motivo_cancelamento']) ? (string) $d['motivo_cancelamento'] : null,
            'comentarios' => $d['comentarios'] ?? null,
        ];
    }

    /** Se o utente já tem a cirurgia registada nesse dia, o agendamento passa a operado; devolve o aviso para a mensagem. */
    private function jaOperado(int $utenteId): string
    {
        return app(SincronizarOperados::class)->paraUtente($utenteId)->isEmpty() ? '' : ' Já tem a cirurgia registada: ficou como operado.';
    }

    /** Mantém o estado da inscrição coerente com o do agendamento (Agendado, Pendente ou Cancelado). */
    private function espelharEstado(ListaDeEspera $espera, int $estado, bool $cancelarInscricao = false): void
    {
        // Cancelar o agendamento nem sempre cancela a inscrição: por omissão o doente volta à lista de espera para ser agendado de novo.
        if ($estado === ListaDeEspera::ESTADO_CANCELADO) {
            $espera->update($cancelarInscricao
                ? ['estado_lista_espera' => (string) ListaDeEspera::ESTADO_CANCELADO, 'cancelar_lista_espera' => ListaDeEspera::CANCELADA_DE_PROPOSITO]
                : ['estado_lista_espera' => (string) ListaDeEspera::ESTADO_PROPOSTO, 'cancelar_lista_espera' => null]);

            return;
        }

        // A lista tem os mesmos estados do agendamento (1 a 6); voltar a agendar tira o motivo de cancelamento da inscrição.
        if (in_array($estado, [1, 2, 3, 4, 5, 6], true)) {
            $espera->update(['estado_lista_espera' => (string) $estado, 'cancelar_lista_espera' => null]);
        }
    }
}
