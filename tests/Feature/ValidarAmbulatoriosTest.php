<?php

use App\Models\Agendamento;
use App\Models\ListaDeEspera;
use Inertia\Testing\AssertableInertia as Assert;

function ambulatorio(object $t, int $diasAtras, array $extra = []): Agendamento
{
    $espera = novaEspera(novoUtente(), $t->utilizador);

    return novoAgendamento($espera, $t->utilizador, now()->subDays($diasAtras)->format('Y-m-d'), $extra + ['local_de_agendamento_id' => 2]);
}

beforeEach(function () {
    $this->utilizador = utilizadorClinico();
});

test('a agenda lista só os ambulatórios já passados que não estão operados nem cancelados', function () {
    $porValidar = ambulatorio($this, 5);
    ambulatorio($this, -5); // futuro
    ambulatorio($this, 5, ['estado_de_agendamento_id' => ListaDeEspera::ESTADO_OPERADO]);
    ambulatorio($this, 5, ['estado_de_agendamento_id' => ListaDeEspera::ESTADO_CANCELADO, 'estado_de_agendamento' => '4']);
    ambulatorio($this, 5, ['local_de_agendamento_id' => 1]); // bloco central

    $this->actingAs($this->utilizador)->get('/agendamento')->assertInertia(fn (Assert $p) => $p
        ->has('ambulatoriosPorValidar', 1)
        ->where('ambulatoriosPorValidar.0.id', $porValidar->id));
});

test('validar marca os escolhidos como operados e ignora os que não estão por validar', function () {
    $a = ambulatorio($this, 5);
    $b = ambulatorio($this, 6);
    $futuro = ambulatorio($this, -3);
    $bloco = ambulatorio($this, 5, ['local_de_agendamento_id' => 1]);

    $this->actingAs($this->utilizador)
        ->post('/agendamento/ambulatorio/operados', ['ids' => [$a->id, $futuro->id, $bloco->id]])
        ->assertSessionHasNoErrors();

    expect($a->fresh()->estado())->toBe(ListaDeEspera::ESTADO_OPERADO)
        ->and($a->fresh()->espera->estado_lista_espera)->toBe((string) ListaDeEspera::ESTADO_OPERADO)
        ->and($b->fresh()->estado())->toBe(ListaDeEspera::ESTADO_AGENDADO)
        ->and($futuro->fresh()->estado())->toBe(ListaDeEspera::ESTADO_AGENDADO)
        ->and($bloco->fresh()->estado())->toBe(ListaDeEspera::ESTADO_AGENDADO);
});

test('quem não pode alterar agendamentos não valida ambulatórios', function () {
    $a = ambulatorio($this, 5);
    $leitor = utilizadorClinico(['show agendamento']);

    $this->actingAs($leitor)->post('/agendamento/ambulatorio/operados', ['ids' => [$a->id]])->assertForbidden();
    $this->actingAs($leitor)->get('/agendamento')->assertInertia(fn (Assert $p) => $p->where('ambulatoriosPorValidar', []));
});

test('criar um utente a partir do agendamento segue para a inscrição em lista de espera, não para o internamento', function () {
    $this->actingAs($this->utilizador)->get('/agendamento/novo-utente')
        ->assertInertia(fn (Assert $p) => $p->where('modalUtente.origem', 'agendamento')->where('modalUtente.fechar_url', '/agendamento'));

    $this->actingAs($this->utilizador)->post('/utentes', [
        'nome' => 'Utente Novo Agenda', 'numero_processo' => 8123456, 'data_nascimento' => '1960-05-05',
        'sexo_id' => 1, 'concelho_id' => DB::table('concelhos')->value('id'), 'origem' => 'agendamento',
    ])->assertRedirect(route('agendamento.espera.create', App\Models\Utente::where('numero_processo', 8123456)->first()));
});

test('criar um utente a partir da referenciação abre em modal sobre a lista de referenciação', function () {
    $gestor = utilizadorClinico(Spatie\Permission\Models\Permission::where('name', 'like', '%internamento%')->orWhere('name', 'like', '%referenciacao%')->pluck('name')->all());

    $this->actingAs($gestor)->get('/referenciacao/novo-utente?processo=555')
        ->assertInertia(fn (Assert $p) => $p->component('referenciacao/index')->where('modalUtente.origem', 'referenciacao')->where('modalUtente.fechar_url', '/referenciacao')->where('modalUtente.processoSugerido', '555'));
});
