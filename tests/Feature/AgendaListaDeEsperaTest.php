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
