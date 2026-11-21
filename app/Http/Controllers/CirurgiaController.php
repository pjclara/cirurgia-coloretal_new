<?php

namespace App\Http\Controllers;

use App\Models\BlocoOperatorio;
use App\Models\BlocoOperatorioCr;
use App\Models\Internamento;
use App\Models\IntervencaoDescricao;
use App\Models\Utente;
use App\Services\SincronizarOperados;
use App\Support\CirurgiaCr;
use App\Support\Dominios;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Cirurgias (bloco operatório) de cada internamento. */
class CirurgiaController extends Controller
{
    private const CONVERSAO = 4;

    /** Campo => tabela de referência, nos dados do CR da cirurgia. */
    private const LISTAS_CR = [
        'intencao_id' => 'intencaos',
        'estoma_de_protecao_id' => 'estoma_de_protecaos',
        'local_extracao_peca_id' => 'local_extracao_pecas',
        'tipo_de_dreno_id' => 'tipo_de_drenos',
        'aderencia_id' => 'aderencias',
        'tipo_de_resseccao_id' => 'tipo_de_resseccaos',
        'neoplasia_residual_id' => 'neoplasia_residuals',
        'perdas_hematica_id' => 'perdas_hematicas',
    ];

    /** Campos com 1 = Não, 2 = Sim, 99 = Não aplicado. */
    private const SIM_NAO_CR = ['resseccao_multi_orgao', 'transfusao_intra_operatoria', 'protector_de_parede', 'complicacoes'];

    /** Campo => tabela de referência, na descrição da intervenção. */
    private const LISTAS_DESCRICAO = [
        'anastemose_modo_id' => 'anastemose_modos',
        'anastemose_via_id' => 'anastemose_vias',
        'anastemose_sentido_id' => 'anastemose_sentidos',
        'tipo_de_reconstrucao_id' => 'tipo_de_reconstrucaos',
        'localizacao_anastemose_id' => 'localizacao_anastemoses',
        'confirmacao_anastemose_id' => 'confirmacao_anastemoses',
    ];

