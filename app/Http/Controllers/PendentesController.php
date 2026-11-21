<?php

namespace App\Http\Controllers;

use App\Models\Internamento;
use App\Models\Morbilidade;
use App\Support\Cirurgias;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «O que me falta fazer»: o trabalho em aberto dos internamentos de que o utilizador é responsável. */
class PendentesController extends Controller
{
    private const LIMITE = 30;

    public function __invoke(Request $request): Response
    {
        $utilizador = $request->user();
        $hoje = now()->startOfDay();

        $seguimentos = Internamento::query()
            ->comAlta()
            ->where('responsavel_id', $utilizador->id)
            ->where(fn ($q) => $q->whereNull('destino_id')->orWhere('destino_id', '<>', Internamento::DESTINO_FALECIDO))
            ->whereDoesntHave('morbilidades', fn ($q) => $q->where('momento', Morbilidade::AOS_30_DIAS))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('bloco_operatorios as b')
                ->whereColumn('b.internamento_id', 'internamentos.id')->whereNull('b.deleted_at'))
            ->whereDate('data_de_alta', '>=', config('clinico.data_minima'))
            ->with('utente:id,nome,numero_processo');
        $totalSeguimentos = (clone $seguimentos)->count();
        $seguimentos = $seguimentos->orderBy('data_de_alta')->orderBy('id')->limit(self::LIMITE)->get()->map(function (Internamento $i) use ($hoje) {
            $prevista = $i->data_de_alta->copy()->addDays(30);

            return [
                'id' => $i->id,
                'utente' => ['id' => $i->utente?->id, 'nome' => $i->utente?->nome, 'numero_processo' => $i->utente?->numero_processo],
                'data_de_alta' => $i->data_de_alta->format('Y-m-d'),
                // positivo = dias de atraso; negativo = dias que faltam
                'dias_de_atraso' => (int) $prevista->startOfDay()->diffInDays($hoje, false),
            ];
        });

        // Os dados do CR só se mostram a quem pode ver referenciações.
        $cr = ['total' => null, 'linhas' => []];
        if ($utilizador->can('show referenciacao')) {
            $pendentes = Cirurgias::porInternamento(Internamento::query()->where('responsavel_id', $utilizador->id)->select('id'))
                ->flatMap(fn ($cirurgias, $internamentoId) => $cirurgias->filter(fn ($c) => $c['cr_em_falta'] !== [])->map(fn ($c) => $c + ['internamento_id' => $internamentoId]))
                ->sortByDesc('data')->values();
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
        ]);
    }
}
