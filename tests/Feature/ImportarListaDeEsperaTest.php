<?php

use App\Models\Diagnostico;
use App\Models\ListaDeEspera;
use App\Models\User;
use App\Models\Utente;

function csvDeListaDeEspera(array $linhas): string
{
    $caminho = tempnam(sys_get_temp_dir(), 'le').'.csv';
    $csv = ["DTA_INSCR;UTENTE;Nome;Data de nascimento;NOME_CLINICO;DIAG PRI;DES_DIAGNOSTICO;PROC_PRINC;DESIGNACAO"];
    foreach ($linhas as $l) {
        $csv[] = implode(';', $l);
    }
    file_put_contents($caminho, implode("\n", $csv)."\n");

    return $caminho;
}

test('importa a lista de espera: cria utentes, mapeia o catálogo e salta ativos e repetidos', function () {
    $this->actingAs(utilizadorClinico());
    $medico = User::factory()->create(['name' => 'Vera Pedro']);
    $existente = novoUtente();
    $comInscricao = novoUtente();
    ListaDeEspera::create([
        'prioridade_id' => 1, 'utente_id' => $comInscricao->id, 'data_de_lista' => '2026-01-01',
        'estado_lista_espera' => ListaDeEspera::ESTADO_PENDENTE, 'responsavel_id' => $medico->id,
    ]);
    $ficheiro = csvDeListaDeEspera([
        ['2026-03-01', $existente->numero_processo, 'Nome Existente', '1950-01-01', 'VERA PEDRO', 'K642', 'Hemorroidas terceiro grau', '06BY0ZC', 'Excisao do plexo'],
        ['2026-03-02', '99000001', 'ana nova do teste', '1960-05-06 04:15:27.123', 'VERA PEDRO', 'Z452', 'Cateter', '0XXXXXX', 'Desconhecido'],
        ['2026-03-03', '99000001', 'ana nova do teste', '1960-05-06', 'VERA PEDRO', 'K642', 'Repetido', '06BY0ZC', 'x'],
        ['2026-03-04', $comInscricao->numero_processo, 'Ja Inscrito', '1950-01-01', 'VERA PEDRO', 'K642', 'x', '06BY0ZC', 'x'],
        ['2026-03-05', '99000002', 'Sem Medico', '1950-01-01', 'MEDICO DESCONHECIDO', 'K642', 'x', '06BY0ZC', 'x'],
    ]);

    // Simulação: não grava.
    $this->artisan('lista-espera:importar', ['ficheiro' => $ficheiro])->assertSuccessful();
    expect(ListaDeEspera::count())->toBe(1)->and(Utente::where('numero_processo', 99000001)->exists())->toBeFalse();

    $this->artisan('lista-espera:importar', ['ficheiro' => $ficheiro, '--aplicar' => true])->assertSuccessful();

    expect(ListaDeEspera::count())->toBe(3);
    $nova = Utente::where('numero_processo', 99000001)->first();
    expect($nova)->not->toBeNull()
        ->and($nova->data_nascimento->format('Y-m-d'))->toBe('1960-05-06')
        ->and(Utente::where('numero_processo', 99000002)->exists())->toBeFalse();

    $daNova = ListaDeEspera::where('utente_id', $nova->id)->first();
    expect((int) $daNova->estado_lista_espera)->toBe(ListaDeEspera::ESTADO_PROPOSTO)
        ->and($daNova->responsavel_id)->toBe($medico->id)
        ->and($daNova->diagnosticos()->count())->toBe(0)
        ->and($daNova->intervencoes()->count())->toBe(0)
        ->and($daNova->comentarios)->toContain('Z452')->toContain('0XXXXXX');

    $doExistente = ListaDeEspera::where('utente_id', $existente->id)->first();
    expect($doExistente->comentarios)->toContain('K642');
    if (Diagnostico::whereKey(120)->exists()) {
        expect($doExistente->diagnosticos()->pluck('diagnosticos.id')->all())->toBe([120]);
    }

    // Repetir não duplica: já têm inscrição ativa.
    $this->artisan('lista-espera:importar', ['ficheiro' => $ficheiro, '--aplicar' => true])->assertSuccessful();
    expect(ListaDeEspera::count())->toBe(3);
});
