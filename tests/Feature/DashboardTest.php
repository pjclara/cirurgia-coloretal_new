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

    $this->actingAs($utilizador)->get('/dashboard'.trimestresDesde(30))->assertInertia(fn (Assert $p) => $p
        ->component('dashboard')
        ->where('indicadores.internamentos.valor', 2)
        ->where('indicadores.altas', 2)
        ->where('indicadores.demora', 10)
        ->where('indicadores.mortalidade', 50)
        ->where('mensal', fn ($m) => count($m) >= 1 && count($m) <= 3 * 4 + 2));
});

test('a análise abre por omissão no último trimestre completo e ignora trimestres inválidos', function () {
    $utilizador = utilizadorClinico();
    novoInternamento(novoUtente(), $utilizador, ['data_de_entrada' => now()->subMonths(5)->format('Y-m-d')]);
    $anterior = now()->subMonths(3);
    $esperado = $anterior->format('Y').' T'.$anterior->quarter;

    $this->actingAs($utilizador)->get('/dashboard?de=lixo&ate=2999%20T9')
        ->assertInertia(fn (Assert $p) => $p->where('de', $esperado)->where('ate', $esperado)->has('trimestres'));
});

test('a análise do cancro retal calcula os indicadores por trimestre', function () {
    $utilizador = utilizadorClinico();
    $vivo = DB::table('destinos')->where('id', '<>', 6)->value('id');
    $data = now()->subDays(10)->format('Y-m-d');

    // Duas ressecções anteriores (uma com óbito) e uma amputação abdomino-perineal.
    foreach ([[1, $vivo], [1, 6], [24, $vivo]] as [$intervencao, $destino]) {
        $utente = novoUtente();
        DB::table('referenciacao_centro_de_referencias')->insert(['utente_id' => $utente->id, 'data_de_referenciacao' => $data, 'origem_id' => 1, 'responsavel_id' => $utilizador->id, 'created_by_id' => $utilizador->id, 'uuid' => (string) \Illuminate\Support\Str::uuid()]);
        $i = novoInternamento($utente, $utilizador, ['data_de_entrada' => $data]);
        $i->forceFill(['destino_id' => $destino])->save();
        $bloco = \App\Models\BlocoOperatorio::create(['internamento_id' => $i->id, 'data_de_inicio' => $data, 'tipo_de_cirurgia_id' => 1, 'tipo_de_abordagem_id' => 1, 're_intervencao_nao_programada_id' => 1]);
        DB::table('bloco_operatorio_intervencao')->insert(['bloco_operatorio_id' => $bloco->id, 'intervencao_id' => $intervencao]);
    }

    // Cirurgia retal de um doente nunca referenciado: não conta.
    $outro = novoInternamento(novoUtente(), $utilizador, ['data_de_entrada' => $data]);
    $b = \App\Models\BlocoOperatorio::create(['internamento_id' => $outro->id, 'data_de_inicio' => $data, 'tipo_de_cirurgia_id' => 1, 'tipo_de_abordagem_id' => 1, 're_intervencao_nao_programada_id' => 1]);
    DB::table('bloco_operatorio_intervencao')->insert(['bloco_operatorio_id' => $b->id, 'intervencao_id' => 1]);

    $this->actingAs($utilizador)->get('/analise/cancro-retal')->assertInertia(fn (Assert $p) => $p
        ->component('analise/cancro-retal')
        ->where('total', 3)
        ->where('totalConservacao', 3)
        ->where('indicadores.conservacao.taxa', 66.7)
        ->where('indicadores.mortalidade.eventos', 1)
        ->where('indicadores.mortalidade.cumpre', false));
});

test('o filtro por trimestre do cancro retal ignora valores inválidos e funciona sem cirurgias', function () {
    $this->actingAs(utilizadorClinico())->get('/analise/cancro-retal?de=lixo&ate=lixo')
        ->assertOk()->assertInertia(fn (Assert $p) => $p->where('trimestres', [])->where('total', 0));
});

