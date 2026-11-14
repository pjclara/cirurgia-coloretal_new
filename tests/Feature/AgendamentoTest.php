<?php

use App\Models\Agendamento;
use App\Models\ListaDeEspera;

beforeEach(function () {
    $this->utilizador = utilizadorClinico();
    $this->dia = now()->addDays(10)->format('Y-m-d');
    $this->ocupante = novoAgendamento(novaEspera(novoUtente(), $this->utilizador), $this->utilizador, $this->dia);
    $this->espera = novaEspera(novoUtente(), $this->utilizador);
});

/** Pedido de novo agendamento na mesma sala, período e local do ocupante. */
function pedidoDeAgendar(object $t, array $extra = [])
{
    return $t->actingAs($t->utilizador)->post("/agendamento/lista-de-espera/{$t->espera->id}/agendar", $extra + [
        'data' => $t->dia,
        'periodo_id' => $t->ocupante->periodo_de_agendamento_id,
        'local_id' => $t->ocupante->local_de_agendamento_id,
        'sala_id' => $t->ocupante->sala_de_agendamento_id,
        'tipo_id' => $t->ocupante->tipo_de_agendamento_id,
        'responsavel_id' => $t->utilizador->id,
        'estado' => ListaDeEspera::ESTADO_AGENDADO,
        'reserva_sangue' => false,
    ]);
}

test('agendar na mesma sala, dia e período avisa do conflito e não grava', function () {
    pedidoDeAgendar($this)->assertSessionHasErrors('conflito');

    expect(Agendamento::where('lista_de_espera_id', $this->espera->id)->count())->toBe(0);
});

test('com confirmação grava mesmo com conflito', function () {
    pedidoDeAgendar($this, ['forcar' => true])->assertSessionHasNoErrors();

    expect(Agendamento::where('lista_de_espera_id', $this->espera->id)->count())->toBe(1);
});

test('outra sala no mesmo dia não é conflito', function () {
    $outraSala = DB::table('sala_de_agendamentos')->where('id', '<>', $this->ocupante->sala_de_agendamento_id)->value('id');

    pedidoDeAgendar($this, ['sala_id' => $outraSala])->assertSessionHasNoErrors();

    expect(Agendamento::where('lista_de_espera_id', $this->espera->id)->count())->toBe(1);
});

test('um agendamento cancelado não conta como conflito', function () {
    $this->ocupante->update(['estado_de_agendamento_id' => ListaDeEspera::ESTADO_CANCELADO, 'estado_de_agendamento' => (string) ListaDeEspera::ESTADO_CANCELADO]);

    pedidoDeAgendar($this)->assertSessionHasNoErrors();
});

test('mover um agendamento muda a data', function () {
    $novoDia = now()->addDays(12)->format('Y-m-d');

    $this->actingAs($this->utilizador)->patch("/agendamento/{$this->ocupante->id}/mover", ['data' => $novoDia])->assertSessionHasNoErrors();

    expect($this->ocupante->fresh()->start->format('Y-m-d'))->toBe($novoDia);
});

test('mover para uma sala ocupada avisa e só move com confirmação', function () {
    $outroDia = now()->addDays(12)->format('Y-m-d');
    $movel = novoAgendamento(novaEspera(novoUtente(), $this->utilizador), $this->utilizador, $outroDia);

    $this->actingAs($this->utilizador)->patch("/agendamento/{$movel->id}/mover", ['data' => $this->dia])->assertSessionHasErrors('conflito');
    expect($movel->fresh()->start->format('Y-m-d'))->toBe($outroDia);

    $this->actingAs($this->utilizador)->patch("/agendamento/{$movel->id}/mover", ['data' => $this->dia, 'forcar' => true])->assertSessionHasNoErrors();
    expect($movel->fresh()->start->format('Y-m-d'))->toBe($this->dia);
});

test('sem permissão de alterar não se pode mover', function () {
    $leitor = utilizadorClinico(['show agendamento']);

    $this->actingAs($leitor)->patch("/agendamento/{$this->ocupante->id}/mover", ['data' => now()->addDays(12)->format('Y-m-d')])->assertForbidden();
});
