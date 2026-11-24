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

test('cada ambulatório é operado ou cancelado com o seu motivo, de uma só vez', function () {
    $operado = ambulatorio($this, 5);
    $porTempo = ambulatorio($this, 5);
    $clinico = ambulatorio($this, 6);
    $porDecidir = ambulatorio($this, 6);
    $futuro = ambulatorio($this, -3);

    // Cancelar sem motivo, ou com um motivo que não existe, é recusado e nada muda.
    $this->actingAs($this->utilizador)->post('/agendamento/ambulatorio/decisoes', ['cancelados' => [['id' => $porTempo->id]]])->assertSessionHasErrors('cancelados.0.motivo');
    $this->actingAs($this->utilizador)->post('/agendamento/ambulatorio/decisoes', ['cancelados' => [['id' => $porTempo->id, 'motivo' => 7]]])->assertSessionHasErrors('cancelados.0.motivo');
    expect($porTempo->fresh()->estado())->toBe(ListaDeEspera::ESTADO_AGENDADO);

    $this->actingAs($this->utilizador)->post('/agendamento/ambulatorio/decisoes', [
        'operados' => [$operado->id, $futuro->id],
        'cancelados' => [['id' => $porTempo->id, 'motivo' => 1], ['id' => $clinico->id, 'motivo' => 4]],
    ])->assertSessionHasNoErrors();

    expect($operado->fresh()->estado())->toBe(ListaDeEspera::ESTADO_OPERADO)
        ->and($porTempo->fresh()->estado())->toBe(ListaDeEspera::ESTADO_CANCELADO)
        ->and((string) $porTempo->fresh()->motivo_cancelar_agendamento)->toBe('1')
        ->and((string) $clinico->fresh()->motivo_cancelar_agendamento)->toBe('4')
        ->and((int) $clinico->fresh()->espera->estado_lista_espera)->toBe(ListaDeEspera::ESTADO_CANCELADO)
        ->and($clinico->fresh()->espera->podeAgendar())->toBeTrue()
        ->and($porDecidir->fresh()->estado())->toBe(ListaDeEspera::ESTADO_AGENDADO)
        ->and($futuro->fresh()->estado())->toBe(ListaDeEspera::ESTADO_AGENDADO);

    // Só fica por validar quem não foi decidido (e o futuro nunca entra).
    $this->actingAs($this->utilizador)->get('/agendamento')->assertInertia(fn (Assert $p) => $p->has('ambulatoriosPorValidar', 1)->where('ambulatoriosPorValidar.0.id', $porDecidir->id));
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
    $dados = ['data' => now()->addDays(9)->format('Y-m-d'), 'periodo_id' => 1, 'local_id' => 1, 'tipo_id' => 1, 'sala_id' => 1, 'reserva_sangue' => 1, 'estado' => 3, 'responsavel_id' => $this->utilizador->id];
    $this->actingAs($this->utilizador)->post("/agendamento/lista-de-espera/{$comPendente->id}/agendar", $dados)->assertSessionHasErrors('conflito');
    expect($comPendente->agendamentos()->count())->toBe(1);

    // Cancelado o pendente, volta à lista.
    $agendamento->forceFill(['estado_de_agendamento_id' => ListaDeEspera::ESTADO_CANCELADO, 'estado_de_agendamento' => '4'])->save();
    $this->actingAs($this->utilizador)->get('/agendamento')->assertInertia(fn (Assert $p) => $p->where('espera.total', 2));
});

test('o mapa para imprimir só traz os dias com cirurgias', function () {
    $segunda = now()->startOfWeek();
    novoAgendamento(novaEspera(novoUtente(), $this->utilizador), $this->utilizador, $segunda->copy()->addDays(2)->format('Y-m-d'));
    novoAgendamento(novaEspera(novoUtente(), $this->utilizador), $this->utilizador, $segunda->copy()->addDays(2)->format('Y-m-d'));

    $this->actingAs($this->utilizador)->get('/agendamento/mapa?vista=semana&data='.$segunda->format('Y-m-d'))->assertInertia(fn (Assert $p) => $p
        ->has('dias', 1)
        ->where('dias.0.data', $segunda->copy()->addDays(2)->format('Y-m-d'))
        ->has('dias.0.agendamentos', 2)
        ->missing('estados'));

    // Um dia sem cirurgias não traz nenhum dia.
    $this->actingAs($this->utilizador)->get('/agendamento/mapa?vista=dia&data='.$segunda->copy()->addDays(4)->format('Y-m-d'))->assertInertia(fn (Assert $p) => $p->has('dias', 0));
});

