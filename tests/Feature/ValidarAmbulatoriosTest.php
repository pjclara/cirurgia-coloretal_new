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
    ])->assertRedirect(route('agendamento.espera.create', ['utente' => App\Models\Utente::where('numero_processo', 8123456)->first(), 'origem' => 'agenda', 'retorno' => '/agendamento']));
});

test('criar um utente a partir da referenciação abre em modal sobre a lista de referenciação', function () {
    $gestor = utilizadorClinico(Spatie\Permission\Models\Permission::where('name', 'like', '%internamento%')->orWhere('name', 'like', '%referenciacao%')->pluck('name')->all());

    $this->actingAs($gestor)->get('/referenciacao/novo-utente?processo=555')
        ->assertInertia(fn (Assert $p) => $p->component('referenciacao/index')->where('modalUtente.origem', 'referenciacao')->where('modalUtente.fechar_url', '/referenciacao')->where('modalUtente.processoSugerido', '555'));
});

test('a inscrição aberta a partir da agenda fica por cima da agenda e volta a ela ao gravar', function () {
    $utente = novoUtente();
    $retorno = '/agendamento?semana=2026-11-01&vista=semana';

    // Por cima da agenda, com os filtros da agenda e o endereço de volta.
    $this->actingAs($this->utilizador)->get('/agendamento/utentes/'.$utente->id.'/inscrever?origem=agenda&retorno='.urlencode($retorno))
        ->assertInertia(fn (Assert $p) => $p
            ->component('agendamento/agenda')
            ->where('vista', 'semana')
            ->where('modal.tipo', 'inscricao')
            ->where('modal.origem', 'agenda')
            ->where('modal.fechar_url', $retorno));

    // Sem origem continua a abrir sobre a lista de espera.
    $this->actingAs($this->utilizador)->get('/agendamento/utentes/'.$utente->id.'/inscrever')
        ->assertInertia(fn (Assert $p) => $p->component('agendamento/lista-de-espera'));

    $intervencao = DB::table('intervencaos')->value('id');
    $dados = ['prioridade_id' => 1, 'data_de_lista' => now()->format('Y-m-d'), 'responsavel_id' => $this->utilizador->id, 'intervencoes' => [$intervencao]];
    $this->actingAs($this->utilizador)->post('/agendamento/utentes/'.$utente->id.'/inscrever', $dados + ['origem' => 'agenda', 'retorno' => $retorno])
        ->assertRedirect($retorno);

    // Um endereço de fora da secção não serve de retorno.
    $outro = novoUtente();
    $this->actingAs($this->utilizador)->post('/agendamento/utentes/'.$outro->id.'/inscrever', $dados + ['origem' => 'agenda', 'retorno' => 'https://exemplo.pt'])
        ->assertRedirect('/agendamento');
});

test('quem já tem um agendamento pendente sai da lista ao lado da agenda e não se volta a agendar', function () {
    $livre = novaEspera(novoUtente(), $this->utilizador);
    $comPendente = novaEspera(novoUtente(), $this->utilizador);
    $agendamento = novoAgendamento($comPendente, $this->utilizador, now()->addDays(5)->format('Y-m-d'), [
        'estado_de_agendamento_id' => ListaDeEspera::ESTADO_PENDENTE, 'estado_de_agendamento' => (string) ListaDeEspera::ESTADO_PENDENTE,
    ]);
    $comAgendado = novaEspera(novoUtente(), $this->utilizador); // agendado (confirmado): continua a sair pelo estado da lista
    $comAgendado->update(['estado_lista_espera' => ListaDeEspera::ESTADO_AGENDADO]);
    novoAgendamento($comAgendado, $this->utilizador, now()->addDays(6)->format('Y-m-d'));

    $this->actingAs($this->utilizador)->get('/agendamento')->assertInertia(fn (Assert $p) => $p
        ->where('espera.total', 1)
        ->where('espera.itens.0.id', $livre->id));

    // Abrir «agendar» leva ao agendamento pendente que já existe.
    $this->actingAs($this->utilizador)->get("/agendamento/lista-de-espera/{$comPendente->id}/agendar")
        ->assertRedirect(route('agendamento.edit', $agendamento));

    // E gravar um novo agendamento é recusado.
    $dados = ['data' => now()->addDays(9)->format('Y-m-d'), 'periodo_id' => 1, 'local_id' => 1, 'tipo_id' => 1, 'sala_id' => 1, 'reserva_sangue' => false, 'estado' => 3, 'responsavel_id' => $this->utilizador->id];
    $this->actingAs($this->utilizador)->post("/agendamento/lista-de-espera/{$comPendente->id}/agendar", $dados)->assertSessionHasErrors('conflito');
    expect($comPendente->agendamentos()->count())->toBe(1);

    // Cancelado o pendente, volta à lista.
    $agendamento->forceFill(['estado_de_agendamento_id' => ListaDeEspera::ESTADO_CANCELADO, 'estado_de_agendamento' => '4'])->save();
    $this->actingAs($this->utilizador)->get('/agendamento')->assertInertia(fn (Assert $p) => $p->where('espera.total', 2));
});
