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

test('a lista de espera filtra pelo ano da inscrição', function () {
    $u = utilizadorClinico();
    foreach (['2025-06-01', '2026-02-01', '2026-03-01'] as $data) {
        ListaDeEspera::create([
            'prioridade_id' => 1, 'utente_id' => novoUtente()->id, 'data_de_lista' => $data,
            'estado_lista_espera' => ListaDeEspera::ESTADO_PROPOSTO, 'responsavel_id' => $u->id,
        ]);
    }

    $this->actingAs($u)->get('/agendamento/lista-de-espera?ano=2026')->assertInertia(fn ($p) => $p
        ->where('lista.total', 2)->where('filtros.ano', 2026)->where('anos', [2026, 2025]));
    $this->actingAs($u)->get('/agendamento/lista-de-espera?ano=1999')->assertInertia(fn ($p) => $p
        ->where('lista.total', 3)->where('filtros.ano', null));
});

test('ao inscrever, mostra as inscrições do utente que ainda estão por operar', function () {
    $u = utilizadorClinico();
    $utente = novoUtente();
    $ativa = ListaDeEspera::create(['prioridade_id' => 1, 'utente_id' => $utente->id, 'data_de_lista' => '2026-02-01', 'estado_lista_espera' => ListaDeEspera::ESTADO_PENDENTE, 'responsavel_id' => $u->id]);
    ListaDeEspera::create(['prioridade_id' => 1, 'utente_id' => $utente->id, 'data_de_lista' => '2025-02-01', 'estado_lista_espera' => ListaDeEspera::ESTADO_OPERADO, 'responsavel_id' => $u->id]);

    $this->actingAs($u)->get("/agendamento/utentes/{$utente->id}/inscrever")->assertInertia(fn ($p) => $p
        ->has('modal.inscricoes_ativas', 1)->where('modal.inscricoes_ativas.0.id', $ativa->id)->where('modal.inscricoes_ativas.0.pode_agendar', true));
    $this->actingAs($u)->get('/agendamento/utentes/'.novoUtente()->id.'/inscrever')->assertInertia(fn ($p) => $p
        ->has('modal.inscricoes_ativas', 0));
});

test('a lista de espera filtra pelo responsável', function () {
    $u = utilizadorClinico();
    $outro = App\Models\User::factory()->create();
    foreach ([$u, $u, $outro] as $m) {
        ListaDeEspera::create(['prioridade_id' => 1, 'utente_id' => novoUtente()->id, 'data_de_lista' => '2026-02-01', 'estado_lista_espera' => 1, 'responsavel_id' => $m->id]);
    }

    $this->actingAs($u)->get('/agendamento/lista-de-espera?responsavel='.$outro->id)->assertInertia(fn ($p) => $p
        ->where('lista.total', 1)->where('filtros.responsavel', $outro->id)->has('responsaveis', 2));
    $this->actingAs($u)->get('/agendamento/lista-de-espera?responsavel=999999')->assertInertia(fn ($p) => $p
        ->where('lista.total', 3)->where('filtros.responsavel', null));
});

test('datas de nascimento antigas em número de série do Excel também se leem', function () {
    expect(App\Support\LeitorDeFolha::data('14102.5', 1))->toBe('1938-08-10')
        ->and(App\Support\LeitorDeFolha::data('14102.5'))->toBeNull();
});
