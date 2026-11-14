<?php

use Inertia\Testing\AssertableInertia as Assert;

test('a lista para imprimir traz os internados, filtrável por equipa', function () {
    $utilizador = utilizadorClinico();
    $utilizador->forceFill(['equipa' => 'A'])->save();
    $outro = utilizadorClinico();
    $outro->forceFill(['equipa' => 'B'])->save();

    $daEquipaA = novoInternamento(novoUtente(), $utilizador, ['cama' => '3']);
    novoInternamento(novoUtente(), $outro, ['cama' => '4']);
    $comAlta = novoInternamento(novoUtente(), $utilizador, ['cama' => '5', 'data_de_alta' => now()->format('Y-m-d')]);

    $this->actingAs($utilizador)->get('/quadro/imprimir')->assertInertia(fn (Assert $p) => $p
        ->component('quadro/imprimir')
        ->has('internados', 2)); // sem o que já teve alta

    $this->actingAs($utilizador)->get('/quadro/imprimir?equipa=A')->assertInertia(fn (Assert $p) => $p
        ->has('internados', 1)
        ->where('internados.0.id', $daEquipaA->id)
        ->where('equipa', 'A'));
});

test('sem permissão de ver internamentos não se imprime a lista', function () {
    $this->actingAs(utilizadorClinico(['show agendamento']))->get('/quadro/imprimir')->assertForbidden();
});
