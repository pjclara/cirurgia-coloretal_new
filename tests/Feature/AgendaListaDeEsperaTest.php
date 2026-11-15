<?php

use App\Models\ListaDeEspera;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->eu = utilizadorClinico();
    $this->colega = utilizadorClinico();
    $this->minha = novaEspera(novoUtente(['nome' => 'Doente Meu Da Lista']), $this->eu);
    $this->doColega = novaEspera(novoUtente(['nome' => 'Doente Do Colega']), $this->colega);
    $this->ids = fn ($p) => collect($p)->pluck('id')->all();
});

test('o painel ao lado do calendário mostra por omissão só os meus doentes por agendar', function () {
    $this->actingAs($this->eu)->get('/agendamento')->assertInertia(fn (Assert $p) => $p
        ->where('espera.filtro', 'meus')
        ->where('espera.itens', fn ($l) => in_array($this->minha->id, ($this->ids)($l)) && ! in_array($this->doColega->id, ($this->ids)($l)))
        ->where('espera.itens.0.utente.nome', 'Doente Meu Da Lista')
        ->has('espera.itens.0.intervencoes'));
});

test('"todos" mostra os de toda a equipa e a escolha fica na sessão', function () {
    $this->actingAs($this->eu)->get('/agendamento?espera=todos')->assertInertia(fn (Assert $p) => $p
        ->where('espera.filtro', 'todos')
        ->where('espera.itens', fn ($l) => in_array($this->minha->id, ($this->ids)($l)) && in_array($this->doColega->id, ($this->ids)($l))));

    // Ao navegar no calendário sem o parâmetro, mantém-se.
    $this->actingAs($this->eu)->get('/agendamento?vista=semana')->assertInertia(fn (Assert $p) => $p->where('espera.filtro', 'todos'));

    $this->actingAs($this->eu)->get('/agendamento?espera=meus')->assertInertia(fn (Assert $p) => $p->where('espera.filtro', 'meus'));
});

test('o painel só tem inscrições por agendar e permite procurar', function () {
    $agendada = novaEspera(novoUtente(['nome' => 'Doente Ja Agendado']), $this->eu);
    $agendada->update(['estado_lista_espera' => ListaDeEspera::ESTADO_AGENDADO]);
    $cancelada = novaEspera(novoUtente(['nome' => 'Doente Cancelado']), $this->eu);
    $cancelada->update(['estado_lista_espera' => ListaDeEspera::ESTADO_CANCELADO]);

    $this->actingAs($this->eu)->get('/agendamento')->assertInertia(fn (Assert $p) => $p
        ->where('espera.total', 1)
        ->where('espera.itens', fn ($l) => ! in_array($agendada->id, ($this->ids)($l)) && ! in_array($cancelada->id, ($this->ids)($l))));

    $this->actingAs($this->eu)->get('/agendamento?espera=todos&espera_q=Colega')->assertInertia(fn (Assert $p) => $p
        ->where('espera.q', 'Colega')
        ->where('espera.itens', fn ($l) => ($this->ids)($l) === [$this->doColega->id]));
});

test('largar um doente da lista num dia abre o agendamento nesse dia', function () {
    $dia = now()->addDays(5)->format('Y-m-d');

    $this->actingAs($this->eu)->get("/agendamento/lista-de-espera/{$this->minha->id}/agendar?agendar_em=$dia")->assertInertia(fn (Assert $p) => $p
        ->component('agendamento/agenda')
        ->where('modal.tipo', 'agendar')
        ->where('modal.espera.id', $this->minha->id)
        ->where('modal.dataSugerida', $dia));
});

test('a lista de espera tem o estado Operado e um filtro para as operadas', function () {
    $operada = novaEspera(novoUtente(['nome' => 'Doente Ja Operado']), $this->eu);
    $operada->update(['estado_lista_espera' => ListaDeEspera::ESTADO_OPERADO]);

    // Não está por agendar: nem na lista "em espera" nem no painel do calendário.
    $this->actingAs($this->eu)->get('/agendamento/lista-de-espera?estado=abertas')->assertInertia(fn (Assert $p) => $p
        ->where('lista.data', fn ($l) => ! in_array($operada->id, ($this->ids)($l))));
    $this->actingAs($this->eu)->get('/agendamento')->assertInertia(fn (Assert $p) => $p
        ->where('espera.itens', fn ($l) => ! in_array($operada->id, ($this->ids)($l))));

    $this->actingAs($this->eu)->get('/agendamento/lista-de-espera?estado=operadas')->assertInertia(fn (Assert $p) => $p
        ->where('filtros.estado', 'operadas')
        ->where('lista.data', fn ($l) => ($this->ids)($l) === [$operada->id])
        ->where('lista.data.0.estado', 5));
});

