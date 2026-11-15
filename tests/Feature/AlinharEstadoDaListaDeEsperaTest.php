<?php

use App\Models\ListaDeEspera;

/** Carrega a migration e corre só o alinhamento (sem criar a tabela de cópia, que obrigaria a um commit implícito). */
function alinharEstados(): int
{
    $migration = require base_path('database/migrations/2026_11_16_000001_alinhar_estado_da_lista_de_espera_com_o_agendamento.php');

    return $migration->alinhar(false);
}

/** Inscrição com o estado dado e um agendamento (estado do agendamento, dia). */
function inscricaoComAgendamentos(int $estadoDaLista, array $agendamentos): ListaDeEspera
{
    $responsavel = utilizadorClinico();
    $espera = novaEspera(novoUtente(), $responsavel);
    $espera->update(['estado_lista_espera' => (string) $estadoDaLista]);
    foreach ($agendamentos as [$estado, $dias]) {
        novoAgendamento($espera, $responsavel, now()->addDays($dias)->format('Y-m-d'), [
            'estado_de_agendamento_id' => $estado,
            'estado_de_agendamento' => (string) min($estado, 4),
        ]);
    }

    return $espera;
}

test('a lista de espera passa a ter o estado do agendamento', function () {
    $agendado = inscricaoComAgendamentos(ListaDeEspera::ESTADO_PENDENTE, [[3, 10]]);
    $cancelado = inscricaoComAgendamentos(ListaDeEspera::ESTADO_AGENDADO, [[4, 10]]);
    $proposto = inscricaoComAgendamentos(ListaDeEspera::ESTADO_AGENDADO, [[1, 10]]);
    $pendente = inscricaoComAgendamentos(ListaDeEspera::ESTADO_PROPOSTO, [[2, 10]]);
    $operado = inscricaoComAgendamentos(ListaDeEspera::ESTADO_PENDENTE, [[5, -5]]);

    expect(alinharEstados())->toBeGreaterThanOrEqual(5);

    expect((int) $agendado->fresh()->estado_lista_espera)->toBe(3)
        ->and((int) $cancelado->fresh()->estado_lista_espera)->toBe(4)
        ->and((int) $proposto->fresh()->estado_lista_espera)->toBe(1)
        ->and((int) $pendente->fresh()->estado_lista_espera)->toBe(2)
        ->and((int) $operado->fresh()->estado_lista_espera)->toBe(3);   // operado: já não está por agendar
});

test('com vários agendamentos conta o mais recente que não esteja cancelado', function () {
    // Cancelou e reagendou: fica agendada, não cancelada.
    $reagendada = inscricaoComAgendamentos(ListaDeEspera::ESTADO_CANCELADO, [[4, 5], [3, 20]]);
    // O agendado é mais antigo e o cancelado mais recente: continua a valer o agendado.
    $cancelouDepois = inscricaoComAgendamentos(ListaDeEspera::ESTADO_PENDENTE, [[3, 5], [4, 20]]);
    // Só cancelados: cancelada.
    $soCancelados = inscricaoComAgendamentos(ListaDeEspera::ESTADO_PENDENTE, [[4, 5], [4, 20]]);

    alinharEstados();

    expect((int) $reagendada->fresh()->estado_lista_espera)->toBe(3)
        ->and((int) $cancelouDepois->fresh()->estado_lista_espera)->toBe(3)
        ->and((int) $soCancelados->fresh()->estado_lista_espera)->toBe(4);
});

test('não mexe em inscrições sem agendamento, apagadas, nem em agendamentos apagados', function () {
    $semAgendamento = novaEspera(novoUtente(), utilizadorClinico());
    $semAgendamento->update(['estado_lista_espera' => (string) ListaDeEspera::ESTADO_PROPOSTO]);

    $apagada = inscricaoComAgendamentos(ListaDeEspera::ESTADO_PENDENTE, [[3, 10]]);
    $apagada->delete();

    $agendamentoApagado = inscricaoComAgendamentos(ListaDeEspera::ESTADO_PENDENTE, [[3, 10]]);
    $agendamentoApagado->agendamentos->each->delete();

    alinharEstados();

    expect((int) $semAgendamento->fresh()->estado_lista_espera)->toBe(1)
        ->and((int) ListaDeEspera::withTrashed()->find($apagada->id)->estado_lista_espera)->toBe(2)
        ->and((int) $agendamentoApagado->fresh()->estado_lista_espera)->toBe(2);
});

test('um registo antigo, só com o estado do agendamento no campo antigo, também conta', function () {
    $antigo = inscricaoComAgendamentos(ListaDeEspera::ESTADO_PENDENTE, [[3, 10]]);
    $antigo->agendamentos()->update(['estado_de_agendamento_id' => null, 'estado_de_agendamento' => '4']);

    alinharEstados();

    expect((int) $antigo->fresh()->estado_lista_espera)->toBe(4);
});

test('uma segunda execução já não altera nada', function () {
    inscricaoComAgendamentos(ListaDeEspera::ESTADO_PENDENTE, [[3, 10]]);

    alinharEstados();

    expect(alinharEstados())->toBe(0);
});
