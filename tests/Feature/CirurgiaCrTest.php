<?php

use App\Models\BlocoOperatorio;
use App\Models\Utente;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->utilizador = utilizadorClinico(permissoesComReferenciacao());

    // Linhas das tabelas de referência que o teste usa (a base de testes pode não as ter).
    $linhas = [
        'intencaos' => [1], 'estoma_de_protecaos' => [1], 'local_extracao_pecas' => [5], 'tipo_de_drenos' => [2], 'aderencias' => [1],
        'tipo_de_resseccaos' => [1], 'neoplasia_residuals' => [1], 'perdas_hematicas' => [2], 'anastemose_modos' => [1],
        'anastemose_vias' => [1], 'anastemose_sentidos' => [99], 'tipo_de_reconstrucaos' => [2], 'localizacao_anastemoses' => [3],
        'confirmacao_anastemoses' => [2],
    ];
    foreach ($linhas as $tabela => $ids) {
        foreach ($ids as $id) {
            DB::table($tabela)->insertOrIgnore(['id' => $id, 'nome' => "Opção $id"]);
        }
    }
});

function referenciarParaCirurgiaCr(Utente $utente, $utilizador, string $data): void
{
    DB::table('referenciacao_centro_de_referencias')->insert([
        'utente_id' => $utente->id, 'data_de_referenciacao' => $data, 'origem_id' => 1, 'responsavel_id' => $utilizador->id,
        'created_by_id' => $utilizador->id, 'uuid' => (string) Str::uuid(),
    ]);
}

function cirurgiaCrDados(array $extra = []): array
{
    return $extra + [
        'data_de_inicio' => now()->subDays(5)->format('Y-m-d'),
        'tipo_de_cirurgia_id' => DB::table('tipo_de_cirurgias')->value('id'),
        'tipo_de_abordagem_id' => DB::table('tipo_de_abordagems')->value('id'),
        're_intervencao_nao_programada_id' => 1,
        'intervencoes' => [1],
    ];
}

function crDados(array $extra = []): array
{
    return $extra + [
        'intencao_id' => 1, 'duracao' => 180, 'estoma_de_protecao_id' => 1, 'local_extracao_peca_id' => 5, 'tipo_de_dreno_id' => 2,
        'resseccao_multi_orgao' => '1', 'aderencia_id' => 1, 'tipo_de_resseccao_id' => 1, 'neoplasia_residual_id' => 1,
        'perdas_hematica_id' => 2, 'transfusao_intra_operatoria' => '1', 'protector_de_parede' => '2', 'complicacoes' => '1',
    ];
}

function crDescricao(array $extra = []): array
{
    return $extra + [
        'anastemose_modo_id' => 1, 'anastemose_via_id' => 1, 'anastemose_sentido_id' => 99, 'tipo_de_reconstrucao_id' => 2,
        'reforco_anastemose' => '1', 'localizacao_anastemose_id' => 3, 'confirmacao_anastemose_id' => 2, 'libertacao_angulo' => '99',
        'numero_cargas' => 3,
    ];
}

test('o formulário diz quando se aplicam os dados do CR', function () {
    $utente = novoUtente();
    referenciarParaCirurgiaCr($utente, $this->utilizador, now()->subDays(30)->format('Y-m-d'));
    $internamento = novoInternamento($utente, $this->utilizador, ['data_de_entrada' => now()->subDays(10)->format('Y-m-d')]);

    $this->actingAs($this->utilizador)->get("/internamentos/{$internamento->id}/cirurgias/nova")->assertInertia(fn (Assert $p) => $p
        ->where('cr.referenciado_desde', now()->subDays(30)->format('Y-m-d'))
        ->where('cr.resseccoes', fn ($r) => collect($r)->contains(1))
        ->has('cr.listas.intencao_id')
        ->has('cr.listas_descricao.anastemose_modo_id'));
});

