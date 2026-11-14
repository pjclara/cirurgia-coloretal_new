<?php

use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('a análise exige permissão de ver internamentos', function () {
    $this->actingAs(utilizadorClinico(['show agendamento']))->get('/dashboard')->assertForbidden();
});

test('a análise calcula os indicadores a partir dos registos', function () {
    $utilizador = utilizadorClinico();
    $vivo = DB::table('destinos')->where('id', '<>', 6)->value('id');

    foreach ([[20, 10, $vivo], [30, 10, 6]] as [$entrada, $demora, $destino]) {
        $i = novoInternamento(novoUtente(), $utilizador, ['data_de_entrada' => now()->subDays($entrada)->format('Y-m-d')]);
        $i->forceFill(['data_de_alta' => now()->subDays($entrada - $demora)->format('Y-m-d'), 'destino_id' => $destino])->save();
    }

    $this->actingAs($utilizador)->get('/dashboard')->assertInertia(fn (Assert $p) => $p
        ->component('dashboard')
        ->where('indicadores.internamentos.valor', 2)
        ->where('indicadores.altas', 2)
        ->where('indicadores.demora', 10)
        ->where('indicadores.mortalidade', 50)
        ->has('mensal', 24));
});

test('o período inválido cai no padrão de 12 meses', function () {
    $this->actingAs(utilizadorClinico())->get('/dashboard?periodo=lixo')->assertInertia(fn (Assert $p) => $p->where('periodo', '12'));
});