test('o período já não se escolhe: o agendamento novo fica de manhã e o que já existe mantém o seu', function () {
    $espera = novaEspera(novoUtente(), $this->utilizador);
    $dados = [
        'data' => now()->addDays(9)->format('Y-m-d'), 'local_id' => 1, 'tipo_id' => 1, 'sala_id' => 1, 'reserva_sangue' => 1,
        'estado' => 3, 'responsavel_id' => $this->utilizador->id,
    ];

    $this->actingAs($this->utilizador)->post("/agendamento/lista-de-espera/{$espera->id}/agendar", $dados)->assertSessionHasNoErrors();
    $agendamento = $espera->agendamentos()->first();
    expect((int) $agendamento->periodo_de_agendamento_id)->toBe(1);

    // Um agendamento antigo, da tarde, continua da tarde ao ser alterado sem período.
    $agendamento->forceFill(['periodo_de_agendamento_id' => 2])->save();
    $this->actingAs($this->utilizador)->put("/agendamento/{$agendamento->id}", $dados + ['comentarios' => 'alterado'])->assertSessionHasNoErrors();
    expect((int) $agendamento->fresh()->periodo_de_agendamento_id)->toBe(2);
});

test('só se agenda de novo o que foi cancelado e o formulário só oferece agendado, operado e cancelado', function () {
    $dados = ['data' => now()->addDays(9)->format('Y-m-d'), 'local_id' => 1, 'tipo_id' => 1, 'sala_id' => 1, 'reserva_sangue' => 1, 'responsavel_id' => $this->utilizador->id];

    // Com um agendamento (agendado, pendente ou operado) não se agenda de novo.
    foreach ([ListaDeEspera::ESTADO_AGENDADO, ListaDeEspera::ESTADO_PENDENTE] as $estado) {
        $espera = novaEspera(novoUtente(), $this->utilizador);
        novoAgendamento($espera, $this->utilizador, now()->addDays(5)->format('Y-m-d'), ['estado_de_agendamento_id' => $estado, 'estado_de_agendamento' => (string) $estado]);
        expect($espera->fresh()->podeAgendar())->toBeFalse();
        $this->actingAs($this->utilizador)->post("/agendamento/lista-de-espera/{$espera->id}/agendar", $dados + ['estado' => 3])->assertSessionHasErrors('conflito');
    }

    // Cancelado o agendamento (pelo formulário, que passa também a inscrição a cancelada), agenda-se de novo.
    $espera = novaEspera(novoUtente(), $this->utilizador);
    $agendamento = novoAgendamento($espera, $this->utilizador, now()->addDays(5)->format('Y-m-d'));
    $this->actingAs($this->utilizador)->put("/agendamento/{$agendamento->id}", $dados + ['estado' => ListaDeEspera::ESTADO_CANCELADO, 'motivo_cancelamento' => 1])->assertSessionHasNoErrors();
    expect((int) $espera->fresh()->estado_lista_espera)->toBe(ListaDeEspera::ESTADO_CANCELADO)->and($espera->fresh()->podeAgendar())->toBeTrue();
    $this->actingAs($this->utilizador)->get('/agendamento')->assertInertia(fn (Assert $p) => $p->where('espera.itens', fn ($l) => collect($l)->pluck('id')->contains($espera->id)));
    $this->actingAs($this->utilizador)->post("/agendamento/lista-de-espera/{$espera->id}/agendar", $dados + ['estado' => 3])->assertSessionHasNoErrors();
    expect($espera->agendamentos()->count())->toBe(2);

    // Uma inscrição cancelada sem nunca ter tido agendamento não se agenda.
    $cancelada = novaEspera(novoUtente(), $this->utilizador);
    $cancelada->update(['estado_lista_espera' => ListaDeEspera::ESTADO_CANCELADO]);
    expect($cancelada->fresh()->podeAgendar())->toBeFalse();

    // O estado só se escolhe entre agendado, operado e cancelado; «pendente» ou «proposto» são recusados.
    $outra = novaEspera(novoUtente(), $this->utilizador);
    $this->actingAs($this->utilizador)->post("/agendamento/lista-de-espera/{$outra->id}/agendar", $dados + ['estado' => ListaDeEspera::ESTADO_PENDENTE])->assertSessionHasErrors('estado');
});

test('doente operado: o agendamento e a inscrição só se consultam, exceto para um administrador', function () {
    $espera = novaEspera(novoUtente(), $this->utilizador);
    $agendamento = novoAgendamento($espera, $this->utilizador, now()->subDays(3)->format('Y-m-d'), [
        'estado_de_agendamento_id' => ListaDeEspera::ESTADO_OPERADO, 'estado_de_agendamento' => (string) ListaDeEspera::ESTADO_AGENDADO,
    ]);
    $espera->update(['estado_lista_espera' => ListaDeEspera::ESTADO_OPERADO]);
    $dados = ['data' => now()->subDays(3)->format('Y-m-d'), 'local_id' => 1, 'tipo_id' => 1, 'sala_id' => 1, 'reserva_sangue' => 1, 'estado' => 5, 'responsavel_id' => $this->utilizador->id, 'comentarios' => 'x'];
    $inscricao = ['prioridade_id' => 1, 'data_de_lista' => now()->format('Y-m-d'), 'responsavel_id' => $this->utilizador->id, 'intervencoes' => [DB::table('intervencaos')->value('id')]];

    // Os formulários chegam só para consulta.
    $this->actingAs($this->utilizador)->get("/agendamento/{$agendamento->id}")->assertInertia(fn (Assert $p) => $p->where('modal.somente_consulta', true));
    $this->actingAs($this->utilizador)->get("/agendamento/lista-de-espera/{$espera->id}")->assertInertia(fn (Assert $p) => $p->where('modal.espera.somente_consulta', true));

    // E o servidor recusa qualquer alteração.
    $this->actingAs($this->utilizador)->put("/agendamento/{$agendamento->id}", $dados)->assertForbidden();
    $this->actingAs($this->utilizador)->patch("/agendamento/{$agendamento->id}/mover", ['data' => now()->format('Y-m-d')])->assertForbidden();
    $this->actingAs($this->utilizador)->delete("/agendamento/{$agendamento->id}")->assertForbidden();
    $this->actingAs($this->utilizador)->put("/agendamento/lista-de-espera/{$espera->id}", $inscricao)->assertForbidden();
    $this->actingAs($this->utilizador)->post("/agendamento/lista-de-espera/{$espera->id}/cancelar")->assertForbidden();
    expect($agendamento->fresh()->comentarios)->not->toBe('x');

    // Um administrador altera.
    $admin = utilizadorClinico();
    $admin->assignRole('admin');
    $this->actingAs($admin)->get("/agendamento/{$agendamento->id}")->assertInertia(fn (Assert $p) => $p->where('modal.somente_consulta', false));
    $this->actingAs($admin)->put("/agendamento/{$agendamento->id}", $dados)->assertSessionHasNoErrors();
    expect($agendamento->fresh()->comentarios)->toBe('x');
});