    /** Lista de todas as cirurgias de um utente. */
    public function porUtente(Request $request, Utente $utente): Response
    {
        $cirurgias = BlocoOperatorio::query()
            ->whereIn('internamento_id', $utente->internamentos()->select('id'))
            ->with(['intervencoes:id,nome,abrev', 'internamento:id,data_de_entrada,data_de_alta,motivo_internamento'])
            ->orderByDesc('data_de_inicio')
            ->get()
            ->map(fn (BlocoOperatorio $c) => [
                'id' => $c->id,
                'data' => $c->data_de_inicio?->format('Y-m-d'),
                'tipo' => Dominios::tabela('tipo_de_cirurgias', $c->tipo_de_cirurgia_id),
                'abordagem' => Dominios::tabela('tipo_de_abordagems', $c->tipo_de_abordagem_id),
                'intervencoes' => $c->intervencoes->map(fn ($i) => trim($i->nome))->values(),
                'internamento' => [
                    'id' => $c->internamento_id,
                    'entrada' => $c->internamento?->data_de_entrada?->format('Y-m-d'),
                    'motivo' => $c->internamento?->motivo_internamento,
                ],
            ]);

        return Inertia::render('cirurgias/lista', [
            'utente' => ['id' => $utente->id, 'nome' => $utente->nome, 'numero_processo' => $utente->numero_processo],
            'cirurgias' => $cirurgias,
            'internamentos' => $utente->internamentos()->orderByDesc('data_de_entrada')
                ->get(['id', 'data_de_entrada', 'data_de_alta', 'motivo_internamento'])
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'entrada' => $i->data_de_entrada?->format('Y-m-d'),
                    'alta' => $i->data_de_alta?->format('Y-m-d'),
                    'motivo' => $i->motivo_internamento,
                ]),
        ]);
    }

    public function create(Request $request, Internamento $internamento): Response
    {
        return Inertia::render('cirurgias/formulario', $this->propsFormulario($request, $internamento, null));
    }

    public function edit(Request $request, BlocoOperatorio $bloco): Response
    {
        return Inertia::render('cirurgias/formulario', $this->propsFormulario($request, $bloco->internamento, $bloco));
    }

    public function store(Request $request, Internamento $internamento): RedirectResponse
    {
        $this->garantirEdicao($request, $internamento);

        $dados = $this->validar($request);
        $intervencoes = $dados['intervencoes'];
        unset($dados['intervencoes']);
        [$cr, $descricoes] = $this->validarCr($request, $internamento, $dados['data_de_inicio'], $intervencoes);

        DB::transaction(function () use ($internamento, $dados, $intervencoes, $cr, $descricoes) {
            $bloco = new BlocoOperatorio($dados);
            $bloco->internamento_id = $internamento->id;
            $bloco->save();
            $this->sincronizarIntervencoes($bloco, $intervencoes);
            $this->gravarCr($bloco, $cr, $descricoes);
        });

        return $this->destino($request, $internamento->utente_id, 'Cirurgia registada.'.$this->agendamentosAtualizados($internamento->utente_id));
    }

    public function update(Request $request, BlocoOperatorio $bloco): RedirectResponse
    {
        $this->garantirEdicao($request, $bloco->internamento);

        $dados = $this->validar($request);
        $intervencoes = $dados['intervencoes'];
        unset($dados['intervencoes']);
        [$cr, $descricoes] = $this->validarCr($request, $bloco->internamento, $dados['data_de_inicio'], $intervencoes);

        DB::transaction(function () use ($bloco, $dados, $intervencoes, $cr, $descricoes) {
            $bloco->update($dados);
            $this->sincronizarIntervencoes($bloco, $intervencoes);
            $this->gravarCr($bloco, $cr, $descricoes);
        });

        return $this->destino($request, $bloco->internamento->utente_id, 'Cirurgia atualizada.'.$this->agendamentosAtualizados($bloco->internamento->utente_id));
    }

    public function destroy(Request $request, BlocoOperatorio $bloco): RedirectResponse
    {
        $this->garantirEdicao($request, $bloco->internamento);

        $utenteId = $bloco->internamento->utente_id;
        $bloco->forceFill(['deleted_by_id' => $request->user()->id])->saveQuietly();
        $bloco->delete();

        return $this->destino($request, $utenteId, 'Cirurgia apagada.');
    }

    /**
     * Acrescenta e retira intervenções sem recriar as que se mantêm: as descrições (anastomose, etc.) apontam para a
     * linha da tabela de ligação, que por isso tem de manter o id. Retirar uma intervenção apaga a sua descrição.
     *
     * @param  array<int, int>  $ids
     */
    private function sincronizarIntervencoes(BlocoOperatorio $bloco, array $ids): void
    {
        $atuais = DB::table('bloco_operatorio_intervencao')->where('bloco_operatorio_id', $bloco->id)->get(['id', 'intervencao_id']);
        $retirar = $atuais->filter(fn ($l) => ! in_array((int) $l->intervencao_id, $ids, true))->pluck('id')->all();

        if ($retirar !== []) {
            IntervencaoDescricao::withTrashed()->whereIn('intervencao_id', $retirar)->forceDelete();
            DB::table('bloco_operatorio_intervencao')->whereIn('id', $retirar)->delete();
        }
        $bloco->intervencoes()->attach(array_values(array_diff($ids, $atuais->pluck('intervencao_id')->map(fn ($i) => (int) $i)->all())));
    }

    /**
     * Os dados do CR só existem em doentes referenciados ao centro de referência com cirurgia de ressecção, e só se
     * guardam quando o formulário os envia (podem preencher-se mais tarde). Devolve [dados da cirurgia|null, descrições por intervenção].
     *
     * @param  array<int, int>  $intervencoes
     * @return array{0: ?array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function validarCr(Request $request, Internamento $internamento, string $data, array $intervencoes): array
    {
        if (! CirurgiaCr::aplica($internamento, $data, $intervencoes)) {
            return [null, []];
        }

        $cr = null;
        if (is_array($request->input('cr'))) {
            $regras = [
                'asa' => ['nullable', 'integer', 'between:1,5'],
                'duracao' => ['required', 'integer', 'between:1,1440'],
                'paleativa_causa' => ['nullable', 'string', 'max:2000'],
                'resseccao_multi_orgao_quais' => ['nullable', 'string', 'max:2000'],
                'unidades_globulos' => ['nullable', 'integer', 'between:0,50'],
                'complicacoes_quais' => ['nullable', 'string', 'max:2000'],
            ];
            foreach (self::LISTAS_CR as $campo => $tabela) {
                $regras[$campo] = ['required', 'integer', Rule::exists($tabela, 'id')];
            }
            foreach (self::SIM_NAO_CR as $campo) {
                $regras[$campo] = ['required', Rule::in(['1', '2', '99'])];
            }
            $cr = $request->validate(
                collect($regras)->mapWithKeys(fn ($r, $k) => ["cr.$k" => $r])->all(),
                ['required' => 'Preencha este campo.', 'in' => 'Escolha uma opção.']
            )['cr'];

            $cr['paleativa_causa'] = (int) $cr['intencao_id'] === 2 ? ($cr['paleativa_causa'] ?? null) : null;
            $cr['resseccao_multi_orgao_quais'] = $cr['resseccao_multi_orgao'] === '2' ? ($cr['resseccao_multi_orgao_quais'] ?? null) : null;
            $cr['unidades_globulos'] = $cr['transfusao_intra_operatoria'] === '2' ? ($cr['unidades_globulos'] ?? null) : null;
            $cr['complicacoes_quais'] = $cr['complicacoes'] === '2' ? ($cr['complicacoes_quais'] ?? null) : null;
        }

        $descricoes = [];
        $enviadas = $request->input('descricoes');
        if (is_array($enviadas)) {
            $permitidas = array_values(array_intersect(array_map('intval', $intervencoes), CirurgiaCr::RESSECCOES));
            $regras = [
                'numero_cargas' => ['nullable', 'integer', 'between:0,20'],
                'distancia_linha_pectinea_anastemose' => ['nullable', 'integer', 'between:0,300'],
                'distancia_linha_pectinea_tumor' => ['nullable', 'integer', 'between:0,300'],
                'qualidade_peca_operatoria_id' => ['nullable', 'integer', Rule::exists('qualidade_peca_operatorias', 'id')],
                'comentarios' => ['nullable', 'string', 'max:2000'],
            ];
            foreach (self::LISTAS_DESCRICAO as $campo => $tabela) {
                $regras[$campo] = ['required', 'integer', Rule::exists($tabela, 'id')];
            }
            foreach (['reforco_anastemose', 'libertacao_angulo'] as $campo) {
                $regras[$campo] = ['required', Rule::in(['1', '2', '99'])];
            }
            foreach (array_keys($enviadas) as $intervencaoId) {
                if (! in_array((int) $intervencaoId, $permitidas, true)) {
                    continue;
                }
                $descricoes[(int) $intervencaoId] = $request->validate(
                    collect($regras)->mapWithKeys(fn ($r, $k) => ["descricoes.$intervencaoId.$k" => $r])->all(),
                    ['required' => 'Preencha este campo.', 'in' => 'Escolha uma opção.']
                )['descricoes'][$intervencaoId];
            }
        }

        return [$cr, $descricoes];
    }

    /**
     * @param  ?array<string, mixed>  $cr
     * @param  array<int, array<string, mixed>>  $descricoes  por id da intervenção do catálogo
     */
    private function gravarCr(BlocoOperatorio $bloco, ?array $cr, array $descricoes): void
    {
        if ($cr !== null) {
            BlocoOperatorioCr::updateOrCreate(['bloco_operatorio_id' => $bloco->id], $cr);
        }
        foreach ($descricoes as $intervencaoId => $dados) {
            $linha = DB::table('bloco_operatorio_intervencao')
                ->where('bloco_operatorio_id', $bloco->id)->where('intervencao_id', $intervencaoId)->orderBy('id')->value('id');
            if ($linha) {
                IntervencaoDescricao::updateOrCreate(['intervencao_id' => $linha], $dados);
            }
        }
    }

    /** Com a cirurgia registada, o agendamento (e a lista de espera) do utente passam a operados; devolve o aviso para a mensagem. */
    private function agendamentosAtualizados(int $utenteId): string
    {
        $n = app(SincronizarOperados::class)->paraUtente($utenteId)->count();

        return $n === 0 ? '' : ($n === 1 ? ' O agendamento foi marcado como operado.' : " $n agendamentos foram marcados como operados.");
    }

    private function garantirEdicao(Request $request, Internamento $internamento): void
    {
        abort_if($internamento->bloqueadoPara($request->user()), 403, 'Só o responsável pelo internamento ou um administrador pode alterar este registo.');
    }

    /** Dados do formulário; usados na página própria e na modal do quadro. */
    public function propsFormulario(Request $request, Internamento $internamento, ?BlocoOperatorio $bloco): array
    {
        $internamento->load('utente');
        $utilizador = $request->user();
        $bloqueado = $internamento->bloqueadoPara($utilizador);

        return [
            'cirurgia' => $bloco ? [
                'id' => $bloco->id,
                'data_de_inicio' => $bloco->data_de_inicio?->format('Y-m-d'),
                'tipo_de_cirurgia_id' => $bloco->tipo_de_cirurgia_id,
                'tipo_de_abordagem_id' => $bloco->tipo_de_abordagem_id,
                'causa_de_conversao' => $bloco->causa_de_conversao ?? '',
                're_intervencao_nao_programada_id' => $bloco->re_intervencao_nao_programada_id,
                'comentarios' => $bloco->comentarios ?? '',
                'intervencoes' => $bloco->intervencoes()->pluck('intervencaos.id')->map(fn ($i) => (int) $i)->values(),
            ] : null,
            'internamento' => [
                'id' => $internamento->id,
                'entrada' => $internamento->data_de_entrada?->format('Y-m-d'),
                'motivo' => $internamento->motivo_internamento,
            ],
            'utente' => ['id' => $internamento->utente_id, 'nome' => $internamento->utente?->nome, 'numero_processo' => $internamento->utente?->numero_processo],
            'dominios' => [
                'tipos' => Dominios::tabelaLista('tipo_de_cirurgias'),
                'abordagens' => Dominios::tabelaLista('tipo_de_abordagems'),
                'reintervencoes' => Dominios::tabelaLista('re_intervencao_nao_programadas'),
                'intervencoes' => DB::table('intervencaos as i')
                    ->leftJoin('grupo_intervencaos as g', 'g.id', '=', 'i.grupo_intervencao_id')
                    ->orderBy('g.nome')->orderBy('i.nome')
                    ->get(['i.id', 'i.nome', 'g.nome as grupo'])
                    ->map(fn ($i) => ['id' => (int) $i->id, 'nome' => trim($i->nome), 'grupo' => $i->grupo ?? 'Outro'])
                    ->all(),
            ],
            'cr' => $this->propsCr($internamento, $bloco),
            'conversao' => self::CONVERSAO,
            'hoje' => now()->format('Y-m-d'),
            'podeEditar' => $utilizador->can($bloco ? 'update internamento' : 'create internamento') && ! $bloqueado,
            'podeApagar' => $bloco && $utilizador->can('delete internamento') && ! $bloqueado,
        ];
    }

    /** Dados do CR da cirurgia: quando se aplicam, o que já está gravado e as listas de escolha. */
    private function propsCr(Internamento $internamento, ?BlocoOperatorio $bloco): array
    {
        $cr = $bloco ? BlocoOperatorioCr::where('bloco_operatorio_id', $bloco->id)->first() : null;
        $descricoes = $bloco
            ? IntervencaoDescricao::query()
                ->join('bloco_operatorio_intervencao as p', 'p.id', '=', 'intervencao_descricaos.intervencao_id')
                ->where('p.bloco_operatorio_id', $bloco->id)
                ->get(['intervencao_descricaos.*', 'p.intervencao_id as catalogo_id'])
                ->mapWithKeys(fn ($d) => [(int) $d->catalogo_id => $d->only(array_merge(
                    array_keys(self::LISTAS_DESCRICAO),
                    ['reforco_anastemose', 'libertacao_angulo', 'numero_cargas', 'distancia_linha_pectinea_anastemose', 'distancia_linha_pectinea_tumor', 'qualidade_peca_operatoria_id', 'comentarios'],
                ))])
                ->all()
            : [];

        $listas = fn (array $mapa) => collect($mapa)->mapWithKeys(fn ($tabela, $campo) => [$campo => Dominios::tabelaLista($tabela)])->all();

        return [
            'referenciado_desde' => CirurgiaCr::referenciadoDesde($internamento->utente_id),
            'resseccoes' => CirurgiaCr::RESSECCOES,
            'dados' => $cr?->only(array_merge(array_keys(self::LISTAS_CR), self::SIM_NAO_CR, ['asa', 'duracao', 'paleativa_causa', 'resseccao_multi_orgao_quais', 'unidades_globulos', 'complicacoes_quais'])),
            'descricoes' => (object) $descricoes,
            'habituais' => $this->habituais(),
            'listas' => $listas(self::LISTAS_CR),
            'listas_descricao' => $listas(self::LISTAS_DESCRICAO) + ['qualidade_peca_operatoria_id' => Dominios::tabelaLista('qualidade_peca_operatorias')],
        ];
    }

    /**
     * Valores mais frequentes (escolhas e sim/não) nas últimas cirurgias do CR do utilizador, ou de toda a equipa se ele ainda tem poucas,
     * para pré-preencher os campos vazios com um clique.
     *
     * @return array{cr: array<string, string|int>, descricao: array<string, string|int>, de: string, n: int}
     */
    private function habituais(): array
    {
        $eu = (int) auth()->id();
        $cr = BlocoOperatorioCr::query()->where('created_by_id', $eu)->latest('id')->limit(200)->get();
        $propria = $cr->count() >= 3;
        if (! $propria) {
            $cr = BlocoOperatorioCr::query()->latest('id')->limit(200)->get();
        }
        $descricoes = IntervencaoDescricao::query()->when($propria, fn ($q) => $q->where('created_by_id', $eu))->latest('id')->limit(200)->get();

        // Escolhas de listas seguem como números; os sim/não (1, 2, 99) como texto, tal como os formulários os usam.
        $textos = array_merge(self::SIM_NAO_CR, ['reforco_anastemose', 'libertacao_angulo']);
        $moda = fn ($linhas, array $campos) => collect($campos)->mapWithKeys(function ($campo) use ($linhas, $textos) {
            $valores = $linhas->pluck($campo)->filter(fn ($v) => $v !== null && $v !== '');

            if ($valores->isEmpty()) {
                return [];
            }
            $mais = $valores->countBy()->sortDesc()->keys()->first();

            return [$campo => in_array($campo, $textos, true) ? (string) $mais : (int) $mais];
        })->all();

        return [
            'cr' => $moda($cr, array_merge(array_keys(self::LISTAS_CR), self::SIM_NAO_CR)),
            'descricao' => $moda($descricoes, array_merge(array_keys(self::LISTAS_DESCRICAO), ['reforco_anastemose', 'libertacao_angulo'])),
            'de' => $propria ? 'suas' : 'equipa',
            'n' => $cr->count(),
        ];
    }

    /** Gravado a partir do quadro: volta ao quadro. */
    private function destino(Request $request, int $utenteId, string $mensagem): RedirectResponse
    {
        if ($request->input('origem', $request->query('origem')) === 'quadro') {
            return redirect()->route('quadro')->with('sucesso', $mensagem);
        }

        // Gravado a partir dos seguimentos ou da revisão de internamentos: volta ao internamento, em modal sobre a lista (só endereços desta aplicação).
        $origem = $request->input('origem', $request->query('origem'));
        if (in_array($origem, ['seguimentos', 'lista'], true)) {
            $retorno = (string) $request->input('retorno', $request->query('retorno'));
            $base = $origem === 'lista' ? '/internamentos' : '/internamentos/seguimentos';

            return redirect(str_starts_with($retorno, $base) ? $retorno : $base)->with('sucesso', $mensagem);
        }

        return redirect()->route('utentes.cirurgias', $utenteId)->with('sucesso', $mensagem);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'data_de_inicio' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'tipo_de_cirurgia_id' => ['required', 'integer', Rule::exists('tipo_de_cirurgias', 'id')],
            'tipo_de_abordagem_id' => ['required', 'integer', Rule::exists('tipo_de_abordagems', 'id')],
            'causa_de_conversao' => ['nullable', 'string', 'max:255'],
            're_intervencao_nao_programada_id' => ['required', 'integer', Rule::exists('re_intervencao_nao_programadas', 'id')],
            'comentarios' => ['nullable', 'string', 'max:2000'],
            'intervencoes' => ['required', 'array', 'min:1'],
            'intervencoes.*' => ['integer', Rule::exists('intervencaos', 'id')],
        ], [
            'required' => 'Preencha este campo.',
            'intervencoes.required' => 'Escolha pelo menos uma intervenção.',
            'intervencoes.min' => 'Escolha pelo menos uma intervenção.',
            'before_or_equal' => 'A data não pode ser futura.',
        ]);
    }
}
