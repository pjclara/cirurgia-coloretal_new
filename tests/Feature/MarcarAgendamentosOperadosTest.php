<?php

use App\Models\Agendamento;
use App\Models\ListaDeEspera;

/** Carrega a migration e corre só a correspondência (sem a tabela de cópia, que obrigaria a um commit implícito). */
function marcarOperados(bool $simular = false): array
{
    $migration = require base_path('database/migrations/2026_11_16_000001_marcar_agendamentos_operados.php');

    return $migration->marcar(false, $simular);
}

/** Utente com um agendamento (estado, dias atrás) e, opcionalmente, uma cirurgia (dias atrás). */
function doenteAgendado(object $t, int $estado, int $agendadoHa, ?int $operadoHa = null): Agendamento
{
    $utente = novoUtente();
    $espera = novaEspera($utente, $t->utilizador);
    $agendamento = novoAgendamento($espera, $t->utilizador, now()->subDays($agendadoHa)->format('Y-m-d'), [
        'estado_de_agendamento_id' => $estado,
        'estado_de_agendamento' => (string) min($estado, 4),
    ]);

    if ($operadoHa !== null) {
        registarCirurgia($t, $utente, $operadoHa);
    }

    return $agendamento;
}

/** Cirurgia do utente (num internamento seu), dias atrás. */
function registarCirurgia(object $t, App\Models\Utente $utente, int $diasAtras): void
{
    $internamento = $utente->internamentos()->first() ?? novoInternamento($utente, $t->utilizador, ['data_de_entrada' => now()->subDays(60)->format('Y-m-d')]);

    $t->actingAs($t->utilizador)->post("/internamentos/{$internamento->id}/cirurgias", [
        'data_de_inicio' => now()->subDays($diasAtras)->format('Y-m-d'),
        'tipo_de_cirurgia_id' => DB::table('tipo_de_cirurgias')->value('id'),
        'tipo_de_abordagem_id' => DB::table('tipo_de_abordagems')->value('id'),
        're_intervencao_nao_programada_id' => 1,
        'intervencoes' => [DB::table('intervencaos')->value('id')],
    ])->assertSessionHasNoErrors();
}

beforeEach(function () {
    $this->utilizador = utilizadorClinico();
});

test('o agendamento passa a operado se houver cirurgia do utente no dia agendado', function () {
    $agendado = doenteAgendado($this, ListaDeEspera::ESTADO_AGENDADO, 20, 20);
    $pendente = doenteAgendado($this, ListaDeEspera::ESTADO_PENDENTE, 30, 30);
    $proposto = doenteAgendado($this, ListaDeEspera::ESTADO_PROPOSTO, 25, 25);

    $ids = marcarOperados();

    expect($ids)->toContain($agendado->id, $pendente->id, $proposto->id)
        ->and($agendado->fresh()->estado())->toBe(5)
        ->and($pendente->fresh()->estado())->toBe(5)
        ->and($proposto->fresh()->estado())->toBe(5);
});

test('aceita uma diferença de poucos dias e recusa uma maior', function () {
    $aTres = doenteAgendado($this, ListaDeEspera::ESTADO_AGENDADO, 20, 17);   // operado 3 dias depois
    $aQuatro = doenteAgendado($this, ListaDeEspera::ESTADO_AGENDADO, 20, 16); // 4 dias depois: demasiado longe

    marcarOperados();

    expect($aTres->fresh()->estado())->toBe(5)
        ->and($aQuatro->fresh()->estado())->toBe(3);
});

test('não marca cancelados, nem agendamentos sem cirurgia ou com cirurgia de outro utente', function () {
    $cancelado = doenteAgendado($this, ListaDeEspera::ESTADO_CANCELADO, 20, 20);
    $semCirurgia = doenteAgendado($this, ListaDeEspera::ESTADO_AGENDADO, 20);
    $doOutro = doenteAgendado($this, ListaDeEspera::ESTADO_AGENDADO, 20);
    registarCirurgia($this, novoUtente(), 20);

    marcarOperados();

    expect($cancelado->fresh()->estado())->toBe(4)
        ->and($semCirurgia->fresh()->estado())->toBe(3)
        ->and($doOutro->fresh()->estado())->toBe(3);
});

test('cada cirurgia conta para um só agendamento: o mais próximo da sua data', function () {
    $utente = novoUtente();
    $espera = novaEspera($utente, $this->utilizador);
    $nosDias = fn (int $ha) => novoAgendamento($espera, $this->utilizador, now()->subDays($ha)->format('Y-m-d'));
    $longe = $nosDias(22);   // 2 dias antes da cirurgia
    $perto = $nosDias(20);   // no próprio dia
    registarCirurgia($this, $utente, 20);

    marcarOperados();

    expect($perto->fresh()->estado())->toBe(5)
        ->and($longe->fresh()->estado())->toBe(3);
});

test('um agendamento já operado fica como está e só simular não grava nada', function () {
    $operado = doenteAgendado($this, 5, 20, 20);
    $agendado = doenteAgendado($this, ListaDeEspera::ESTADO_AGENDADO, 20, 20);

    $simulado = marcarOperados(simular: true);

    expect($simulado)->toBe([$agendado->id])
        ->and($agendado->fresh()->estado())->toBe(3);

    expect(marcarOperados())->toBe([$agendado->id])
        ->and($operado->fresh()->estado())->toBe(5)
        ->and($agendado->fresh()->estado())->toBe(5)
        ->and(marcarOperados())->toBe([]);   // segunda execução: nada a fazer
});
