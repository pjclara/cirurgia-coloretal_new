<?php

namespace App\Http\Controllers;

use App\Models\BlocoOperatorio;
use App\Models\Internamento;
use App\Support\Cirurgias;
use App\Support\Dominios;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quadro do serviço: todos os doentes internados neste momento, por localização e cama.
 */
class QuadroController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return $this->montar($request, null);
    }

    /** O quadro com o formulário do internamento, editável, numa modal por cima. */
    public function editar(Request $request, Internamento $internamento, InternamentoController $formulario): Response
    {
        return $this->montar($request, $formulario->propsEdicao($request, $internamento) + [
            'fechar_url' => '/quadro',
            'origem' => 'quadro',
        ]);
    }

    /** O quadro com o formulário de novo internamento numa modal por cima. */
    public function novo(Request $request, InternamentoController $formulario): Response
    {
        return $this->montar($request, $formulario->propsCriacao($request) + [
            'fechar_url' => '/quadro',
            'origem' => 'quadro',
        ]);
    }

    /** O quadro com o formulário de novo utente numa modal por cima. */
    public function novoUtente(Request $request, UtenteController $formulario): Response
    {
        return $this->montar($request, null, $formulario->propsCriacao($request) + [
            'fechar_url' => '/quadro',
            'origem' => 'quadro',
        ]);
    }

    /** O quadro com o formulário de nova cirurgia (deste internamento) numa modal por cima. */
    public function novaCirurgia(Request $request, Internamento $internamento, CirurgiaController $formulario): Response
    {
        return $this->montar($request, null, null, $formulario->propsFormulario($request, $internamento, null) + [
            'fechar_url' => '/quadro',
            'voltar_url' => '/quadro/'.$internamento->id,
            'origem' => 'quadro',
        ]);
    }

    /** O quadro com o formulário de uma cirurgia existente numa modal por cima. */
    public function editarCirurgia(Request $request, BlocoOperatorio $bloco, CirurgiaController $formulario): Response
    {
        return $this->montar($request, null, null, $formulario->propsFormulario($request, $bloco->internamento, $bloco) + [
            'fechar_url' => '/quadro',
            'voltar_url' => '/quadro/'.$bloco->internamento_id,
            'origem' => 'quadro',
        ]);
    }

    private function montar(Request $request, ?array $modal, ?array $modalUtente = null, ?array $modalCirurgia = null): Response
    {
        $equipa = $this->equipaDoQuadro($request);

        $internados = $this->internados($equipa);

        return Inertia::render('quadro/index', [
            'internados' => $internados,
            'equipa' => $equipa,
            'hoje' => now()->format('Y-m-d'),
            'modal' => $modal,
            'modalUtente' => $modalUtente,
            'modalCirurgia' => $modalCirurgia,
        ]);
    }

    /**
     * Equipa mostrada no quadro: a escolhida agora (A, B ou "todas"), senão a última escolha desta sessão,
     * senão a equipa do próprio utilizador. Sem equipa, mostra todos.
     */
    private function equipaDoQuadro(Request $request): ?string
    {
        $pedida = $request->query('equipa');
        if (in_array($pedida, ['A', 'B', 'todas'], true)) {
            $request->session()->put('quadro.equipa', $pedida);
        }

        $valor = $request->session()->get('quadro.equipa', $request->user()?->equipa);

        return in_array($valor, ['A', 'B'], true) ? $valor : null;
    }

    /** Doentes internados neste momento, por localização e cama (opcionalmente de uma equipa). */
    private function internados(?string $equipa): Collection
    {
        $cirurgias = Cirurgias::porInternamento(Internamento::query()->internados()->select('id'));

        return Internamento::query()
            ->internados()
            ->with(['utente:id,nome,data_nascimento,sexo_id,numero_processo', 'utente.referenciacoesAtivas:id,utente_id', 'responsavel:id,name,abrev,equipa', 'diagnosticos:id,nome,abrev'])
            ->when($equipa, fn ($q) => $q->whereHas('responsavel', fn ($r) => $r->where('equipa', $equipa)))
            ->orderBy('localizacao_id')
            ->orderBy('cama')
            ->get()
            ->map(fn (Internamento $i) => [
                'id' => $i->id,
                'cirurgias' => $cirurgias[$i->id] ?? [],
                'cama' => $i->cama,
                'localizacao_id' => $i->localizacao_id,
                'localizacao' => Dominios::nome('localizacaos', $i->localizacao_id) ?? 'Sem localização',
                'data_de_entrada' => $i->data_de_entrada?->format('Y-m-d'),
                'motivo' => $i->motivo_internamento,
                'centro_referencia' => (bool) $i->utente?->referenciacoesAtivas->isNotEmpty(),
                'caso_social' => $i->caso_social_id !== null && $i->caso_social_id !== 1
                    ? Dominios::nome('caso_socials', $i->caso_social_id)
                    : null,
                'utente' => [
                    'id' => $i->utente?->id,
                    'nome' => $i->utente?->nome,
                    'data_nascimento' => $i->utente?->data_nascimento?->format('Y-m-d'),
                    'sexo' => Dominios::nome('sexos', $i->utente?->sexo_id),
                    'numero_processo' => $i->utente?->numero_processo,
                ],
                'responsavel' => $i->responsavel ? [
                    'nome' => $i->responsavel->name,
                    'abrev' => $i->responsavel->abrev,
                    'equipa' => $i->responsavel->equipa,
                ] : null,
                'diagnosticos' => $i->diagnosticos->map(fn ($d) => trim($d->abrev ?: $d->nome))->values(),
            ]);
    }

    /** Lista do quadro para imprimir: passar a visita e recolher registos à cabeceira. */
    public function imprimir(Request $request): Response
    {
        $equipa = in_array($request->query('equipa'), ['A', 'B'], true) ? $request->query('equipa') : null;

        return Inertia::render('quadro/imprimir', [
            'internados' => $this->internados($equipa),
            'equipa' => $equipa,
            'hoje' => now()->format('Y-m-d'),
            'geradoEm' => now()->format('d/m/Y H:i'),
        ]);
    }
}
