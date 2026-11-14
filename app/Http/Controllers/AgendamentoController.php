<?php

namespace App\Http\Controllers;

use App\Models\Agendamento;
use App\Models\ListaDeEspera;
use App\Models\Utente;
use App\Support\Dominios;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Agendamento cirúrgico: agenda semanal, lista de espera e respetivos registos. */
class AgendamentoController extends Controller
{
    public function agenda(Request $request): Response
    {
        try {
            $base = $request->query('semana') ? Carbon::createFromFormat('Y-m-d', (string) $request->query('semana')) : now();
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
            ->with(['espera.utente:id,nome,numero_processo', 'espera.intervencoes:id,nome,abrev', 'responsavel:id,name,abrev'])
            ->whereBetween('start', [$inicio->copy()->startOfDay(), $fim->copy()->endOfDay()])
            ->orderBy('start')
            ->orderBy('periodo_de_agendamento_id')
            ->orderBy('sala_de_agendamento_id')
            ->get()
            ->filter(fn (Agendamento $a) => $mostrarCancelados || $a->estado() !== ListaDeEspera::ESTADO_CANCELADO)
            ->map(fn (Agendamento $a) => $this->resumo($a))
            ->groupBy('data');

        $dias = collect(range(0, $nDias - 1))->map(fn ($n) => [
            'data' => $inicio->copy()->addDays($n)->format('Y-m-d'),
            'agendamentos' => $agendamentos->get($inicio->copy()->addDays($n)->format('Y-m-d'), collect())->values(),
        ]);

        return Inertia::render('agendamento/agenda', [
            'dias' => $dias,
            'vista' => $vista,
            'semana' => $semanaBase->format('Y-m-d'),
            'anterior' => $vista === 'mes' ? $mesBase->copy()->subMonth()->format('Y-m-d') : $semanaBase->copy()->subWeek()->format('Y-m-d'),
            'seguinte' => $vista === 'mes' ? $mesBase->copy()->addMonth()->format('Y-m-d') : $semanaBase->copy()->addWeek()->format('Y-m-d'),
            'mes' => $mesBase->format('Y-m-d'),
            'hoje' => now()->format('Y-m-d'),
            'cancelados' => $mostrarCancelados,
            'estados' => Dominios::tabelaLista('estado_de_agendamentos'),
        ]);
    }

    public function listaDeEspera(Request $request): Response
    {
        $estados = ['abertas' => [1, 2], 'agendadas' => [3], 'canceladas' => [4], 'todas' => null];
        $estado = array_key_exists($request->query('estado'), $estados) ? $request->query('estado') : 'abertas';
        $prioridade = in_array((int) $request->query('prioridade'), array_keys(ListaDeEspera::PRIORIDADES), true) ? (int) $request->query('prioridade') : null;
        $q = trim((string) $request->query('q', ''));

        $lista = ListaDeEspera::query()
            ->with(['utente:id,nome,numero_processo', 'intervencoes:id,nome,abrev', 'diagnosticos:id,nome', 'responsavel:id,name,abrev', 'agendamentos'])
            ->when($estados[$estado], fn ($query, $ids) => $query->whereIn(DB::raw('CAST(estado_lista_espera AS UNSIGNED)'), $ids))
            ->when($prioridade, fn ($query, $p) => $query->where('prioridade_id', $p))
            ->when($q !== '', fn ($query) => $query->whereHas('utente', fn ($u) => $u->pesquisar($q)))
            ->orderByRaw('CASE prioridade_id WHEN 2 THEN 0 WHEN 3 THEN 1 WHEN 1 THEN 2 ELSE 3 END')
            ->orderBy('data_de_lista')
            ->paginate(25)
            ->withQueryString()
            ->through(function (ListaDeEspera $e) {
                $proximo = $e->agendamentos->filter(fn ($a) => $a->estado() !== ListaDeEspera::ESTADO_CANCELADO)->sortByDesc('start')->first();

                return [
                    'id' => $e->id,
                    'utente' => ['id' => $e->utente?->id, 'nome' => $e->utente?->nome, 'numero_processo' => $e->utente?->numero_processo],
                    'data' => $e->data_de_lista?->format('Y-m-d'),
                    'prioridade' => (int) $e->prioridade_id,
                    'estado' => (int) $e->estado_lista_espera,
                    'intervencoes' => $e->intervencoes->map(fn ($i) => trim($i->abrev ?: $i->nome))->values(),
                    'diagnosticos' => $e->diagnosticos->map(fn ($d) => trim($d->nome))->values(),
                    'responsavel' => $e->responsavel?->abrev ?: $e->responsavel?->name,
                    'agendamento' => $proximo ? ['id' => $proximo->id, 'data' => $proximo->start?->format('Y-m-d')] : null,
                ];
            });

        return Inertia::render('agendamento/lista-de-espera', [
            'lista' => $lista,
            'filtros' => ['estado' => $estado, 'prioridade' => $prioridade, 'q' => $q],
            'dataAlvo' => $this->dataAlvo($request),
            'prioridades' => $this->opcoesPrioridade(),
            'estados' => Dominios::tabelaLista('estado_de_agendamentos'),
        ]);
    }

    // ---- Lista de espera: registo ----

    public function criarInscricao(Request $request, Utente $utente): Response
    {
        return $this->formularioInscricao($utente, null);
    }

    public function editarInscricao(ListaDeEspera $espera): Response
    {
        return $this->formularioInscricao($espera->utente, $espera);
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

        return redirect()->route('agendamento.espera')->with('sucesso', 'Utente inscrito em lista de espera.');
    }

    public function atualizarInscricao(Request $request, ListaDeEspera $espera): RedirectResponse
    {
        $dados = $this->validarInscricao($request);

        DB::transaction(function () use ($dados, $espera) {
            $espera->update(collect($dados)->except(['intervencoes', 'diagnosticos'])->all());
            $espera->intervencoes()->sync($dados['intervencoes']);
            $espera->diagnosticos()->sync($dados['diagnosticos'] ?? []);
        });

        return redirect()->route('agendamento.espera')->with('sucesso', 'Inscrição atualizada.');
    }

    public function cancelarInscricao(ListaDeEspera $espera): RedirectResponse
    {
        $espera->update(['estado_lista_espera' => ListaDeEspera::ESTADO_CANCELADO]);

        return redirect()->route('agendamento.espera')->with('sucesso', 'Inscrição cancelada.');
    }

    // ---- Agendamento: registo ----

    public function criarAgendamento(Request $request, ListaDeEspera $espera): Response
    {
        return $this->formularioAgendamento($espera, null, $this->dataAlvo($request));
    }

    public function editarAgendamento(Agendamento $agendamento): Response
    {
        return $this->formularioAgendamento($agendamento->espera, $agendamento);
    }

    public function guardarAgendamento(Request $request, ListaDeEspera $espera): RedirectResponse
    {
        $dados = $this->validarAgendamento($request);

        DB::transaction(function () use ($dados, $espera) {
            $agendamento = new Agendamento($this->paraGravar($dados));
            $agendamento->lista_de_espera_id = $espera->id;
            $agendamento->save();
            $this->espelharEstado($espera, $dados['estado']);
        });

        return redirect()->route('agendamento.agenda', ['semana' => $dados['data']])->with('sucesso', 'Cirurgia agendada.');
    }

    public function atualizarAgendamento(Request $request, Agendamento $agendamento): RedirectResponse
    {
        $dados = $this->validarAgendamento($request);

        DB::transaction(function () use ($dados, $agendamento) {
            $agendamento->update($this->paraGravar($dados));
            $this->espelharEstado($agendamento->espera, $dados['estado']);
        });

        return redirect()->route('agendamento.agenda', ['semana' => $dados['data']])->with('sucesso', 'Agendamento atualizado.');
    }

    public function apagarAgendamento(Request $request, Agendamento $agendamento): RedirectResponse
    {
        $agendamento->forceFill(['deleted_by_id' => $request->user()->id])->saveQuietly();
        $agendamento->delete();

        return redirect()->route('agendamento.agenda')->with('sucesso', 'Agendamento apagado.');
    }

    /** Move um agendamento para outra data (arrastar no calendário). */
    public function moverAgendamento(Request $request, Agendamento $agendamento): RedirectResponse
    {
        $dados = $request->validate([
            'data' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYears(3)->format('Y-m-d')],
        ]);
        $agendamento->update(['start' => $dados['data'].' 00:00:00', 'end' => $dados['data'].' 00:00:00']);

        return back()->with('sucesso', 'Agendamento movido para '.Carbon::parse($dados['data'])->format('d/m/Y').'.');
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
            'utente' => ['id' => $a->espera?->utente?->id, 'nome' => $a->espera?->utente?->nome, 'numero_processo' => $a->espera?->utente?->numero_processo],
            'intervencoes' => ($a->espera?->intervencoes ?? collect())->map(fn ($i) => trim($i->abrev ?: $i->nome))->values(),
            'periodo' => Dominios::tabela('periodo_de_agendamentos', $a->periodo_de_agendamento_id),
            'local' => Dominios::tabela('local_de_agendamentos', $a->local_de_agendamento_id),
            'sala' => Dominios::tabela('sala_de_agendamentos', $a->sala_de_agendamento_id),
            'tipo' => Dominios::tabela('tipo_de_agendamentos', $a->tipo_de_agendamento_id),
            'estado' => $a->estado(),
            'responsavel' => $a->responsavel?->abrev ?: $a->responsavel?->name,
            'reserva_sangue' => (string) $a->reserva_sangue === '1',
        ];
    }

    private function opcoesPrioridade(): array
    {
        return collect(ListaDeEspera::PRIORIDADES)->map(fn ($nome, $id) => ['id' => $id, 'nome' => $nome])->values()->all();
    }

    private function formularioInscricao(Utente $utente, ?ListaDeEspera $espera): Response
    {
        return Inertia::render('agendamento/inscricao', [
            'espera' => $espera ? [
                'id' => $espera->id,
                'prioridade_id' => (int) $espera->prioridade_id,
                'data_de_lista' => $espera->data_de_lista?->format('Y-m-d'),
                'responsavel_id' => $espera->responsavel_id,
                'comentarios' => $espera->comentarios ?? '',
                'intervencoes' => $espera->intervencoes()->pluck('intervencaos.id')->map(fn ($i) => (int) $i)->values(),
                'diagnosticos' => $espera->diagnosticos()->pluck('diagnosticos.id')->map(fn ($i) => (int) $i)->values(),
                'estado' => (int) $espera->estado_lista_espera,
                'agendamentos' => $espera->agendamentos()->orderByDesc('start')->get()->map(fn ($a) => [
                    'id' => $a->id,
                    'data' => $a->start?->format('Y-m-d'),
                    'estado' => $a->estado(),
                ])->values(),
            ] : null,
            'utente' => ['id' => $utente->id, 'nome' => $utente->nome, 'numero_processo' => $utente->numero_processo],
            'dominios' => [
                'prioridades' => $this->opcoesPrioridade(),
                'responsaveis' => Dominios::responsaveis($espera?->responsavel_id),
                'diagnosticos' => Dominios::diagnosticos(),
                'intervencoes' => $this->intervencoes(),
            ],
            'hoje' => now()->format('Y-m-d'),
            'estados' => Dominios::tabelaLista('estado_de_agendamentos'),
        ]);
    }

    private function formularioAgendamento(ListaDeEspera $espera, ?Agendamento $agendamento, ?string $dataSugerida = null): Response
    {
        $espera->load(['utente:id,nome,numero_processo', 'intervencoes:id,nome,abrev']);

        return Inertia::render('agendamento/agendar', [
            'agendamento' => $agendamento ? [
                'id' => $agendamento->id,
                'data' => $agendamento->start?->format('Y-m-d'),
                'periodo_id' => $agendamento->periodo_de_agendamento_id,
                'local_id' => $agendamento->local_de_agendamento_id,
                'sala_id' => $agendamento->sala_de_agendamento_id,
                'tipo_id' => $agendamento->tipo_de_agendamento_id,
                'responsavel_id' => $agendamento->responsavel_id,
                'estado' => $agendamento->estado() ?? ListaDeEspera::ESTADO_PENDENTE,
                'reserva_sangue' => (string) $agendamento->reserva_sangue === '1',
                'comentarios' => $agendamento->comentarios ?? '',
            ] : null,
            'espera' => [
                'id' => $espera->id,
                'utente' => ['id' => $espera->utente?->id, 'nome' => $espera->utente?->nome, 'numero_processo' => $espera->utente?->numero_processo],
                'intervencoes' => $espera->intervencoes->map(fn ($i) => trim($i->abrev ?: $i->nome))->values(),
                'responsavel_id' => $espera->responsavel_id,
            ],
            'dominios' => [
                'periodos' => Dominios::tabelaLista('periodo_de_agendamentos'),
                'locais' => Dominios::tabelaLista('local_de_agendamentos'),
                'salas' => Dominios::tabelaLista('sala_de_agendamentos'),
                'tipos' => Dominios::tabelaLista('tipo_de_agendamentos'),
                'estados' => Dominios::tabelaLista('estado_de_agendamentos'),
                'responsaveis' => Dominios::responsaveis($agendamento?->responsavel_id ?? $espera->responsavel_id),
            ],
            'hoje' => now()->format('Y-m-d'),
            'dataSugerida' => $dataSugerida,
        ]);
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

    private function validarAgendamento(Request $request): array
    {
        return $request->validate([
            'data' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYears(3)->format('Y-m-d')],
            'periodo_id' => ['required', 'integer', Rule::exists('periodo_de_agendamentos', 'id')],
            'local_id' => ['required', 'integer', Rule::exists('local_de_agendamentos', 'id')],
            'sala_id' => ['nullable', 'integer', Rule::exists('sala_de_agendamentos', 'id')],
            'tipo_id' => ['required', 'integer', Rule::exists('tipo_de_agendamentos', 'id')],
            'responsavel_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'estado' => ['required', 'integer', Rule::exists('estado_de_agendamentos', 'id')],
            'reserva_sangue' => ['required', 'boolean'],
            'comentarios' => ['nullable', 'string', 'max:2000'],
        ], ['required' => 'Preencha este campo.']);
    }

    private function paraGravar(array $d): array
    {
        $data = $d['data'].' 00:00:00';

        return [
            'start' => $data,
            'end' => $data,
            'periodo_de_agendamento_id' => $d['periodo_id'],
            'local_de_agendamento_id' => $d['local_id'],
            'sala_de_agendamento_id' => $d['sala_id'] ?? null,
            'tipo_de_agendamento_id' => $d['tipo_id'],
            'responsavel_id' => $d['responsavel_id'],
            'estado_de_agendamento_id' => $d['estado'],
            'estado_de_agendamento' => (string) $d['estado'],
            'reserva_sangue' => $d['reserva_sangue'] ? '1' : '0',
            'comentarios' => $d['comentarios'] ?? null,
        ];
    }

    /** Mantém o estado da inscrição coerente com o do agendamento (Agendado, Pendente ou Cancelado). */
    private function espelharEstado(ListaDeEspera $espera, int $estado): void
    {
        if (in_array($estado, [ListaDeEspera::ESTADO_PENDENTE, ListaDeEspera::ESTADO_AGENDADO, ListaDeEspera::ESTADO_CANCELADO], true)) {
            $espera->update(['estado_lista_espera' => (string) $estado]);
        }
    }
}
