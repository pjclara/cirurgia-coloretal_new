<?php

namespace App\Http\Controllers;

use App\Http\Requests\UtenteRequest;
use App\Models\Internamento;
use App\Models\Utente;
use App\Support\Cirurgias;
use App\Support\Dominios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UtenteController extends Controller
{
    public function index(Request $request): Response
    {
        $termo = trim((string) $request->query('q', ''));

        $utentes = Utente::query()
            ->pesquisar($termo)
            ->withCount('internamentos')
            ->orderBy('nome')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Utente $u) => [
                'id' => $u->id,
                'nome' => $u->nome,
                'numero_processo' => $u->numero_processo,
                'numero_utente' => $u->numero_utente,
                'data_nascimento' => $u->data_nascimento?->format('Y-m-d'),
                'sexo' => Dominios::nome('sexos', $u->sexo_id),
                'concelho' => Dominios::nome('concelhos', $u->concelho_id),
                'internamentos' => $u->internamentos_count,
            ]);

        return Inertia::render('utentes/index', [
            'utentes' => $utentes,
            'q' => $termo,
        ]);
    }

    /** Pesquisa rápida usada no cabeçalho e no formulário de internamento. */
    public function pesquisa(Request $request): JsonResponse
    {
        $termo = trim((string) $request->query('q', ''));
        if (mb_strlen($termo) < 2) {
            return response()->json([]);
        }

        $utentes = Utente::query()
            ->pesquisar($termo)
            ->orderBy('nome')
            ->limit(8)
            ->get(['id', 'nome', 'numero_processo', 'data_nascimento']);

        $internados = Internamento::query()
            ->internados()
            ->whereIn('utente_id', $utentes->pluck('id'))
            ->pluck('id', 'utente_id');

        return response()->json($utentes->map(fn (Utente $u) => [
            'id' => $u->id,
            'nome' => $u->nome,
            'numero_processo' => $u->numero_processo,
            'data_nascimento' => $u->data_nascimento?->format('Y-m-d'),
            'internamento_aberto_id' => $internados[$u->id] ?? null,
        ]));
    }

    public function create(Request $request): Response
    {
        return Inertia::render('utentes/formulario', [
            'utente' => null,
            'dominios' => $this->dominios(),
            'processoSugerido' => ctype_digit((string) $request->query('processo')) ? (string) $request->query('processo') : '',
        ]);
    }

    public function store(UtenteRequest $request): RedirectResponse
    {
        $utente = Utente::create($request->validated());

        return redirect()
            ->route('utentes.show', $utente)
            ->with('sucesso', 'Utente criado.');
    }

    public function show(Utente $utente): Response
    {
        $cirurgias = Cirurgias::porInternamento($utente->internamentos()->select('id'));

        $internamentos = $utente->internamentos()
            ->with(['responsavel:id,name,abrev', 'diagnosticos:id,nome'])
            ->get()
            ->map(fn (Internamento $i) => [
                'id' => $i->id,
                'cirurgias' => $cirurgias[$i->id] ?? [],
                'data_de_entrada' => $i->data_de_entrada?->format('Y-m-d'),
                'data_de_alta' => $i->data_de_alta?->format('Y-m-d'),
                'motivo' => $i->motivo_internamento,
                'localizacao' => Dominios::nome('localizacaos', $i->localizacao_id),
                'destino' => Dominios::nome('destinos', $i->destino_id),
                'clavien' => Dominios::nome('clavien_dindos', $i->clavien_dindo_id),
                'responsavel' => $i->responsavel?->abrev ?: $i->responsavel?->name,
                'diagnosticos' => $i->diagnosticos->map(fn ($d) => trim($d->nome))->values(),
            ]);

        return Inertia::render('utentes/ficha', [
            'utente' => $this->paraFormulario($utente) + [
                'sexo' => Dominios::nome('sexos', $utente->sexo_id),
                'concelho' => Dominios::nome('concelhos', $utente->concelho_id),
                'estado_civil' => Dominios::nome('estado_civils', $utente->estado_civil_id),
            ],
            'internamentos' => $internamentos,
        ]);
    }

    public function edit(Utente $utente): Response
    {
        return Inertia::render('utentes/formulario', [
            'utente' => $this->paraFormulario($utente),
            'dominios' => $this->dominios(),
            'processoSugerido' => '',
        ]);
    }

    public function update(UtenteRequest $request, Utente $utente): RedirectResponse
    {
        $utente->update($request->validated());

        return redirect()
            ->route('utentes.show', $utente)
            ->with('sucesso', 'Alterações guardadas.');
    }

    private function paraFormulario(Utente $utente): array
    {
        return [
            'id' => $utente->id,
            'nome' => $utente->nome,
            'numero_processo' => $utente->numero_processo,
            'numero_utente' => $utente->numero_utente,
            'data_nascimento' => $utente->data_nascimento?->format('Y-m-d'),
            'sexo_id' => $utente->sexo_id,
            'concelho_id' => $utente->concelho_id,
            'estado_civil_id' => $utente->estado_civil_id,
            'etnia_id' => $utente->etnia_id,
            'classe_social_id' => $utente->classe_social_id,
        ];
    }

    private function dominios(): array
    {
        return [
            'sexos' => Dominios::lista('sexos'),
            'concelhos' => Dominios::lista('concelhos'),
            'estados_civis' => Dominios::lista('estado_civils'),
            'etnias' => Dominios::lista('etnias'),
            'classes_sociais' => Dominios::lista('classe_socials'),
        ];
    }
}
