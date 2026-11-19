<?php

function csvTriagem(array $linhas, array $cabecalho = ['Nome', 'NUM_PROCESSO_consultas', 'Medico', 'Data']): string
{
    $caminho = tempnam(sys_get_temp_dir(), 'triagem');
    $f = fopen($caminho, 'w');
    fputcsv($f, $cabecalho);
    foreach ($linhas as $l) {
        fputcsv($f, $l);
    }
    fclose($f);

    return $caminho;
}

test('a migration da triagem cruzada referencia os doentes do ficheiro sem duplicar nem inventar', function () {
    $migration = require database_path('migrations/2026_11_18_000004_importar_triagem_cruzada.php');
    $medico = utilizadorClinico([]);
    $medico->forceFill(['abrev' => 'ZZ'])->save();
    $novo = novoUtente();
    $repetido = novoUtente();
    $jaReferenciado = novoUtente();
    $dataInvalida = novoUtente();
    DB::table('referenciacao_centro_de_referencias')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'utente_id' => $jaReferenciado->id, 'data_de_referenciacao' => '2024-01-01',
        'origem_id' => 1, 'responsavel_id' => $medico->id, 'created_by_id' => $medico->id,
    ]);
    $ontem = now()->subDay()->format('Y-m-d');
    $maisAntiga = now()->subDays(40)->format('Y-m-d');

    $migration->caminho = csvTriagem([
        ['Um Nome', $novo->numero_processo, 'zz', $ontem],
        ['Outro Nome', $repetido->numero_processo, 'ZZ', $ontem],
        ['Outro Nome', $repetido->numero_processo, 'ZZ', $maisAntiga],          // a data mais antiga fica
        ['Ja Ref', $jaReferenciado->numero_processo, 'ZZ', $ontem],           // já referenciado: não se toca
        ['Data Má', $dataInvalida->numero_processo, 'ZZ', ''],                // sem data: por importar
        ['Desconhecido', 999999999, 'ZZ', $ontem],                            // sem utente: por importar
        ['Futuro', $novo->numero_processo, 'ZZ', now()->addDays(5)->format('Y-m-d')],
    ]);

    $migration->up();
    $migration->up(); // idempotente

    $criada = fn ($u) => DB::table('referenciacao_centro_de_referencias')->where('utente_id', $u->id)->whereNull('deleted_at')->get();
    expect($criada($novo))->toHaveCount(1)
        ->and($criada($novo)[0]->data_de_referenciacao)->toBe($ontem)
        ->and((int) $criada($novo)[0]->responsavel_id)->toBe($medico->id)
        ->and((int) $criada($novo)[0]->origem_id)->toBe(6)
        ->and($criada($repetido))->toHaveCount(1)
        ->and($criada($repetido)[0]->data_de_referenciacao)->toBe($maisAntiga)
        ->and($criada($jaReferenciado))->toHaveCount(1)
        ->and($criada($jaReferenciado)[0]->data_de_referenciacao)->toBe('2024-01-01')
        ->and($criada($dataInvalida))->toHaveCount(0)
        ->and(DB::table('triagem_importada')->count())->toBe(3)                 // inclui o que não existia na base
        ->and(DB::table('utentes')->where('numero_processo', 999999999)->exists())->toBeTrue();
});

test('sem o ficheiro a migration não faz nada', function () {
    $migration = require database_path('migrations/2026_11_18_000004_importar_triagem_cruzada.php');
    $antes = DB::table('referenciacao_centro_de_referencias')->count();
    $migration->caminho = '/nao/existe.csv';

    $migration->up();

    expect(DB::table('referenciacao_centro_de_referencias')->count())->toBe($antes);
});

test('o formato com consulta seguinte usa a data de referenciação, o clínico e a nota, e a consulta passa a ser a entrada', function () {
    $migration = require database_path('migrations/2026_11_18_000004_importar_triagem_cruzada.php');
    $clinico = utilizadorClinico([]);
    $clinico->forceFill(['name' => 'Maria Teste Silva', 'abrev' => 'QQ'])->save();
    $comEntrada = novoUtente();
    $consultaFutura = novoUtente();
    $consultaAntes = novoUtente();
    $ref = now()->subDays(10)->format('Y-m-d');
    $consulta = now()->subDays(3)->format('Y-m-d');
    $cabecalho = ['Nome', 'NUM_PROCESSO_consultas', 'Medico', 'Data_referenciacao', 'Data_consulta_seguinte', 'Tipo_consulta', 'Valencia', 'Clinico', 'Hospital', 'Dias_ate_consulta', 'Nota'];

    $migration->caminho = csvTriagem([
        ['Um Nome', $comEntrada->numero_processo, 'desconhecido', $ref, $consulta, 'Primeiras', 'CIRURGIA GERAL', 'MARIA TESTE SILVA', 'H.LEIRIA', 3, 'Trazer exames'],
        ['Futura', $consultaFutura->numero_processo, 'QQ', $ref, now()->addDays(5)->format('Y-m-d'), 'Primeiras', 'CIRURGIA GERAL', '', 'H.LEIRIA', 5, ''],   // ainda não aconteceu
        ['Antes', $consultaAntes->numero_processo, 'QQ', $ref, now()->subDays(20)->format('Y-m-d'), 'Primeiras', 'CIRURGIA GERAL', '', 'H.LEIRIA', 0, ''],     // anterior à referenciação
    ], $cabecalho);

    $migration->up();

    $r = fn ($u) => DB::table('referenciacao_centro_de_referencias')->where('utente_id', $u->id)->first();
    expect($r($comEntrada)->data_de_referenciacao)->toBe($ref)
        ->and($r($comEntrada)->data_de_entrada)->toBe($consulta)
        ->and((int) $r($comEntrada)->responsavel_id)->toBe($clinico->id)
        ->and($r($comEntrada)->comentarios)->toContain('Consulta seguinte (entrada no CR)')->toContain('Primeiras')->toContain('Cirurgia Geral')->toContain('Maria Teste Silva')->toContain('Nota: Trazer exames')
        ->and($r($consultaFutura)->data_de_entrada)->toBeNull()
        ->and($r($consultaAntes)->data_de_entrada)->toBeNull();
});

