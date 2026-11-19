<?php

use App\Models\Referenciacao;
use App\Support\DiagnosticosCr;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->utilizador = utilizadorClinico(array_merge(
        Permission::where('name', 'like', '%internamento%')->orWhere('name', 'like', '%agendamento%')->pluck('name')->all(),
        ['show referenciacao', 'create referenciacao', 'update referenciacao'],
    ));
    $this->utente = novoUtente();
    $this->cr = DB::table('diagnosticos')->where('centro_de_referencia', 1)->value('id');
    $this->outroCr = DB::table('diagnosticos')->where('centro_de_referencia', 1)->where('id', '<>', $this->cr)->value('id');
});

/** Referenciação ativa do utente, com os diagnósticos dados. */
function referenciar30(object $t, array $diagnosticos = []): Referenciacao
{
    $r = new Referenciacao(['data_de_referenciacao' => now()->subDays(60)->format('Y-m-d'), 'origem_id' => 1, 'responsavel_id' => $t->utilizador->id]);
    $r->utente_id = $t->utente->id;
    $r->created_by_id = $t->utilizador->id;
    $r->save();
    foreach ($diagnosticos as $d) {
        $r->diagnosticos()->attach([$d => ['modo_id' => 99, 'histologia_id' => null, 'data_diagnostico' => null]]);
    }

    return $r;
}

function idsDe($relacao): array
{
    return $relacao->pluck('diagnosticos.id')->sort()->values()->all();
}

test('os diagnósticos CR de uma inscrição em lista de espera passam para a referenciação e para os internamentos com cirurgia', function () {
    $ref = referenciar30($this);
    $operado = internamentoOperado($this, novoInternamento($this->utente, $this->utilizador, ['data_de_entrada' => now()->subDays(20)->format('Y-m-d')]));
    $operado->diagnosticos()->sync([]);
    $intervencao = DB::table('intervencaos')->value('id');

    $this->actingAs($this->utilizador)->post("/agendamento/utentes/{$this->utente->id}/inscrever", [
        'prioridade_id' => 1, 'data_de_lista' => now()->format('Y-m-d'), 'responsavel_id' => $this->utilizador->id,
        'intervencoes' => [$intervencao], 'diagnosticos' => [$this->cr],
    ])->assertSessionHasNoErrors();

    expect(idsDe($ref->diagnosticos()))->toBe([$this->cr])
        ->and(idsDe($operado->diagnosticos()))->toBe([$this->cr]);
});

test('uma referenciação nova leva os seus diagnósticos CR para as inscrições ativas que não os têm', function () {
    $espera = novaEspera($this->utente, $this->utilizador);
    $jaTem = novaEspera($this->utente, $this->utilizador);
    $jaTem->diagnosticos()->sync([$this->outroCr]);

    DiagnosticosCr::associar(referenciar30($this), [$this->cr]);
    DiagnosticosCr::sincronizar($this->utente->id);

    // A referenciação junta os diagnósticos CR de tudo o que o utente tem (o seu e o da outra inscrição) e a inscrição sem diagnóstico CR recebe-os.
    $todos = collect([$this->cr, $this->outroCr])->sort()->values()->all();
    expect(idsDe($espera->diagnosticos()))->toBe($todos)
        ->and(idsDe($jaTem->diagnosticos()))->toBe([$this->outroCr]);
});

test('gravar um internamento passa os seus diagnósticos CR para a referenciação e para as inscrições, sem mexer no próprio internamento', function () {
    $ref = referenciar30($this);
    $espera = novaEspera($this->utente, $this->utilizador);
    $internamento = novoInternamento($this->utente, $this->utilizador, ['data_de_entrada' => now()->subDays(5)->format('Y-m-d')]);

    $this->actingAs($this->utilizador)->put("/internamentos/{$internamento->id}", payloadInternamento($internamento, ['diagnosticos' => [$this->cr]]))->assertSessionHasNoErrors();

    expect(idsDe($ref->diagnosticos()))->toBe([$this->cr])
        ->and(idsDe($espera->diagnosticos()))->toBe([$this->cr])
        ->and(idsDe($internamento->diagnosticos()))->toBe([$this->cr]);
});

test('uma inscrição cancelada ou operada não conta nem recebe diagnósticos', function () {
    $cancelada = novaEspera($this->utente, $this->utilizador);
    $cancelada->forceFill(['estado_lista_espera' => 4])->save();
    $cancelada->diagnosticos()->sync([$this->cr]);
    referenciar30($this, [$this->outroCr]);

    DiagnosticosCr::sincronizar($this->utente->id);

    expect(DiagnosticosCr::doUtente($this->utente->id)->pluck('id')->all())->not->toContain($this->cr)
        ->and($cancelada->diagnosticos()->count())->toBe(1);
});

test('a inscrição nova já traz os diagnósticos CR que o utente tem registados', function () {
    $internamento = novoInternamento($this->utente, $this->utilizador, ['data_de_entrada' => now()->subDays(5)->format('Y-m-d')]);
    $internamento->diagnosticos()->sync([$this->cr]);

    $this->actingAs($this->utilizador)->get("/agendamento/utentes/{$this->utente->id}/inscrever")->assertInertia(fn (Assert $p) => $p
        ->where('modal.diagnosticos_cr_do_utente', [$this->cr]));
});

test('o doente do CR está identificado na lista de espera, na agenda e no mapa, só para quem pode ver referenciações', function () {
    referenciar30($this);
    $espera = novaEspera($this->utente, $this->utilizador);
    novoAgendamento($espera, $this->utilizador, now()->addDays(3)->format('Y-m-d'));

    $this->actingAs($this->utilizador)->get('/agendamento/lista-de-espera')->assertInertia(fn (Assert $p) => $p
        ->where('lista.data.0.utente.cr', true));
    $this->actingAs($this->utilizador)->get('/agendamento/mapa?vista=semana&data='.now()->addDays(3)->format('Y-m-d'))->assertInertia(fn (Assert $p) => $p
        ->where('dias', fn ($dias) => collect($dias)->flatMap(fn ($d) => $d['agendamentos'])->contains(fn ($a) => $a['utente']['cr'] === true)));

    $semPermissao = utilizadorClinico();
    $this->actingAs($semPermissao)->get('/agendamento/lista-de-espera')->assertInertia(fn (Assert $p) => $p
        ->where('lista.data.0.utente.cr', false));
});
