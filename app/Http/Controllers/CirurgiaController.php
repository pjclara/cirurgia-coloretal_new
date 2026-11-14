<?php

namespace App\Http\Controllers;

use App\Models\BlocoOperatorio;
use App\Models\Internamento;
use App\Models\Utente;
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
        return $this->formulario($request, $internamento, null);
    }

    public function edit(Request $request, BlocoOperatorio $bloco): Response
    {
        return $this->formulario($request, $bloco->internamento, $bloco);
    }

    public function store(Request $request, Internamento $internamento): RedirectResponse
    {
        $this->garantirEdicao($request, $internamento);

        $dados = $this->validar($request);
        $intervencoes = $dados['intervencoes'];
        unset($dados['intervencoes']);

        DB::transaction(function () use ($internamento, $dados, $intervencoes) {
            $bloco = new BlocoOperatorio($dados);
            $bloco->internamento_id = $internamento->id;
            $bloco->save();
            $bloco->intervencoes()->sync($intervencoes);
        });

        return redirect()->route('utentes.cirurgias', $internamento->utente_id)->with('sucesso', 'Cirurgia registada.');
    }

    public function update(Request $request, BlocoOperatorio $bloco): RedirectResponse
    {
        $this->garantirEdicao($request, $bloco->internamento);

        $dados = $this->validar($request);
        $intervencoes = $dados['intervencoes'];
        unset($dados['intervencoes']);

        DB::transaction(function () use ($bloco, $dados, $intervencoes) {
            $bloco->update($dados);
            $bloco->intervencoes()->sync($intervencoes);
        });

        return redirect()->route('utentes.cirurgias', $bloco->internamento->utente_id)->with('sucesso', 'Cirurgia atualizada.');
    }

    public function destroy(Request $request, BlocoOperatorio $bloco): RedirectResponse
    {
        $this->garantirEdicao($request, $bloco->internamento);

        $utenteId = $bloco->internamento->utente_id;
        $bloco->forceFill(['deleted_by_id' => $request->user()->id])->saveQuietly();
        $bloco->delete();

        return redirect()->route('utentes.cirurgias', $utenteId)->with('sucesso', 'Cirurgia apagada.');
    }

    private function garantirEdicao(Request $request, Internamento $internamento): void
    {
        abort_if($internamento->bloquear_tabela && ! $request->user()->eAdministrador(), 403, 'Internamento bloqueado.');
    }

    private function formulario(Request $request, Internamento $internamento, ?BlocoOperatorio $bloco): Response
    {
        $internamento->load('utente');
        $utilizador = $request->user();
        $bloqueado = $internamento->bloquear_tabela && ! $utilizador->eAdministrador();

        return Inertia::render('cirurgias/formulario', [
            'cirurgia' => $bloco ? [
                'id' => $bloco->id,
                'data_de_inicio' => $bloco->data_de_inicio?->format('Y-m-d'),
                'tipo_de_cirurgia_id' => $bloco->tipo_de_cirurgia_id,
                'tipo_de_abordagem_id' => $bloco->tipo_de_abordagem_id,
                'causa_de_conversao' => $bloco->causa_de_conversao ?? '',
                'resseccao_de_orgao' => (int) $bloco->resseccao_de_orgao,
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
            'conversao' => self::CONVERSAO,
            'hoje' => now()->format('Y-m-d'),
            'podeEditar' => $utilizador->can($bloco ? 'update internamento' : 'create internamento') && ! $bloqueado,
            'podeApagar' => $bloco && $utilizador->can('delete internamento') && ! $bloqueado,
        ]);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'data_de_inicio' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'tipo_de_cirurgia_id' => ['required', 'integer', Rule::exists('tipo_de_cirurgias', 'id')],
            'tipo_de_abordagem_id' => ['required', 'integer', Rule::exists('tipo_de_abordagems', 'id')],
            'causa_de_conversao' => ['nullable', 'string', 'max:255'],
            'resseccao_de_orgao' => ['required', Rule::in([1, 2, 99])],
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
