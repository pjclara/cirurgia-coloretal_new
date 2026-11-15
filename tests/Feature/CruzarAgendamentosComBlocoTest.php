<?php

use App\Models\ListaDeEspera;
use App\Support\LeitorDeFolha;
use Illuminate\Support\Facades\Storage;

/** Cria uma folha .xlsx mínima: cabeçalhos em texto, processo em número, data como número de série do Excel. */
function folhaDoBloco(array $cirurgias): string
{
    $coluna = ['NUM_PROCESSO', 'DTA_INTERVENCAO', 'DES_TIPO_CIRURGIA'];
    $linha = fn (int $n, array $celulas) => '<row r="'.$n.'">'.implode('', $celulas).'</row>';
    $texto = fn (string $ref, string $v) => '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($v).'</t></is></c>';

    $linhas = [$linha(1, array_map(fn ($c, $i) => $texto(chr(65 + $i).'1', $c), $coluna, array_keys($coluna)))];
    foreach (array_values($cirurgias) as $n => [$processo, $dia]) {
        $serie = (int) round((strtotime($dia) - strtotime('1899-12-30')) / 86400);
        $r = $n + 2;
        $linhas[] = $linha($r, ['<c r="A'.$r.'"><v>'.$processo.'</v></c>', '<c r="B'.$r.'"><v>'.$serie.'</v></c>', $texto("C$r", 'PROD. PROGRAMADA BASE')]);
    }

    $ficheiro = tempnam(sys_get_temp_dir(), 'bloco').'.xlsx';
    $zip = new ZipArchive;
    $zip->open($ficheiro, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.implode('', $linhas).'</sheetData></worksheet>');
    $zip->close();

    return $ficheiro;
}

/** Utente com um agendamento (estado, dias atrás). */
function doenteComAgendamento(object $t, int $estado, int $diasAtras): array
{
    $utente = novoUtente();
    $espera = novaEspera($utente, $t->utilizador);
    $espera->update(['estado_lista_espera' => (string) $estado]);
    $agendamento = novoAgendamento($espera, $t->utilizador, now()->subDays($diasAtras)->format('Y-m-d'), [
        'estado_de_agendamento_id' => $estado,
        'estado_de_agendamento' => (string) min($estado, 4),
    ]);

    return [$utente, $espera, $agendamento];
}

beforeEach(function () {
    $this->utilizador = utilizadorClinico();
    Storage::fake('local');
});

test('o leitor lê uma folha .xlsx e converte o número de série em data', function () {
    $ficheiro = folhaDoBloco([[123456, '2026-03-04']]);

    $linhas = iterator_to_array(LeitorDeFolha::linhas($ficheiro));

    expect($linhas)->toHaveCount(1)
        ->and($linhas[0]['NUM_PROCESSO'])->toBe('123456')
        ->and($linhas[0]['DES_TIPO_CIRURGIA'])->toBe('PROD. PROGRAMADA BASE')
        ->and(LeitorDeFolha::data($linhas[0]['DTA_INTERVENCAO']))->toBe('2026-03-04');
    expect(LeitorDeFolha::data('2022-01-02 00:00:00'))->toBe('2022-01-02')
        ->and(LeitorDeFolha::data('05/06/2023'))->toBe('2023-06-05')
        ->and(LeitorDeFolha::data('abc'))->toBeNull();
});

test('sem --aplicar só mostra o que mudaria', function () {
    [$utente, $espera, $agendamento] = doenteComAgendamento($this, ListaDeEspera::ESTADO_PENDENTE, 10);
    $ficheiro = folhaDoBloco([[$utente->numero_processo, now()->subDays(10)->format('Y-m-d')]]);

    $this->artisan('agendamentos:cruzar-bloco', ['ficheiro' => $ficheiro])
        ->expectsOutputToContain('Passam a operados: 1')
        ->expectsOutputToContain('Só simulação')
        ->assertSuccessful();

    expect($agendamento->fresh()->estado())->toBe(2)
        ->and((int) $espera->fresh()->estado_lista_espera)->toBe(2);
});

test('com --aplicar passa a operado o agendamento e a lista, com tolerância de um dia', function () {
    [$exato, $esperaExata, $agExato] = doenteComAgendamento($this, ListaDeEspera::ESTADO_PENDENTE, 10);
    [$umDia, , $agUmDia] = doenteComAgendamento($this, ListaDeEspera::ESTADO_AGENDADO, 10);
    [$doisDias, , $agDoisDias] = doenteComAgendamento($this, ListaDeEspera::ESTADO_AGENDADO, 10);
    [$cancelado, , $agCancelado] = doenteComAgendamento($this, ListaDeEspera::ESTADO_CANCELADO, 10);
    [, , $agSemCirurgia] = doenteComAgendamento($this, ListaDeEspera::ESTADO_PENDENTE, 10);   // o ficheiro não o confirma

    $ficheiro = folhaDoBloco([
        [$exato->numero_processo, now()->subDays(10)->format('Y-m-d')],
        [$umDia->numero_processo, now()->subDays(9)->format('Y-m-d')],
        [$doisDias->numero_processo, now()->subDays(8)->format('Y-m-d')],
        [$cancelado->numero_processo, now()->subDays(10)->format('Y-m-d')],
    ]);

    $this->artisan('agendamentos:cruzar-bloco', ['ficheiro' => $ficheiro, '--aplicar' => true])
        ->expectsOutputToContain('Passam a operados: 2')
        ->assertSuccessful();

    expect($agExato->fresh()->estado())->toBe(5)
        ->and((int) $esperaExata->fresh()->estado_lista_espera)->toBe(5)
        ->and($agUmDia->fresh()->estado())->toBe(5)
        ->and($agDoisDias->fresh()->estado())->toBe(3)     // 2 dias: fora da tolerância
        ->and($agCancelado->fresh()->estado())->toBe(4)    // cancelados não mudam
        ->and($agSemCirurgia->fresh()->estado())->toBe(2); // não confirmado: fica igual

    $registos = collect(Storage::disk('local')->files())->filter(fn ($f) => str_starts_with($f, 'cruzamento_bloco_'));
    expect($registos)->toHaveCount(1)
        ->and(Storage::disk('local')->get($registos->first()))->toContain((string) $agExato->id);

    // Segunda execução: já está tudo operado.
    $this->artisan('agendamentos:cruzar-bloco', ['ficheiro' => $ficheiro, '--aplicar' => true])->expectsOutputToContain('Passam a operados: 0');
});

test('um ficheiro sem as colunas esperadas dá erro e não grava nada', function () {
    $ficheiro = tempnam(sys_get_temp_dir(), 'x').'.csv';
    file_put_contents($ficheiro, "A;B\n1;2\n");

    $this->artisan('agendamentos:cruzar-bloco', ['ficheiro' => $ficheiro, '--aplicar' => true])->assertFailed();
});
