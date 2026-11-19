<?php

use App\Models\Referenciacao;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->gestor = utilizadorClinico(['show referenciacao', 'create referenciacao', 'update referenciacao']);
    $this->utente = novoUtente();
    $this->hoje = now()->format('Y-m-d');
});

/** Corpo válido de uma referenciação; `$extra` sobrepõe campos. */
function corpoReferenciacao(object $t, array $extra = []): array
{
    return $extra + [
        'data_de_referenciacao' => now()->subDays(20)->format('Y-m-d'),
        'origem_id' => DB::table('origem_da_referenciacaos')->value('id'),
        'responsavel_id' => $t->gestor->id,
        'diagnosticos' => [[
            'diagnostico_id' => DB::table('diagnosticos')->value('id'),
            'modo_id' => DB::table('diagnostico_referenciacao_modos')->value('id'),
            'histologia_id' => DB::table('histologias')->value('id'),
            'data_diagnostico' => now()->subDays(25)->format('Y-m-d'),
        ]],
    ];
}

function referenciar(object $t, array $extra = [])
{
    return $t->actingAs($t->gestor)->post("/referenciacao/utentes/{$t->utente->id}", corpoReferenciacao($t, $extra));
}

test('referenciar um utente grava a referenciação com os diagnósticos', function () {
    referenciar($this)->assertSessionHasNoErrors()->assertRedirect('/referenciacao');

    $r = Referenciacao::where('utente_id', $this->utente->id)->first();
    expect($r)->not->toBeNull()
        ->and($r->uuid)->toHaveLength(36)
        ->and($r->diagnosticos)->toHaveCount(1)
        ->and($r->situacao())->toBe('referenciado');
});

test('as datas têm de ser coerentes', function () {
    referenciar($this, ['data_de_diagnostico' => now()->subDays(5)->format('Y-m-d')])->assertSessionHasErrors('data_de_diagnostico');
    referenciar($this, ['data_de_entrada' => now()->subDays(30)->format('Y-m-d')])->assertSessionHasErrors('data_de_entrada');
    referenciar($this, ['data_de_saida' => $this->hoje, 'destino_id' => 1])->assertSessionHasErrors('data_de_entrada');
    referenciar($this, ['data_de_entrada' => now()->subDays(10)->format('Y-m-d'), 'data_de_saida' => now()->subDays(12)->format('Y-m-d'), 'destino_id' => 1])->assertSessionHasErrors('data_de_saida');
    referenciar($this, ['data_de_referenciacao' => now()->addDay()->format('Y-m-d')])->assertSessionHasErrors('data_de_referenciacao');

    expect(Referenciacao::count())->toBe(0);
});

test('a saída exige o destino e a entrada', function () {
    $entrada = now()->subDays(10)->format('Y-m-d');

    referenciar($this, ['data_de_entrada' => $entrada, 'data_de_saida' => $this->hoje])->assertSessionHasErrors('destino_id');
    referenciar($this, ['data_de_entrada' => $entrada, 'destino_id' => 1])->assertSessionHasErrors('data_de_saida');
    referenciar($this, ['data_de_entrada' => $entrada, 'data_de_saida' => $this->hoje, 'destino_id' => 1])->assertSessionHasNoErrors();

    expect(Referenciacao::first()->situacao())->toBe('saida');
});

test('cada diagnóstico exige o modo e não se repete', function () {
    $linha = corpoReferenciacao($this)['diagnosticos'][0];

    referenciar($this, ['diagnosticos' => [array_merge($linha, ['modo_id' => null])]])->assertSessionHasErrors('diagnosticos.0.modo_id');
    referenciar($this, ['diagnosticos' => [$linha, $linha]])->assertSessionHasErrors('diagnosticos.0.diagnostico_id');
});

test('editar substitui os diagnósticos', function () {
    referenciar($this);
    $r = Referenciacao::first();

    $this->actingAs($this->gestor)->put("/referenciacao/{$r->id}", corpoReferenciacao($this, ['diagnosticos' => [], 'data_de_entrada' => $this->hoje]))->assertSessionHasNoErrors();

    $r->refresh();
    expect($r->diagnosticos()->count())->toBe(0)->and($r->situacao())->toBe('no_centro');
});

