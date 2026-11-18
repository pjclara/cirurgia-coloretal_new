<?php

function csvTriagem(array $linhas): string
{
    $caminho = tempnam(sys_get_temp_dir(), 'triagem');
    $f = fopen($caminho, 'w');
    fputcsv($f, ['Nome', 'NUM_PROCESSO_consultas', 'Medico', 'Data']);
    foreach ($linhas as $l) {
        fputcsv($f, $l);
    }
    fclose($f);

    return $caminho;
}

test('a migration da triagem cruzada referencia os doentes do ficheiro sem duplicar nem inventar', function () {
    $migration = require database_path('migrations/2026_11_18_000002_importar_triagem_cruzada.php');
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
        ->and(DB::table('triagem_importada')->count())->toBe(2);
});

test('sem o ficheiro a migration não faz nada', function () {
    $migration = require database_path('migrations/2026_11_18_000002_importar_triagem_cruzada.php');
    $antes = DB::table('referenciacao_centro_de_referencias')->count();
    $migration->caminho = '/nao/existe.csv';

    $migration->up();

    expect(DB::table('referenciacao_centro_de_referencias')->count())->toBe($antes);
});