test('grava os dados do CR e a descrição da intervenção numa ressecção de doente do CR', function () {
    $utente = novoUtente();
    referenciarParaCirurgiaCr($utente, $this->utilizador, now()->subDays(30)->format('Y-m-d'));
    $internamento = novoInternamento($utente, $this->utilizador, ['data_de_entrada' => now()->subDays(10)->format('Y-m-d')]);

    $this->actingAs($this->utilizador)->post("/internamentos/{$internamento->id}/cirurgias", cirurgiaCrDados([
        'cr' => crDados(),
        'descricoes' => [1 => crDescricao()],
    ]))->assertSessionHasNoErrors();

    $bloco = BlocoOperatorio::first();
    expect(DB::table('bloco_operatorio_c_r_s')->where('bloco_operatorio_id', $bloco->id)->value('duracao'))->toBe(180);
    $linha = DB::table('bloco_operatorio_intervencao')->where('bloco_operatorio_id', $bloco->id)->where('intervencao_id', 1)->value('id');
    expect(DB::table('intervencao_descricaos')->where('intervencao_id', $linha)->value('numero_cargas'))->toBe(3);

    // Editar mantém as ligações das intervenções (a descrição aponta para a linha) e atualiza os dados.
    $this->actingAs($this->utilizador)->put("/cirurgias/{$bloco->id}", cirurgiaCrDados([
        'cr' => crDados(['duracao' => 200]),
        'descricoes' => [1 => crDescricao(['numero_cargas' => 4])],
    ]))->assertSessionHasNoErrors();

    expect(DB::table('bloco_operatorio_c_r_s')->where('bloco_operatorio_id', $bloco->id)->count())->toBe(1)
        ->and(DB::table('bloco_operatorio_c_r_s')->where('bloco_operatorio_id', $bloco->id)->value('duracao'))->toBe(200)
        ->and(DB::table('bloco_operatorio_intervencao')->where('bloco_operatorio_id', $bloco->id)->where('intervencao_id', 1)->value('id'))->toBe($linha)
        ->and(DB::table('intervencao_descricaos')->where('intervencao_id', $linha)->value('numero_cargas'))->toBe(4);

    $this->actingAs($this->utilizador)->get("/cirurgias/{$bloco->id}")->assertInertia(fn (Assert $p) => $p
        ->where('cr.dados.duracao', 200)->where('cr.descricoes.1.numero_cargas', 4));
});

test('retirar a ressecção apaga a sua descrição sem falhar', function () {
    $utente = novoUtente();
    referenciarParaCirurgiaCr($utente, $this->utilizador, now()->subDays(30)->format('Y-m-d'));
    $internamento = novoInternamento($utente, $this->utilizador, ['data_de_entrada' => now()->subDays(10)->format('Y-m-d')]);
    $this->actingAs($this->utilizador)->post("/internamentos/{$internamento->id}/cirurgias", cirurgiaCrDados([
        'intervencoes' => [1, 33], 'descricoes' => [1 => crDescricao()],
    ]))->assertSessionHasNoErrors();
    $bloco = BlocoOperatorio::first();

    $this->actingAs($this->utilizador)->put("/cirurgias/{$bloco->id}", cirurgiaCrDados(['intervencoes' => [33]]))->assertSessionHasNoErrors();

    expect(DB::table('intervencao_descricaos')->count())->toBe(0)
        ->and($bloco->intervencoes()->pluck('intervencaos.id')->all())->toBe([33]);
});