test('quem já está referenciado só recebe a data de entrada, e só se estiver vazia e a consulta for válida', function () {
    $migration = require database_path('migrations/2026_11_18_000004_importar_triagem_cruzada.php');
    $medico = utilizadorClinico([]);
    $vazio = novoUtente();
    $preenchido = novoUtente();
    $consultaAntes = novoUtente();
    $inserir = fn ($u, $entrada) => DB::table('referenciacao_centro_de_referencias')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'utente_id' => $u->id, 'data_de_referenciacao' => now()->subDays(30)->format('Y-m-d'),
        'origem_id' => 1, 'responsavel_id' => $medico->id, 'created_by_id' => $medico->id, 'data_de_entrada' => $entrada,
    ]);
    $inserir($vazio, null);
    $inserir($preenchido, now()->subDays(25)->format('Y-m-d'));
    $inserir($consultaAntes, null);
    $consulta = now()->subDays(5)->format('Y-m-d');
    $cabecalho = ['Nome', 'NUM_PROCESSO_consultas', 'Medico', 'Data_referenciacao', 'Data_consulta_seguinte'];

    $migration->caminho = csvTriagem([
        ['A', $vazio->numero_processo, 'x', now()->subDays(30)->format('Y-m-d'), $consulta],
        ['B', $preenchido->numero_processo, 'x', now()->subDays(30)->format('Y-m-d'), $consulta],
        ['C', $consultaAntes->numero_processo, 'x', now()->subDays(30)->format('Y-m-d'), now()->subDays(40)->format('Y-m-d')],   // antes da referenciação
    ], $cabecalho);
    $migration->up();

    $entrada = fn ($u) => DB::table('referenciacao_centro_de_referencias')->where('utente_id', $u->id)->value('data_de_entrada');
    expect($entrada($vazio))->toBe($consulta)
        ->and($entrada($preenchido))->toBe(now()->subDays(25)->format('Y-m-d'))
        ->and($entrada($consultaAntes))->toBeNull()
        ->and(DB::table('referenciacao_centro_de_referencias')->whereIn('utente_id', [$vazio->id, $preenchido->id, $consultaAntes->id])->count())->toBe(3)
        ->and(DB::table('triagem_importada')->where('acao', 'entrada')->count())->toBe(1);
});

test('os doentes que não existem na base são criados por completar, sem inventar dados', function () {
    $migration = require database_path('migrations/2026_11_18_000004_importar_triagem_cruzada.php');
    utilizadorClinico([]);
    $ref = now()->subDays(10)->format('Y-m-d');
    $processoNovo = 987654321;
    $processoSemData = 987654322;
    $cabecalho = ['Nome', 'NUM_PROCESSO_consultas', 'Medico', 'Data_referenciacao', 'Data_consulta_seguinte'];

    $migration->caminho = csvTriagem([
        ['  joao   da silva ', $processoNovo, 'x', $ref, ''],
        ['Joao Manuel da Silva', $processoNovo, 'x', $ref, ''],        // o nome mais completo fica
        ['Sem Data', $processoSemData, 'x', '', ''],                   // utente criado, sem referenciação
    ], $cabecalho);

    $migration->up();
    $migration->up(); // não duplica

    $novo = DB::table('utentes')->where('numero_processo', $processoNovo)->get();
    expect($novo)->toHaveCount(1)
        ->and($novo[0]->nome)->toBe('Joao Manuel da Silva')
        ->and($novo[0]->slug)->toBe('Joao Silva')
        ->and($novo[0]->data_nascimento)->toBeNull()
        ->and($novo[0]->sexo_id)->toBeNull()
        ->and($novo[0]->concelho_id)->toBeNull()
        ->and(DB::table('referenciacao_centro_de_referencias')->where('utente_id', $novo[0]->id)->count())->toBe(1)
        ->and(DB::table('utentes')->where('numero_processo', $processoSemData)->count())->toBe(1)
        ->and(DB::table('referenciacao_centro_de_referencias')->whereIn('utente_id', DB::table('utentes')->where('numero_processo', $processoSemData)->pluck('id'))->count())->toBe(0)
        ->and(DB::table('triagem_utentes_criados')->count())->toBe(2);
});

test('um utente por completar abre na lista, na ficha e na edição e só se guarda com os dados obrigatórios', function () {
    $u = utilizadorClinico();
    $id = DB::table('utentes')->insertGetId(['nome' => 'Por Completar', 'slug' => 'Por Completar', 'numero_processo' => 987000111, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($u)->get('/utentes?q=Por+Completar')->assertOk();
    $this->actingAs($u)->get("/utentes/$id")->assertOk();
    $this->actingAs($u)->get("/utentes/$id/editar")->assertOk();
    $this->actingAs($u)->put("/utentes/$id", ['nome' => 'Por Completar', 'numero_processo' => 987000111])->assertSessionHasErrors(['data_nascimento', 'sexo_id', 'concelho_id']);
});
