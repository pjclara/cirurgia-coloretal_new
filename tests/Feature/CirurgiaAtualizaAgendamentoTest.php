<?php

use App\Models\Agendamento;
use App\Models\ListaDeEspera;
use App\Models\Utente;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->utilizador = utilizadorClinico();
});

/** Utente em lista de espera com um agendamento (estado, dias atrás). */
function utenteAgendado(object $t, int $estado, int $diasAtras): array
{
    $utente = novoUtente();
    $espera = novaEspera($utente, $t->utilizador);
    $agendamento = novoAgendamento($espera, $t->utilizador, now()->subDays($diasAtras)->format('Y-m-d'), [
        'estado_de_agendamento_id' => $estado,
        'estado_de_agendamento' => (string) min($estado, 4),
    ]);
    $espera->update(['estado_lista_espera' => (string) $estado]);

    return [$utente, $espera, $agendamento];
}

/** Regista uma cirurgia pela app, no internamento do utente (criado se for preciso). */
function operarUtente(object $t, Utente $utente, int $diasAtras)
{
    $internamento = $utente->internamentos()->first() ?? novoInternamento($utente, $t->utilizador, ['data_de_entrada' => now()->subDays(60)->format('Y-m-d')]);

    return $t->actingAs($t->utilizador)->post("/internamentos/{$internamento->id}/cirurgias", [
        'data_de_inicio' => now()->subDays($diasAtras)->format('Y-m-d'),
        'tipo_de_cirurgia_id' => DB::table('tipo_de_cirurgias')->value('id'),
        'tipo_de_abordagem_id' => DB::table('tipo_de_abordagems')->value('id'),
        're_intervencao_nao_programada_id' => 1,
        'intervencoes' => [DB::table('intervencaos')->value('id')],
    ]);
}

test('registar a cirurgia passa o agendamento e a lista de espera a operados', function () {
    [$utente, $espera, $agendamento] = utenteAgendado($this, ListaDeEspera::ESTADO_AGENDADO, 10);

    operarUtente($this, $utente, 10)->assertSessionHasNoErrors()
        ->assertSessionHas('sucesso', fn ($m) => str_contains($m, 'marcado como operado'));

    expect($agendamento->fresh()->estado())->toBe(5)
        ->and((int) $espera->fresh()->estado_lista_espera)->toBe(5);
});

test('só conta uma cirurgia perto do dia agendado, do mesmo utente, e nunca um agendamento cancelado', function () {
    [$longe, $esperaLonge, $agLonge] = utenteAgendado($this, ListaDeEspera::ESTADO_AGENDADO, 20);   // cirurgia 10 dias depois
    [$cancelado, $esperaCancelada, $agCancelado] = utenteAgendado($this, ListaDeEspera::ESTADO_CANCELADO, 10);
    [$outro, $esperaOutro, $agOutro] = utenteAgendado($this, ListaDeEspera::ESTADO_AGENDADO, 10);

    operarUtente($this, $longe, 10);
    operarUtente($this, $cancelado, 10);
    operarUtente($this, novoUtente(), 10);   // cirurgia de outro utente

    expect($agLonge->fresh()->estado())->toBe(3)
        ->and($agCancelado->fresh()->estado())->toBe(4)
        ->and((int) $esperaCancelada->fresh()->estado_lista_espera)->toBe(4)
        ->and($agOutro->fresh()->estado())->toBe(3);
});

test('registar o agendamento de quem já foi operado nesse dia deixa-o como operado', function () {
    $utente = novoUtente();
    operarUtente($this, $utente, 5);
    $espera = novaEspera($utente, $this->utilizador);

    $this->actingAs($this->utilizador)->post("/agendamento/lista-de-espera/{$espera->id}/agendar", [
        'data' => now()->subDays(5)->format('Y-m-d'),
        'periodo_id' => DB::table('periodo_de_agendamentos')->value('id'),
        'local_id' => DB::table('local_de_agendamentos')->value('id'),
        'sala_id' => DB::table('sala_de_agendamentos')->value('id'),
        'tipo_id' => DB::table('tipo_de_agendamentos')->value('id'),
        'responsavel_id' => $this->utilizador->id,
        'estado' => ListaDeEspera::ESTADO_AGENDADO,
        'reserva_sangue' => 1,
    ])->assertSessionHasNoErrors()->assertSessionHas('sucesso', fn ($m) => str_contains($m, 'ficou como operado'));

    $agendamento = Agendamento::where('lista_de_espera_id', $espera->id)->firstOrFail();
    expect($agendamento->estado())->toBe(5)
        ->and((int) $espera->fresh()->estado_lista_espera)->toBe(5);
});

test('o calendário traz o estado operado de cada agendamento, para o riscar', function () {
    [$utente, , $agendamento] = utenteAgendado($this, ListaDeEspera::ESTADO_AGENDADO, 2);
    operarUtente($this, $utente, 2);

    $this->actingAs($this->utilizador)->get('/agendamento?vista=semana&semana='.now()->subDays(2)->format('Y-m-d'))->assertInertia(fn (Assert $p) => $p
        ->where('dias', fn ($dias) => collect($dias)->flatMap(fn ($d) => $d['agendamentos'])->firstWhere('id', $agendamento->id)['estado'] === 5));
});

test('apagar a cirurgia não repõe o agendamento', function () {
    [$utente, , $agendamento] = utenteAgendado($this, ListaDeEspera::ESTADO_AGENDADO, 10);
    operarUtente($this, $utente, 10);
    $bloco = App\Models\BlocoOperatorio::whereHas('internamento', fn ($q) => $q->where('utente_id', $utente->id))->firstOrFail();

    $this->actingAs($this->utilizador)->delete("/cirurgias/{$bloco->id}")->assertRedirect();

    expect($agendamento->fresh()->estado())->toBe(5);
});