test('ao guardar um agendamento como Operado a lista de espera passa a Operado', function () {
    $dia = now()->subDays(3)->format('Y-m-d');
    $espera = novaEspera(novoUtente(), $this->eu);
    $this->actingAs($this->eu)->post("/agendamento/lista-de-espera/{$espera->id}/agendar", [
        'data' => $dia,
        'periodo_id' => DB::table('periodo_de_agendamentos')->value('id'),
        'local_id' => DB::table('local_de_agendamentos')->value('id'),
        'sala_id' => DB::table('sala_de_agendamentos')->value('id'),
        'tipo_id' => DB::table('tipo_de_agendamentos')->value('id'),
        'responsavel_id' => $this->eu->id,
        'estado' => ListaDeEspera::ESTADO_OPERADO,
        'reserva_sangue' => false,
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect((int) $espera->fresh()->estado_lista_espera)->toBe(5)
        ->and($espera->agendamentos()->first()->estado())->toBe(5);
});

/** Agendamento de um utente novo, numa sala e hora (null = sem hora). */
function agendadoNaSala(object $t, string $dia, int $salaId, ?string $hora)
{
    $agendamento = novoAgendamento(novaEspera(novoUtente(), $t->eu), $t->eu, $dia, ['sala_de_agendamento_id' => $salaId]);
    $agendamento->forceFill(['hora' => $hora ? "$hora:00" : null])->save();

    return $agendamento;
}

test('o agendamento guarda a hora e o calendário e o mapa ordenam por dia, sala e hora', function () {
    $dia = now()->addDays(4)->format('Y-m-d');
    $salas = DB::table('sala_de_agendamentos')->orderBy('id')->limit(2)->pluck('id');
    [$s1, $s2] = [$salas[0], $salas[1]];

    $tarde = agendadoNaSala($this, $dia, $s1, '14:00');
    $semHora = agendadoNaSala($this, $dia, $s1, null);
    $outraSala = agendadoNaSala($this, $dia, $s2, '08:00');
    $cedo = agendadoNaSala($this, $dia, $s1, '08:30');

    $ordem = fn ($dias) => collect($dias)->firstWhere('data', $dia)['agendamentos'];

    $this->actingAs($this->eu)->get("/agendamento?vista=semana&semana=$dia")->assertInertia(fn (Assert $p) => $p
        ->where('dias', fn ($dias) => collect($ordem($dias))->pluck('id')->all() === [$cedo->id, $tarde->id, $semHora->id, $outraSala->id]
            && collect($ordem($dias))->pluck('hora')->all() === ['08:30', '14:00', null, '08:00']));

    $this->actingAs($this->eu)->get("/agendamento/mapa?vista=dia&data=$dia")->assertInertia(fn (Assert $p) => $p
        ->where('dias', fn ($dias) => collect(collect($dias)->first()['agendamentos'])->pluck('id')->all() === [$cedo->id, $tarde->id, $semHora->id, $outraSala->id]));
});

test('o formulário de agendar aceita a hora, recusa uma hora inválida e mover o dia mantém a hora', function () {
    $dia = now()->addDays(6)->format('Y-m-d');
    $espera = novaEspera(novoUtente(), $this->eu);
    $corpo = [
        'data' => $dia,
        'periodo_id' => DB::table('periodo_de_agendamentos')->value('id'),
        'local_id' => DB::table('local_de_agendamentos')->value('id'),
        'sala_id' => DB::table('sala_de_agendamentos')->value('id'),
        'tipo_id' => DB::table('tipo_de_agendamentos')->value('id'),
        'responsavel_id' => $this->eu->id,
        'estado' => ListaDeEspera::ESTADO_AGENDADO,
        'reserva_sangue' => false,
    ];

    $this->actingAs($this->eu)->post("/agendamento/lista-de-espera/{$espera->id}/agendar", $corpo + ['hora' => '25:99'])->assertSessionHasErrors('hora');

    $this->actingAs($this->eu)->post("/agendamento/lista-de-espera/{$espera->id}/agendar", $corpo + ['hora' => '09:30'])->assertSessionHasNoErrors();
    $agendamento = App\Models\Agendamento::where('lista_de_espera_id', $espera->id)->firstOrFail();
    expect($agendamento->horaCurta())->toBe('09:30');

    $this->actingAs($this->eu)->get("/agendamento/{$agendamento->id}")->assertInertia(fn (Assert $p) => $p->where('modal.agendamento.hora', '09:30'));

    $this->actingAs($this->eu)->patch("/agendamento/{$agendamento->id}/mover", ['data' => now()->addDays(7)->format('Y-m-d'), 'forcar' => true])->assertSessionHasNoErrors();
    expect($agendamento->fresh()->horaCurta())->toBe('09:30');

    // Sem hora também é válido.
    $outra = novaEspera(novoUtente(), $this->eu);
    $this->actingAs($this->eu)->post("/agendamento/lista-de-espera/{$outra->id}/agendar", $corpo + ['hora' => '', 'sala_id' => DB::table('sala_de_agendamentos')->orderByDesc('id')->value('id')])->assertSessionHasNoErrors();
    expect(App\Models\Agendamento::where('lista_de_espera_id', $outra->id)->first()->horaCurta())->toBeNull();
});