test('o cancro retal abre por omissão no último trimestre completo', function () {
    $utilizador = utilizadorClinico();
    $data = now()->subMonths(5)->format('Y-m-d');
    $utente = novoUtente();
    DB::table('referenciacao_centro_de_referencias')->insert(['utente_id' => $utente->id, 'data_de_referenciacao' => $data, 'origem_id' => 1, 'responsavel_id' => $utilizador->id, 'created_by_id' => $utilizador->id, 'uuid' => (string) \Illuminate\Support\Str::uuid()]);
    $i = novoInternamento($utente, $utilizador, ['data_de_entrada' => $data]);
    $b = \App\Models\BlocoOperatorio::create(['internamento_id' => $i->id, 'data_de_inicio' => $data, 'tipo_de_cirurgia_id' => 1, 'tipo_de_abordagem_id' => 1, 're_intervencao_nao_programada_id' => 1]);
    DB::table('bloco_operatorio_intervencao')->insert(['bloco_operatorio_id' => $b->id, 'intervencao_id' => 1]);
    $anterior = now()->subMonths(3);
    $esperado = $anterior->format('Y').' T'.$anterior->quarter;

    $this->actingAs($utilizador)->get('/analise/cancro-retal')->assertInertia(fn (Assert $p) => $p->where('de', $esperado)->where('ate', $esperado));
});

test('a qualidade dos dados lista as cirurgias de doentes do CR com dados em falta', function () {
    $utilizador = utilizadorClinico();
    $data = now()->subDays(10)->format('Y-m-d');
    $utente = novoUtente();
    DB::table('referenciacao_centro_de_referencias')->insert(['utente_id' => $utente->id, 'data_de_referenciacao' => $data, 'origem_id' => 1, 'responsavel_id' => $utilizador->id, 'created_by_id' => $utilizador->id, 'uuid' => (string) \Illuminate\Support\Str::uuid()]);
    $i = novoInternamento($utente, $utilizador, ['data_de_entrada' => $data]);
    $b = \App\Models\BlocoOperatorio::create(['internamento_id' => $i->id, 'data_de_inicio' => $data, 'tipo_de_cirurgia_id' => 1, 'tipo_de_abordagem_id' => 1, 're_intervencao_nao_programada_id' => 1]);
    DB::table('bloco_operatorio_intervencao')->insert(['bloco_operatorio_id' => $b->id, 'intervencao_id' => 1]);

    $this->actingAs($utilizador)->get('/analise/cancro-retal/qualidade')->assertInertia(fn (Assert $p) => $p
        ->component('analise/qualidade-dados')
        ->where('qualidade.cirurgias', 1)
        ->where('qualidade.totais.morbilidade_alta', 1)
        ->where('qualidade.linhas.0.utente.id', $utente->id)
        ->where('qualidade.responsaveis.0.com_falta', 1)
        ->where('qualidade.linhas.0.prioridade', 'alta')
        ->where('qualidade.prioridades.alta', 1)
        ->where('qualidade.linhas.0.responsavel.id', fn ($id) => $id === (int) $i->responsavel_id));

    $this->actingAs($utilizador)->get('/analise/cancro-retal/qualidade?responsavel=999999')->assertInertia(fn (Assert $p) => $p->where('qualidade.cirurgias', 0)->where('qualidade.linhas', []));
});

test('a análise geral filtra por trimestre', function () {
    $utilizador = utilizadorClinico();
    novoInternamento(novoUtente(), $utilizador, ['data_de_entrada' => now()->subDays(200)->format('Y-m-d')]);
    novoInternamento(novoUtente(), $utilizador, ['data_de_entrada' => now()->subDays(10)->format('Y-m-d')]);
    $antigo = now()->subDays(200);
    $rotulo = $antigo->format('Y').' T'.$antigo->quarter;

    $this->actingAs($utilizador)->get('/dashboard?'.http_build_query(['de' => $rotulo, 'ate' => $rotulo]))
        ->assertInertia(fn (Assert $p) => $p->where('de', $rotulo)->where('indicadores.internamentos.valor', 1));
    $this->actingAs($utilizador)->get('/dashboard'.trimestresDesde(200))
        ->assertInertia(fn (Assert $p) => $p->where('indicadores.internamentos.valor', 2));
});