test('quem só pode consultar não consegue referenciar nem alterar', function () {
    $leitor = utilizadorClinico(['show referenciacao']);
    referenciar($this);
    $r = Referenciacao::first();

    $this->actingAs($leitor)->post("/referenciacao/utentes/{$this->utente->id}", corpoReferenciacao($this))->assertForbidden();
    $this->actingAs($leitor)->put("/referenciacao/{$r->id}", corpoReferenciacao($this))->assertForbidden();
    $this->actingAs($leitor)->get('/referenciacao')->assertOk();
});

test('a lista filtra por situação e a ficha do utente mostra a referenciação', function () {
    referenciar($this);
    $outro = novoUtente();
    $this->actingAs($this->gestor)->post("/referenciacao/utentes/{$outro->id}", corpoReferenciacao($this, ['data_de_entrada' => now()->subDays(5)->format('Y-m-d')]));

    $this->actingAs($this->gestor)->get('/referenciacao?situacao=no_centro')->assertInertia(fn (Assert $p) => $p
        ->component('referenciacao/index')
        ->where('lista.total', 1)
        ->where('lista.data.0.utente.id', $outro->id));

    // A ficha exige também ver internamentos; sem ver referenciações, a secção vem vazia.
    $completo = utilizadorClinico(['show internamento', 'show referenciacao']);
    $semReferenciacao = utilizadorClinico(['show internamento']);
    $this->actingAs($completo)->get("/utentes/{$this->utente->id}")->assertInertia(fn (Assert $p) => $p->has('referenciacoes', 1));
    $this->actingAs($semReferenciacao)->get("/utentes/{$this->utente->id}")->assertInertia(fn (Assert $p) => $p->has('referenciacoes', 0));
});

test('apagar faz soft delete e a modal de edição abre sobre a lista', function () {
    referenciar($this);
    $r = Referenciacao::first();

    $this->actingAs($this->gestor)->get("/referenciacao/{$r->id}")->assertInertia(fn (Assert $p) => $p
        ->component('referenciacao/index')->where('modal.referenciacao.id', $r->id)->where('modal.utente.id', $this->utente->id));

    $this->actingAs($this->gestor)->delete("/referenciacao/{$r->id}")->assertRedirect('/referenciacao');
    expect(Referenciacao::count())->toBe(0)->and(Referenciacao::withTrashed()->count())->toBe(1);
});

test('sinalizar um utente só pede a origem e a data e fica em nome de quem sinalizou', function () {
    $origem = DB::table('origem_da_referenciacaos')->value('id');

    $this->actingAs($this->gestor)->post("/referenciacao/utentes/{$this->utente->id}/sinalizar", ['data_de_referenciacao' => $this->hoje, 'origem_id' => $origem])
        ->assertSessionHasNoErrors();

    $r = Referenciacao::where('utente_id', $this->utente->id)->first();
    expect($r->responsavel_id)->toBe($this->gestor->id)->and($r->situacao())->toBe('referenciado');
});

test('a sinalização valida a origem e a data e não duplica', function () {
    $origem = DB::table('origem_da_referenciacaos')->value('id');
    $url = "/referenciacao/utentes/{$this->utente->id}/sinalizar";

    $this->actingAs($this->gestor)->post($url, ['data_de_referenciacao' => $this->hoje])->assertSessionHasErrors('origem_id');
    $this->actingAs($this->gestor)->post($url, ['data_de_referenciacao' => now()->addDay()->format('Y-m-d'), 'origem_id' => $origem])->assertSessionHasErrors('data_de_referenciacao');

    $this->actingAs($this->gestor)->post($url, ['data_de_referenciacao' => $this->hoje, 'origem_id' => $origem]);
    $this->actingAs($this->gestor)->post($url, ['data_de_referenciacao' => $this->hoje, 'origem_id' => $origem])->assertSessionHas('erro');

    expect(Referenciacao::where('utente_id', $this->utente->id)->count())->toBe(1);
});

test('sem permissão de referenciar não se sinaliza', function () {
    $leitor = utilizadorClinico(['show referenciacao']);

    $this->actingAs($leitor)->post("/referenciacao/utentes/{$this->utente->id}/sinalizar", ['data_de_referenciacao' => $this->hoje, 'origem_id' => 1])->assertForbidden();
});