test('o sangue tem três opções: não é necessário, apenas classificação e reserva de sangue', function () {
    $dados = ['data' => now()->addDays(9)->format('Y-m-d'), 'local_id' => 1, 'tipo_id' => 1, 'sala_id' => 1, 'estado' => 3, 'responsavel_id' => $this->utilizador->id];

    foreach ([1, 2, 3] as $codigo) {
        $espera = novaEspera(novoUtente(), $this->utilizador);
        $this->actingAs($this->utilizador)->post("/agendamento/lista-de-espera/{$espera->id}/agendar", ['data' => now()->addDays(9 + $codigo)->format('Y-m-d')] + $dados + ['reserva_sangue' => $codigo])->assertSessionHasNoErrors(); // dias diferentes: a mesma sala e dia avisa de conflito
        $agendamento = $espera->agendamentos()->first();
        expect((string) $agendamento->fresh()->reserva_sangue)->toBe((string) $codigo);
        $this->actingAs($this->utilizador)->get("/agendamento/{$agendamento->id}")->assertInertia(fn (Assert $p) => $p->where('modal.agendamento.reserva_sangue', (string) $codigo));
    }

    // Fora das três opções (ou em falta) é recusado.
    $outra = novaEspera(novoUtente(), $this->utilizador);
    $this->actingAs($this->utilizador)->post("/agendamento/lista-de-espera/{$outra->id}/agendar", $dados + ['reserva_sangue' => 4])->assertSessionHasErrors('reserva_sangue');
    $this->actingAs($this->utilizador)->post("/agendamento/lista-de-espera/{$outra->id}/agendar", $dados)->assertSessionHasErrors('reserva_sangue');
});

test('cancelar um agendamento pede o motivo e este limpa-se ao voltar a agendar', function () {
    $espera = novaEspera(novoUtente(), $this->utilizador);
    $base = ['data' => now()->addDays(9)->format('Y-m-d'), 'local_id' => 1, 'tipo_id' => 1, 'sala_id' => 1, 'reserva_sangue' => 1, 'responsavel_id' => $this->utilizador->id];
    $this->actingAs($this->utilizador)->post("/agendamento/lista-de-espera/{$espera->id}/agendar", $base + ['estado' => 3])->assertSessionHasNoErrors();
    $agendamento = $espera->agendamentos()->first();

    // Cancelar sem motivo, ou com um motivo que não existe, é recusado.
    $this->actingAs($this->utilizador)->put("/agendamento/{$agendamento->id}", $base + ['estado' => 4])->assertSessionHasErrors('motivo_cancelamento');
    $this->actingAs($this->utilizador)->put("/agendamento/{$agendamento->id}", $base + ['estado' => 4, 'motivo_cancelamento' => 7])->assertSessionHasErrors('motivo_cancelamento');

    $this->actingAs($this->utilizador)->put("/agendamento/{$agendamento->id}", $base + ['estado' => 4, 'motivo_cancelamento' => 1])->assertSessionHasNoErrors();
    expect((string) $agendamento->fresh()->motivo_cancelar_agendamento)->toBe('1');
    $this->actingAs($this->utilizador)->get("/agendamento/{$agendamento->id}")->assertInertia(fn (Assert $p) => $p
        ->where('modal.agendamento.motivo_cancelamento', 1)->has('modal.dominios.motivos_cancelamento', 6));

    // Voltar a agendado limpa o motivo; fora de cancelado o motivo enviado é ignorado.
    $this->actingAs($this->utilizador)->put("/agendamento/{$agendamento->id}", $base + ['estado' => 3, 'motivo_cancelamento' => 2])->assertSessionHasNoErrors();
    expect($agendamento->fresh()->motivo_cancelar_agendamento)->toBeNull();
});
