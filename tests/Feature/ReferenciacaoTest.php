<?php

use App\Models\Referenciacao;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->gestor = utilizadorClinico(['show referenciacao', 'create referenciacao', 'update referenciacao', 'delete referenciacao']);
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

test('apagar uma referenciação exige a permissão de apagar, não basta poder editar', function () {
    referenciar($this);
    $r = Referenciacao::first();
    $editor = utilizadorClinico(['show referenciacao', 'create referenciacao', 'update referenciacao']);

    $this->actingAs($editor)->delete("/referenciacao/{$r->id}")->assertForbidden();
    expect(Referenciacao::count())->toBe(1);

    $this->actingAs($this->gestor)->delete("/referenciacao/{$r->id}")->assertRedirect('/referenciacao');
    expect(Referenciacao::count())->toBe(0);
});

test('a migration dá a permissão de apagar a quem já podia editar referenciações', function () {
    $migration = require database_path('migrations/2026_11_22_000001_permissao_apagar_referenciacoes.php');
    $perfil = \Spatie\Permission\Models\Role::create(['name' => 'perfil de teste referenciacao', 'guard_name' => 'web']);
    $perfil->givePermissionTo('update referenciacao');
    $outro = \Spatie\Permission\Models\Role::create(['name' => 'perfil de teste so ver', 'guard_name' => 'web']);
    $outro->givePermissionTo('show referenciacao');

    $migration->up();
    $migration->up(); // repetir não duplica

    expect($perfil->fresh()->hasPermissionTo('delete referenciacao'))->toBeTrue()
        ->and($outro->fresh()->hasPermissionTo('delete referenciacao'))->toBeFalse();
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

test('o formulário e a sinalização usam os diagnósticos CR que o utente já tem nos internamentos', function () {
    $cr = DB::table('diagnosticos')->where('centro_de_referencia', 1)->value('id');
    $normal = DB::table('diagnosticos')->where('centro_de_referencia', 0)->value('id');
    $internamento = novoInternamento($this->utente, $this->gestor, ['data_de_entrada' => now()->subDays(40)->format('Y-m-d')]);
    $internamento->diagnosticos()->sync([$cr, $normal]);

    // Referenciação nova: o formulário traz só o diagnóstico CR.
    $this->actingAs($this->gestor)->get("/referenciacao/utentes/{$this->utente->id}/nova")->assertInertia(fn (Assert $p) => $p
        ->where('modal.diagnosticos_do_utente', fn ($lista) => collect($lista)->pluck('id')->all() === [$cr]));

    // Sinalização rápida: o diagnóstico CR fica logo associado, com o modo «Outro».
    $origem = DB::table('origem_da_referenciacaos')->value('id');
    $this->actingAs($this->gestor)->post("/referenciacao/utentes/{$this->utente->id}/sinalizar", ['data_de_referenciacao' => $this->hoje, 'origem_id' => $origem])->assertSessionHasNoErrors();
    $referenciacao = Referenciacao::where('utente_id', $this->utente->id)->firstOrFail();
    expect($referenciacao->diagnosticos()->pluck('diagnosticos.id')->all())->toBe([$cr])
        ->and($referenciacao->diagnosticos()->first()->pivot->modo_id)->toBe(Referenciacao::MODO_OUTRO);

    // Ao editar, o que já está na referenciação deixa de ser sugerido.
    $this->actingAs($this->gestor)->get("/referenciacao/{$referenciacao->id}")->assertInertia(fn (Assert $p) => $p->where('modal.diagnosticos_do_utente', []));
});

test('os diagnósticos CR da referenciação passam para os internamentos com cirurgia do utente, sem tocar nos outros', function () {
    $cr = DB::table('diagnosticos')->where('centro_de_referencia', 1)->value('id');
    $editor = utilizadorClinico(array_merge(\Spatie\Permission\Models\Permission::where('name', 'like', '%internamento%')->pluck('name')->all(), ['show referenciacao', 'create referenciacao', 'update referenciacao']));
    $this->utilizador = $editor;
    $operado = internamentoOperado($this, novoInternamento($this->utente, $editor, ['data_de_entrada' => now()->subDays(60)->format('Y-m-d')]));
    $semCirurgia = novoInternamento($this->utente, $editor, ['data_de_entrada' => now()->subDays(30)->format('Y-m-d')]);
    $operado->diagnosticos()->sync([]);
    $semCirurgia->diagnosticos()->sync([]);

    $corpo = corpoReferenciacao($this, ['responsavel_id' => $editor->id, 'diagnosticos' => [[
        'diagnostico_id' => $cr, 'modo_id' => DB::table('diagnostico_referenciacao_modos')->value('id'), 'histologia_id' => null, 'data_diagnostico' => null,
    ]]]);
    $this->actingAs($editor)->post("/referenciacao/utentes/{$this->utente->id}", $corpo)->assertSessionHasNoErrors();

    expect($operado->diagnosticos()->pluck('diagnosticos.id')->all())->toBe([$cr])
        ->and($semCirurgia->diagnosticos()->count())->toBe(0);
});

test('referenciar sincroniza os diagnósticos CR com os internamentos qualquer que seja a permissão de quem grava', function () {
    $cr = DB::table('diagnosticos')->where('centro_de_referencia', 1)->value('id');
    $operador = utilizadorClinico();
    $this->utilizador = $operador;
    $operado = internamentoOperado($this, novoInternamento($this->utente, $operador, ['data_de_entrada' => now()->subDays(60)->format('Y-m-d')]));
    $operado->diagnosticos()->sync([]);

    $corpo = corpoReferenciacao($this, ['diagnosticos' => [[
        'diagnostico_id' => $cr, 'modo_id' => DB::table('diagnostico_referenciacao_modos')->value('id'), 'histologia_id' => null, 'data_diagnostico' => null,
    ]]]);
    $this->actingAs($this->gestor)->post("/referenciacao/utentes/{$this->utente->id}", $corpo)->assertSessionHasNoErrors();

    expect($operado->diagnosticos()->pluck('diagnosticos.id')->all())->toBe([$cr]);
});

test('uma cirurgia nova recebe sozinha os diagnósticos CR da referenciação do utente', function () {
    $cr = DB::table('diagnosticos')->where('centro_de_referencia', 1)->value('id');
    $operador = utilizadorClinico();
    $this->utilizador = $operador;
    $internamento = novoInternamento($this->utente, $operador, ['data_de_entrada' => now()->subDays(20)->format('Y-m-d')]);
    $internamento->diagnosticos()->sync([]);
    $ref = new Referenciacao(['data_de_referenciacao' => now()->subDays(40)->format('Y-m-d'), 'origem_id' => 1, 'responsavel_id' => $operador->id]);
    $ref->utente_id = $this->utente->id;
    $ref->created_by_id = $operador->id;
    $ref->save();
    $ref->diagnosticos()->attach([$cr => ['modo_id' => 99, 'histologia_id' => null, 'data_diagnostico' => null]]);
    expect($internamento->diagnosticos()->count())->toBe(0); // ainda sem cirurgia

    internamentoOperado($this, $internamento);

    expect($internamento->diagnosticos()->pluck('diagnosticos.id')->all())->toBe([$cr]);
});

test('retirar o diagnóstico CR a um internamento ao gravá-lo não o faz voltar', function () {
    $cr = DB::table('diagnosticos')->where('centro_de_referencia', 1)->value('id');
    $operador = utilizadorClinico();
    $this->utilizador = $operador;
    $internamento = internamentoOperado($this, novoInternamento($this->utente, $operador, ['data_de_entrada' => now()->subDays(20)->format('Y-m-d')]));
    $ref = new Referenciacao(['data_de_referenciacao' => now()->subDays(40)->format('Y-m-d'), 'origem_id' => 1, 'responsavel_id' => $operador->id]);
    $ref->utente_id = $this->utente->id;
    $ref->created_by_id = $operador->id;
    $ref->save();
    $ref->diagnosticos()->attach([$cr => ['modo_id' => 99, 'histologia_id' => null, 'data_diagnostico' => null]]);
    $outro = DB::table('diagnosticos')->where('centro_de_referencia', 0)->value('id');

    // Gravar o internamento só com um diagnóstico que não é CR: a escolha de quem grava respeita-se.
    $this->actingAs($operador)->put("/internamentos/{$internamento->id}", payloadInternamento($internamento, ['diagnosticos' => [$outro]]))->assertSessionHasNoErrors();

    expect($internamento->diagnosticos()->pluck('diagnosticos.id')->all())->toBe([$outro]);
});

test('a migration sincroniza os diagnósticos CR entre referenciações e internamentos com cirurgia', function () {
    $migration = require database_path('migrations/2026_11_18_000006_associar_diagnosticos_cr_da_referenciacao_aos_internamentos.php');
    $cr = DB::table('diagnosticos')->where('centro_de_referencia', 1)->value('id');
    $outroCr = DB::table('diagnosticos')->where('centro_de_referencia', 1)->where('id', '<>', $cr)->value('id');
    $operador = utilizadorClinico();
    $this->utilizador = $operador;
    $semDiagnostico = internamentoOperado($this, novoInternamento($this->utente, $operador, ['data_de_entrada' => now()->subDays(90)->format('Y-m-d')]));
    $jaTem = internamentoOperado($this, novoInternamento($this->utente, $operador, ['data_de_entrada' => now()->subDays(60)->format('Y-m-d')]));
    $jaTem->diagnosticos()->sync([$outroCr]);
    $semCirurgia = novoInternamento($this->utente, $operador, ['data_de_entrada' => now()->subDays(30)->format('Y-m-d')]);
    $semDiagnostico->diagnosticos()->sync([]);
    $semCirurgia->diagnosticos()->sync([]);
    $ref = new Referenciacao(['data_de_referenciacao' => now()->subDays(100)->format('Y-m-d'), 'origem_id' => 1, 'responsavel_id' => $operador->id]);
    $ref->utente_id = $this->utente->id;
    $ref->created_by_id = $operador->id;
    $ref->save();
    $ref->diagnosticos()->attach([$cr => ['modo_id' => 99, 'histologia_id' => null, 'data_diagnostico' => null]]);

    $migration->up();
    $migration->up(); // idempotente

    // A união dos diagnósticos CR do utente: a referenciação ganha o do outro internamento e o internamento sem diagnóstico fica com ambos.
    $todos = collect([$cr, $outroCr])->sort()->values()->all();
    expect($semDiagnostico->diagnosticos()->pluck('diagnosticos.id')->sort()->values()->all())->toBe($todos)
        ->and($ref->diagnosticos()->pluck('diagnosticos.id')->sort()->values()->all())->toBe($todos)
        ->and($jaTem->diagnosticos()->pluck('diagnosticos.id')->all())->toBe([$outroCr])
        ->and($semCirurgia->diagnosticos()->count())->toBe(0);
});

test('o formulário da referenciação só oferece diagnósticos do centro de referência e «Outro Colorectal»', function () {
    $cr = DB::table('diagnosticos')->where('centro_de_referencia', 1)->pluck('id')->map(fn ($i) => (int) $i)->all();
    $normal = (int) DB::table('diagnosticos')->where('centro_de_referencia', 0)->where('id', '<>', \App\Models\Diagnostico::OUTRO)->value('id');
    $modo = DB::table('diagnostico_referenciacao_modos')->value('id');
    $linha = fn (int $id) => ['diagnostico_id' => $id, 'modo_id' => $modo, 'histologia_id' => null, 'data_diagnostico' => null];

    $this->actingAs($this->gestor)->get("/referenciacao/utentes/{$this->utente->id}/nova")->assertInertia(fn (Assert $p) => $p
        ->where('modal.dominios.diagnosticos', function ($lista) use ($cr, $normal) {
            $ids = collect($lista)->pluck('id')->all();

            return count(array_diff($cr, $ids)) === 0 && in_array(\App\Models\Diagnostico::OUTRO, $ids, true) && ! in_array($normal, $ids, true)
                && count($ids) === count($cr) + 1;
        }));

    // Um diagnóstico que não é do centro de referência não se aceita; «Outro Colorectal» sim.
    $this->actingAs($this->gestor)->post("/referenciacao/utentes/{$this->utente->id}", corpoReferenciacao($this, ['diagnosticos' => [$linha($normal)]]))
        ->assertSessionHasErrors('diagnosticos.0.diagnostico_id');
    $this->actingAs($this->gestor)->post("/referenciacao/utentes/{$this->utente->id}", corpoReferenciacao($this, ['diagnosticos' => [$linha(\App\Models\Diagnostico::OUTRO)]]))
        ->assertSessionHasNoErrors();
});

test('o formulário da referenciação traz os internamentos do utente, só para quem os pode ver', function () {
    $cr = DB::table('diagnosticos')->where('centro_de_referencia', 1)->value('id');
    $operador = utilizadorClinico();
    $this->utilizador = $operador;
    $antigo = novoInternamento($this->utente, $operador, ['data_de_entrada' => now()->subDays(90)->format('Y-m-d'), 'motivo_internamento' => 'Motivo antigo']);
    $recente = internamentoOperado($this, novoInternamento($this->utente, $operador, ['data_de_entrada' => now()->subDays(20)->format('Y-m-d')]));
    $recente->diagnosticos()->sync([$cr]);

    // Com permissão para ver internamentos: do mais recente para o mais antigo, com diagnósticos e cirurgias.
    $leitor = utilizadorClinico(['show internamento', 'show referenciacao', 'create referenciacao']);
    $this->actingAs($leitor)->get("/referenciacao/utentes/{$this->utente->id}/nova")->assertInertia(fn (Assert $p) => $p
        ->where('modal.internamentos.0.id', $recente->id)
        ->where('modal.internamentos.1.id', $antigo->id)
        ->where('modal.internamentos.0.diagnosticos.0.cr', true)
        ->where('modal.internamentos.0.cirurgias', fn ($c) => count($c) === 1));

    // Só com permissão de referenciação, os internamentos não se mostram.
    $this->actingAs($this->gestor)->get("/referenciacao/utentes/{$this->utente->id}/nova")->assertInertia(fn (Assert $p) => $p->where('modal.internamentos', null));
});

test('o internamento abre numa modal por cima da referenciação e, ao gravar, volta a ela', function () {
    $operador = utilizadorClinico(['show internamento', 'update internamento', 'show referenciacao', 'create referenciacao', 'update referenciacao']);
    $internamento = novoInternamento($this->utente, $operador, ['data_de_entrada' => now()->subDays(20)->format('Y-m-d')]);
    $retorno = "/referenciacao/utentes/{$this->utente->id}/nova";

    $this->actingAs($operador)->get("/referenciacao/internamentos/{$internamento->id}?retorno=".urlencode($retorno))->assertInertia(fn (Assert $p) => $p
        ->component('referenciacao/index')
        ->where('modalInternamento.internamento.id', $internamento->id)
        ->where('modalInternamento.origem', 'referenciacao')
        ->where('modalInternamento.fechar_url', $retorno)
        ->where('modal', null));

    // Endereços de fora da referenciação não se aceitam como retorno.
    $this->actingAs($operador)->get("/referenciacao/internamentos/{$internamento->id}?retorno=".urlencode('https://exemplo.pt'))->assertInertia(fn (Assert $p) => $p->where('modalInternamento.fechar_url', '/referenciacao'));

    // Gravar volta à referenciação que o abriu.
    $this->actingAs($operador)->put("/internamentos/{$internamento->id}", payloadInternamento($internamento, ['origem' => 'referenciacao', 'retorno' => $retorno]))->assertRedirect($retorno);
    $this->actingAs($operador)->put("/internamentos/{$internamento->id}", payloadInternamento($internamento, ['origem' => 'referenciacao', 'retorno' => 'https://exemplo.pt']))->assertRedirect('/referenciacao');

    // Sem permissão para ver internamentos, não abre.
    $this->actingAs($this->gestor)->get("/referenciacao/internamentos/{$internamento->id}")->assertForbidden();
});