test('o utente sinalizado aparece marcado no quadro e nos internamentos, até ter saída', function () {
    $internamento = novoInternamento($this->utente, $this->gestor);
    $vizinho = novoInternamento(novoUtente(), $this->gestor, ['cama' => '2']);
    $leitor = utilizadorClinico();
    referenciar($this, ['data_de_entrada' => now()->subDays(5)->format('Y-m-d')]);

    $this->actingAs($leitor)->get('/quadro')->assertInertia(fn (Assert $p) => $p
        ->where('internados', fn ($lista) => collect($lista)->firstWhere('id', $internamento->id)['centro_referencia'] === true
            && collect($lista)->firstWhere('id', $vizinho->id)['centro_referencia'] === false));

    // Com saída do centro deixa de estar sinalizado.
    Referenciacao::first()->update(['data_de_saida' => $this->hoje, 'destino_id' => 1]);
    $this->actingAs($leitor)->get('/quadro')->assertInertia(fn (Assert $p) => $p
        ->where('internados', fn ($lista) => collect($lista)->firstWhere('id', $internamento->id)['centro_referencia'] === false));

    // A lista de internamentos só tem altas: volta a sinalizar e dá alta.
    Referenciacao::first()->update(['data_de_saida' => null, 'destino_id' => null]);
    $internamento->update(['data_de_alta' => $this->hoje]);
    $this->actingAs($leitor)->get('/internamentos')->assertInertia(fn (Assert $p) => $p
        ->where('internamentos.data', fn ($lista) => collect($lista)->firstWhere('id', $internamento->id)['centro_referencia'] === true));
});

test('a lista de referenciações filtra por ano e trimestre da data de referenciação', function () {
    $dia = now()->subDays(20);
    $antigo = now()->subYears(2);
    referenciar($this, ['data_de_referenciacao' => $dia->format('Y-m-d')])->assertRedirect();
    $outro = novoUtente();
    $this->actingAs($this->gestor)->post("/referenciacao/utentes/{$outro->id}", corpoReferenciacao($this, ['data_de_referenciacao' => $antigo->format('Y-m-d')]))->assertRedirect();

    $ver = fn (string $consulta) => $this->actingAs($this->gestor)->get("/referenciacao$consulta");
    $ver('')->assertInertia(fn (Assert $p) => $p->where('lista.total', 2)->where('filtros.ano', '')->has('anos', 2));
    $ver('?ano='.$dia->year)->assertInertia(fn (Assert $p) => $p->where('lista.total', 1)->where('filtros.ano', (string) $dia->year));
    $ver('?ano='.$dia->year.'&trimestre='.$dia->quarter)->assertInertia(fn (Assert $p) => $p->where('lista.total', 1)->where('filtros.trimestre', (string) $dia->quarter));
    $outroTrimestre = $dia->quarter === 1 ? 2 : 1;
    $ver('?ano='.$dia->year.'&trimestre='.$outroTrimestre)->assertInertia(fn (Assert $p) => $p->where('lista.total', 0));
    $ver('?ano='.$antigo->year)->assertInertia(fn (Assert $p) => $p->where('lista.total', 1));
    // Inválidos ignoram-se; o trimestre sem ano também.
    $ver('?ano=lixo&trimestre=9')->assertInertia(fn (Assert $p) => $p->where('lista.total', 2)->where('filtros.ano', ''));
    $ver('?trimestre=1')->assertInertia(fn (Assert $p) => $p->where('lista.total', 2)->where('filtros.trimestre', ''));
});

test('a data da consulta seguinte grava-se, edita-se e aparece na lista', function () {
    $proxima = now()->addDays(14)->format('Y-m-d');
    referenciar($this, ['data_consulta_seguinte' => $proxima])->assertSessionHasNoErrors();

    $this->actingAs($this->gestor)->get('/referenciacao')->assertInertia(fn (Assert $p) => $p->where('lista.data.0.data_consulta_seguinte', $proxima));
    $this->actingAs($this->gestor)->post("/referenciacao/utentes/".novoUtente()->id, corpoReferenciacao($this, ['data_consulta_seguinte' => 'lixo']))->assertSessionHasErrors('data_consulta_seguinte');
});