test('os dados do CR exigem os campos obrigatórios e ignoram-se fora do CR ou sem ressecção', function () {
    $utente = novoUtente();
    referenciarParaCirurgiaCr($utente, $this->utilizador, now()->subDays(30)->format('Y-m-d'));
    $internamento = novoInternamento($utente, $this->utilizador, ['data_de_entrada' => now()->subDays(10)->format('Y-m-d')]);

    $this->actingAs($this->utilizador)->post("/internamentos/{$internamento->id}/cirurgias", cirurgiaCrDados(['cr' => ['duracao' => 90]]))
        ->assertSessionHasErrors(['cr.intencao_id']);
    expect(BlocoOperatorio::count())->toBe(0);

    // Sem ressecção (ileostomia): os dados do CR não se aplicam e ignoram-se.
    $this->actingAs($this->utilizador)->post("/internamentos/{$internamento->id}/cirurgias", cirurgiaCrDados(['intervencoes' => [33], 'cr' => crDados()]))
        ->assertSessionHasNoErrors();
    expect(DB::table('bloco_operatorio_c_r_s')->count())->toBe(0);

    // Doente que não é do CR.
    $outro = novoUtente();
    $i2 = novoInternamento($outro, $this->utilizador, ['data_de_entrada' => now()->subDays(10)->format('Y-m-d')]);
    $this->actingAs($this->utilizador)->post("/internamentos/{$i2->id}/cirurgias", cirurgiaCrDados(['cr' => crDados()]))->assertSessionHasNoErrors();
    expect(DB::table('bloco_operatorio_c_r_s')->count())->toBe(0);
});

test('a revisão de internamentos filtra os internamentos com cirurgia', function () {
    $utente = novoUtente();
    $comResseccao = novoInternamento($utente, $this->utilizador, ['data_de_entrada' => now()->subDays(20)->format('Y-m-d'), 'data_de_alta' => now()->subDays(10)->format('Y-m-d')]);
    $semResseccao = novoInternamento(novoUtente(), $this->utilizador, ['data_de_entrada' => now()->subDays(20)->format('Y-m-d'), 'data_de_alta' => now()->subDays(10)->format('Y-m-d')]);
    $this->actingAs($this->utilizador)->post("/internamentos/{$comResseccao->id}/cirurgias", cirurgiaCrDados())->assertSessionHasNoErrors();
    $this->actingAs($this->utilizador)->post("/internamentos/{$semResseccao->id}/cirurgias", cirurgiaCrDados(['intervencoes' => [33]]))->assertSessionHasNoErrors();
    $semCirurgia = novoInternamento(novoUtente(), $this->utilizador, ['data_de_entrada' => now()->subDays(20)->format('Y-m-d'), 'data_de_alta' => now()->subDays(10)->format('Y-m-d')]);

    $this->actingAs($this->utilizador)->get('/internamentos?cirurgia=1')->assertInertia(fn (Assert $p) => $p
        ->where('filtros.cirurgia', true)
        ->has('internamentos.data', 2)
        ->where('internamentos.data', fn ($d) => collect($d)->pluck('id')->sort()->values()->all() === collect([$comResseccao->id, $semResseccao->id])->sort()->values()->all()));
    $this->actingAs($this->utilizador)->get('/internamentos')->assertInertia(fn (Assert $p) => $p->has('internamentos.data', 3));
});

test('as listas assinalam o CR incompleto até os dados e a descrição serem preenchidos', function () {
    $utente = novoUtente();
    referenciarParaCirurgiaCr($utente, $this->utilizador, now()->subDays(30)->format('Y-m-d'));
    $internamento = novoInternamento($utente, $this->utilizador, ['data_de_entrada' => now()->subDays(10)->format('Y-m-d')]);

    $this->actingAs($this->utilizador)->post("/internamentos/{$internamento->id}/cirurgias", cirurgiaCrDados())->assertSessionHasNoErrors();
    $falta = fn () => \App\Support\Cirurgias::porInternamento([$internamento->id])->get($internamento->id)->first()['cr_em_falta'];

    expect($falta())->toBe(['cr', 'descricao']);

    $bloco = BlocoOperatorio::first();
    $this->actingAs($this->utilizador)->put("/cirurgias/{$bloco->id}", cirurgiaCrDados(['cr' => crDados()]))->assertSessionHasNoErrors();
    expect($falta())->toBe(['descricao']);

    $this->actingAs($this->utilizador)->put("/cirurgias/{$bloco->id}", cirurgiaCrDados(['cr' => crDados(), 'descricoes' => [1 => crDescricao()]]))->assertSessionHasNoErrors();
    expect($falta())->toBe([]);
});
