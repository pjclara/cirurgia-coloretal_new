<?php

namespace App\Http\Controllers;

use App\Models\BlocoOperatorio;
use App\Models\Internamento;
use App\Support\Dominios;
use App\Support\Pendentes;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «O que me falta fazer»: o trabalho em aberto dos internamentos de que o utilizador é responsável. */
class PendentesController extends Controller
{
    private const LIMITE = 30;

    public function __invoke(Request $request): Response
    {
        return $this->montar($request);
    }

    /** Uma cirurgia numa modal por cima desta página; ao fechar ou gravar volta aqui. */
    public function cirurgia(Request $request, BlocoOperatorio $bloco, CirurgiaController $formulario): Response
    {
        return $this->montar($request, $formulario->propsFormulario($request, $bloco->internamento, $bloco) + ['fechar_url' => '/pendentes', 'origem' => 'pendentes']);
    }

    private function montar(Request $request, ?array $modalCirurgia = null): Response
    {
        $utilizador = $request->user();
        $hoje = now()->startOfDay();

        $seguimentos = Pendentes::seguimentos($utilizador->id)->with(['utente:id,nome,numero_processo', 'diagnosticos:id,nome']);
        $totalSeguimentos = (clone $seguimentos)->count();
        $seguimentos = $seguimentos->orderBy('data_de_alta')->orderBy('id')->limit(self::LIMITE)->get()->map(function (Internamento $i) use ($hoje) {
            $prevista = $i->data_de_alta->copy()->addDays(30);

            return [
                'id' => $i->id,
                'utente' => ['id' => $i->utente?->id, 'nome' => $i->utente?->nome, 'numero_processo' => $i->utente?->numero_processo],
                'diagnosticos' => $i->diagnosticos->pluck('nome')->map(fn ($n) => trim($n))->values()->all(),
                'data_de_alta' => $i->data_de_alta->format('Y-m-d'),
                'prevista' => $prevista->format('Y-m-d'),
                // positivo = dias de atraso; negativo = dias que faltam
                'dias_de_atraso' => (int) $prevista->startOfDay()->diffInDays($hoje, false),
            ];
        });

        // Os dados do CR só se mostram a quem pode ver referenciações.
        $cr = ['total' => null, 'linhas' => []];
        if ($utilizador->can('show referenciacao')) {
            $pendentes = Pendentes::cr($utilizador->id);
            $internamentos = Internamento::with('utente:id,nome,numero_processo')->whereIn('id', $pendentes->take(self::LIMITE)->pluck('internamento_id'))->get()->keyBy('id');
            $cr = [
                'total' => $pendentes->count(),
                'linhas' => $pendentes->take(self::LIMITE)->map(fn ($c) => [
                    'bloco' => $c['id'],
                    'data' => substr((string) $c['data'], 0, 10),
                    'intervencoes' => $c['intervencoes'],
                    'falta' => $c['cr_em_falta'],
                    'utente' => ['id' => $internamentos[$c['internamento_id']]->utente?->id, 'nome' => $internamentos[$c['internamento_id']]->utente?->nome, 'numero_processo' => $internamentos[$c['internamento_id']]->utente?->numero_processo],
                ])->all(),
            ];
        }

        return Inertia::render('pendentes', [
            'utilizadorId' => $utilizador->id,
            'limite' => self::LIMITE,
            'seguimentos' => ['total' => $totalSeguimentos, 'linhas' => $seguimentos],
            'cr' => $cr,
            'modalCirurgia' => $modalCirurgia,
            'podeRegistar' => $utilizador->can('update internamento'),
            'dominios' => [
                'complicacoes' => Dominios::complicacoes(),
                'resolucoes' => Dominios::resolucoesComGrau(),
                'clavien' => Dominios::lista('clavien_dindos'),
            ],
        ]);
    }
}
