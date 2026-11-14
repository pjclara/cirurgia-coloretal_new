<?php

namespace App\Http\Controllers;

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
        $equipa = $request->query('equipa');

        return $this->montar($request, $formulario->propsEdicao($request, $internamento) + [
            'fechar_url' => $equipa ? '/quadro?equipa='.urlencode((string) $equipa) : '/quadro',
            'origem' => 'quadro',
        ]);
    }

    private function montar(Request $request, ?array $modal): Response
    {
        $equipa = in_array($request->query('equipa'), ['A', 'B'], true) ? $request->query('equipa') : null;

        $internados = $this->internados($equipa);

        return Inertia::render('quadro/index', [
            'internados' => $internados,
            'equipa' => $equipa,
            'hoje' => now()->format('Y-m-d'),
            'modal' => $modal,
        ]);
    }

    /** Doentes internados neste momento, por localização e cama (opcionalmente de uma equipa). */
    private function internados(?string $equipa): Collection
    {
        $cirurgias = Cirurgias::porInternamento(Internamento::query()->internados()->select('id'));

        return Internamento::query()
            ->internados()
            ->with(['utente:id,nome,data_nascimento,sexo_id,numero_processo', 'responsavel:id,name,abrev,equipa', 'diagnosticos:id,nome,abrev'])
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
