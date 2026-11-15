<?php

use Inertia\Testing\AssertableInertia as Assert;

test('registar internamento abre a modal sobre o quadro, sem sair dele', function () {
    $utente = novoUtente();

    $this->actingAs(utilizadorClinico())->get('/quadro/novo?utente='.$utente->id)->assertInertia(fn (Assert $p) => $p
        ->component('quadro/index')
        ->where('modal.internamento', null)
        ->where('modal.origem', 'quadro')
        ->where('modal.fechar_url', '/quadro')
        ->where('modal.utente.id', $utente->id));
});

test('quadro/novo exige a permissão de criar', function () {
    $this->actingAs(utilizadorClinico(['show internamento']))->get('/quadro/novo')->assertForbidden();
});

test('gravar a partir do quadro volta ao quadro', function () {
    $utilizador = utilizadorClinico();
    $modelo = novoInternamento(novoUtente(), $utilizador);
    $payload = payloadInternamento($modelo, ['origem' => 'quadro']);
    $modelo->forceDelete();

    $this->actingAs($utilizador)->post('/internamentos', $payload)->assertRedirect(route('quadro'));
});

test('a lista de internamentos só mostra os que têm alta', function () {
    $utilizador = utilizadorClinico();
    $internado = novoInternamento(novoUtente(), $utilizador);
    $comAlta = novoInternamento(novoUtente(), $utilizador, ['data_de_alta' => now()->format('Y-m-d')]);

    $this->actingAs($utilizador)->get('/internamentos')->assertInertia(fn (Assert $p) => $p
        ->where('internamentos.data', fn ($lista) => collect($lista)->pluck('id')->contains($comAlta->id)
            && ! collect($lista)->pluck('id')->contains($internado->id)));
});

test('a pesquisa de utente encontra pelo início do n.º de processo e põe os nomes que começam pelo termo primeiro', function () {
    $a = novoUtente(['nome' => 'Zé Maria Silva', 'numero_processo' => 7712345]);
    $b = novoUtente(['nome' => 'Maria Antunes', 'numero_processo' => 7799999]);
    $utilizador = utilizadorClinico();

    $porNumero = $this->actingAs($utilizador)->getJson('/utentes/pesquisa?q=77123')->json();
    expect(collect($porNumero)->pluck('id')->all())->toBe([$a->id]);

    $porNome = $this->actingAs($utilizador)->getJson('/utentes/pesquisa?q=maria')->json();
    expect(array_search($b->id, array_column($porNome, 'id')))->toBeLessThan(array_search($a->id, array_column($porNome, 'id')));
});

test('criar utente abre a modal sobre o quadro e, ao gravar, segue para o registo do internamento', function () {
    $utilizador = utilizadorClinico();

    $this->actingAs($utilizador)->get('/quadro/novo-utente?processo=555123')->assertInertia(fn (Assert $p) => $p
        ->component('quadro/index')
        ->where('modalUtente.origem', 'quadro')
        ->where('modalUtente.processoSugerido', '555123')
        ->where('modalUtente.fechar_url', '/quadro'));

    $this->actingAs($utilizador)->post('/utentes', [
        'nome' => 'Utente Da Modal',
        'numero_processo' => '555123',
        'data_nascimento' => '1960-05-05',
        'sexo_id' => 1,
        'concelho_id' => DB::table('concelhos')->value('id'),
        'origem' => 'quadro',
    ])->assertRedirect('/quadro/novo?utente='.App\Models\Utente::where('numero_processo', 555123)->value('id'));
});

test('as cirurgias abrem em modal sobre o quadro e gravar volta ao quadro', function () {
    $utilizador = utilizadorClinico();
    $internamento = novoInternamento(novoUtente(), $utilizador);
    $payload = [
        'data_de_inicio' => now()->format('Y-m-d'),
        'tipo_de_cirurgia_id' => DB::table('tipo_de_cirurgias')->value('id'),
        'tipo_de_abordagem_id' => DB::table('tipo_de_abordagems')->value('id'),
        're_intervencao_nao_programada_id' => DB::table('re_intervencao_nao_programadas')->value('id'),
        'intervencoes' => [DB::table('intervencaos')->value('id')],
        'origem' => 'quadro',
    ];

    $this->actingAs($utilizador)->get("/quadro/{$internamento->id}/cirurgias/nova")->assertInertia(fn (Assert $p) => $p
        ->component('quadro/index')
        ->where('modalCirurgia.cirurgia', null)
        ->where('modalCirurgia.origem', 'quadro')
        ->where('modalCirurgia.internamento.id', $internamento->id));

    $this->actingAs($utilizador)->post("/internamentos/{$internamento->id}/cirurgias", $payload)->assertRedirect(route('quadro'));

    $bloco = App\Models\BlocoOperatorio::where('internamento_id', $internamento->id)->firstOrFail();
    $this->actingAs($utilizador)->get("/quadro/cirurgias/{$bloco->id}")->assertInertia(fn (Assert $p) => $p
        ->where('modalCirurgia.cirurgia.id', $bloco->id));
    $this->actingAs($utilizador)->get('/quadro')->assertInertia(fn (Assert $p) => $p
        ->where('internados.0.cirurgias.0.id', $bloco->id));

    $this->actingAs($utilizador)->put("/cirurgias/{$bloco->id}", $payload)->assertRedirect(route('quadro'));
});

test('a reintervenção é sim ou não', function () {
    $utilizador = utilizadorClinico();
    $internamento = novoInternamento(novoUtente(), $utilizador);
    $payload = [
        'data_de_inicio' => now()->format('Y-m-d'),
        'tipo_de_cirurgia_id' => DB::table('tipo_de_cirurgias')->value('id'),
        'tipo_de_abordagem_id' => DB::table('tipo_de_abordagems')->value('id'),
        're_intervencao_nao_programada_id' => 2,
        'intervencoes' => [DB::table('intervencaos')->value('id')],
    ];

    $this->actingAs($utilizador)->post("/internamentos/{$internamento->id}/cirurgias", $payload)->assertSessionHasNoErrors();

    $bloco = App\Models\BlocoOperatorio::where('internamento_id', $internamento->id)->firstOrFail();
    expect((int) $bloco->re_intervencao_nao_programada_id)->toBe(2);
});
