<?php

use Illuminate\Support\Str;

function folhaDt(array $linhas): string
{
    $ficheiro = tempnam(sys_get_temp_dir(), 'dt').'.csv';
    file_put_contents($ficheiro, "NUM_PROCESSO;DTA_REALIZACAO;TIP_CONSULTA;NOME_CLINICO\n".implode("\n", array_map(fn ($l) => implode(';', $l), $linhas))."\n");

    return $ficheiro;
}

test('importa as consultas DT para a referenciação do utente, sem repetir nem inventar utentes', function () {
    DB::table('estado_consultas')->insertOrIgnore(['id' => 3, 'nome' => 'Realizada']);
    $utente = novoUtente();
    $referenciacao = DB::table('referenciacao_centro_de_referencias')->insertGetId([
        'uuid' => (string) Str::uuid(), 'responsavel_id' => ($gestor = utilizadorClinico())->id, 'created_by_id' => $gestor->id, 'updated_by_id' => $gestor->id, 'utente_id' => $utente->id, 'data_de_referenciacao' => '2025-01-01', 'origem_id' => DB::table('origem_da_referenciacaos')->value('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $semReferenciacao = novoUtente();
    $ficheiro = folhaDt([
        [$utente->numero_processo, '2025-02-03', 'Primeiras', 'ANDRE FERREIRA'],
        [$utente->numero_processo, '2025-02-03', 'Primeiras', 'ANDRE FERREIRA'], // repetida no ficheiro
        [$utente->numero_processo, '2025-03-10', 'Subsequentes', 'ANA'],
        [$semReferenciacao->numero_processo, '2025-02-03', 'Primeiras', 'ANA'],
        [99999991, '2025-02-03', 'Primeiras', 'ANA'], // sem utente
        [$utente->numero_processo, now()->addDays(5)->format('Y-m-d'), 'Subsequentes', 'ANA'], // futura
    ]);

    // Simulação: não grava.
    $this->artisan('decisoes:importar-dt', ['ficheiro' => $ficheiro])->assertSuccessful();
    expect(DB::table('reuniao_decisao_terapeuticas')->where('referenciacao_id', $referenciacao)->count())->toBe(0);

    $this->artisan('decisoes:importar-dt', ['ficheiro' => $ficheiro, '--aplicar' => true])->assertSuccessful();
    $criadas = DB::table('reuniao_decisao_terapeuticas')->where('referenciacao_id', $referenciacao)->orderBy('data_de_decisao_terapeutica')->get();
    expect($criadas)->toHaveCount(2)
        ->and(substr($criadas[0]->data_de_decisao_terapeutica, 0, 10))->toBe('2025-02-03')
        ->and($criadas[0]->estado_consulta_id)->toBe(3)
        ->and($criadas[1]->comentarios)->toContain('subsequentes');

    // Repetir não duplica.
    $this->artisan('decisoes:importar-dt', ['ficheiro' => $ficheiro, '--aplicar' => true])->assertSuccessful();
    expect(DB::table('reuniao_decisao_terapeuticas')->where('referenciacao_id', $referenciacao)->count())->toBe(2);
});

test('a referenciação mostra as decisões terapêuticas do utente', function () {
    DB::table('estado_consultas')->insertOrIgnore(['id' => 3, 'nome' => 'Realizada']);
    $gestor = utilizadorClinico(Spatie\Permission\Models\Permission::where('name', 'like', '%referenciacao%')->pluck('name')->all());
    $utente = novoUtente();
    $referenciacao = DB::table('referenciacao_centro_de_referencias')->insertGetId([
        'uuid' => (string) Str::uuid(), 'responsavel_id' => $gestor->id, 'created_by_id' => $gestor->id, 'updated_by_id' => $gestor->id,
        'utente_id' => $utente->id, 'data_de_referenciacao' => '2025-01-01', 'origem_id' => DB::table('origem_da_referenciacaos')->value('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('reuniao_decisao_terapeuticas')->insert([
        ['referenciacao_id' => $referenciacao, 'data_de_decisao_terapeutica' => '2025-02-03', 'estado_consulta_id' => 3, 'resumo' => 'Cirurgia', 'created_at' => now(), 'updated_at' => now()],
        ['referenciacao_id' => $referenciacao, 'data_de_decisao_terapeutica' => '2025-04-01', 'estado_consulta_id' => 3, 'resumo' => 'Vigilância', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($gestor)->get("/referenciacao/$referenciacao")->assertInertia(fn ($p) => $p
        ->has('modal.decisoes', 2)
        ->where('modal.decisoes.0.data', '2025-04-01')
        ->where('modal.decisoes.0.resumo', 'Vigilância'));
});
