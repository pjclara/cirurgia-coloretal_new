<?php

use App\Models\Complicacao;
use App\Models\Internamento;

beforeEach(function () {
    $this->utilizador = utilizadorClinico();
    $this->internamento = novoInternamento(novoUtente(), $this->utilizador, ['data_de_entrada' => now()->subDays(20)->format('Y-m-d')]);
    $this->destinos = DB::table('destinos')->where('id', '<>', Internamento::DESTINO_FALECIDO)->pluck('id');
    $this->sem = ['complicacoes' => [['id' => Complicacao::SEM_COMPLICACOES, 'resolucoes' => []]], 'clavien_dindo_id' => null];
});

function guardarAlta(object $t, array $extra)
{
    return $t->actingAs($t->utilizador)->put("/internamentos/{$t->internamento->id}", payloadInternamento($t->internamento, $extra));
}

test('uma alta já dada pode ser editada', function () {
    $alta = ['data_de_alta' => now()->subDays(5)->format('Y-m-d'), 'destino_id' => $this->destinos[0], 'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA, 'morbilidade' => ['alta' => $this->sem]];
    guardarAlta($this, $alta)->assertSessionHasNoErrors();

    guardarAlta($this, [
        'data_de_alta' => now()->subDays(3)->format('Y-m-d'), 'data_de_saida' => now()->subDays(2)->format('Y-m-d'),
        'destino_id' => $this->destinos[1] ?? $this->destinos[0], 'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA, 'morbilidade' => ['alta' => $this->sem],
    ])->assertSessionHasNoErrors();

    expect($this->internamento->fresh()->data_de_alta->format('Y-m-d'))->toBe(now()->subDays(3)->format('Y-m-d'));
});

test('cancelar a alta devolve o doente ao internamento', function () {
    guardarAlta($this, ['data_de_alta' => now()->subDays(5)->format('Y-m-d'), 'destino_id' => $this->destinos[0], 'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA, 'morbilidade' => ['alta' => $this->sem]])->assertSessionHasNoErrors();

    guardarAlta($this, ['data_de_alta' => '', 'data_de_saida' => '', 'destino_id' => '', 'estado_da_alta_id' => Internamento::ALTA_PENDENTE, 'morbilidade' => ['alta' => $this->sem]])->assertSessionHasNoErrors();

    $i = $this->internamento->fresh();
    expect($i->data_de_alta)->toBeNull()->and($i->destino_id)->toBeNull()->and($i->data_de_saida)->toBeNull();
});

test('cancelar a alta retira também o seguimento aos 30 dias', function () {
    $dados = ['data_de_alta' => now()->subDays(5)->format('Y-m-d'), 'destino_id' => $this->destinos[0], 'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA,
        'morbilidade' => ['alta' => $this->sem, '30_dias' => $this->sem + ['data_registo' => now()->subDay()->format('Y-m-d')]]];
    guardarAlta($this, $dados)->assertSessionHasNoErrors();
    expect($this->internamento->morbilidades()->count())->toBe(2);

    guardarAlta($this, ['data_de_alta' => '', 'data_de_saida' => '', 'destino_id' => '', 'estado_da_alta_id' => Internamento::ALTA_PENDENTE,
        'morbilidade' => ['alta' => $this->sem, '30_dias' => ['complicacoes' => [], 'clavien_dindo_id' => '', 'data_registo' => '']]])->assertSessionHasNoErrors();

    expect($this->internamento->fresh()->data_de_alta)->toBeNull()
        ->and($this->internamento->morbilidades()->where('momento', '30_dias')->exists())->toBeFalse();
});

test('a alta edita-se numa modal sobre a lista e, ao guardar, volta à lista', function () {
    $alta = ['data_de_alta' => now()->subDays(5)->format('Y-m-d'), 'destino_id' => $this->destinos[0], 'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA, 'morbilidade' => ['alta' => $this->sem]];
    guardarAlta($this, $alta)->assertSessionHasNoErrors();

    $this->actingAs($this->utilizador)->get("/internamentos/{$this->internamento->id}/editar")->assertInertia(fn ($p) => $p
        ->component('internamentos/index')
        ->where('modal.internamento.id', $this->internamento->id)
        ->where('modal.fechar_url', '/internamentos')
        ->where('modal.origem', 'lista'));

    guardarAlta($this, ['origem' => 'lista', 'data_de_alta' => now()->subDays(4)->format('Y-m-d'), 'destino_id' => $this->destinos[0], 'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA, 'morbilidade' => ['alta' => $this->sem]])
        ->assertRedirect(route('internamentos.index'));
});

test('ao registar internamento ou agendar, diz-se se o utente ainda não está sinalizado para o centro de referência', function () {
    $utente = novoUtente();
    $u = utilizadorClinico();
    $u->givePermissionTo('create referenciacao');

    $this->actingAs($u)->get('/quadro/novo?utente='.$utente->id)->assertInertia(fn ($p) => $p
        ->where('modal.utente.sinalizado', false)
        ->has('modal.referenciacao.origens'));

    $semPermissao = utilizadorClinico();
    $this->actingAs($semPermissao)->get('/quadro/novo?utente='.$utente->id)->assertInertia(fn ($p) => $p->where('modal.referenciacao', null));
});

test('na lista de espera abre-se a inscrição de um utente escolhido na pesquisa', function () {
    $utente = novoUtente();

    $this->actingAs($this->utilizador)->get("/agendamento/utentes/{$utente->id}/inscrever")->assertInertia(fn ($p) => $p
        ->component('agendamento/lista-de-espera')
        ->where('modal.tipo', 'inscricao')
        ->where('modal.utente.id', $utente->id));
});

test('os diagnósticos do centro de referência do internamento passam para a referenciação ativa do utente', function () {
    $utente = $this->internamento->utente;
    $cr = DB::table('diagnosticos')->where('centro_de_referencia', 1)->value('id');
    $normal = DB::table('diagnosticos')->where('centro_de_referencia', 0)->value('id');
    $referenciacao = new \App\Models\Referenciacao(['data_de_referenciacao' => now()->subDays(10)->format('Y-m-d'), 'origem_id' => 1, 'responsavel_id' => $this->utilizador->id]);
    $referenciacao->utente_id = $utente->id;
    $referenciacao->created_by_id = $this->utilizador->id;
    $referenciacao->save();

    $this->actingAs($this->utilizador)->put("/internamentos/{$this->internamento->id}", payloadInternamento($this->internamento, ['diagnosticos' => [$cr, $normal]]))->assertSessionHasNoErrors();

    $ids = $referenciacao->diagnosticos()->pluck('diagnosticos.id')->all();
    expect($ids)->toBe([$cr])
        ->and($referenciacao->diagnosticos()->first()->pivot->modo_id)->toBe(\App\Models\Referenciacao::MODO_OUTRO);

    // Voltar a gravar não duplica.
    $this->actingAs($this->utilizador)->put("/internamentos/{$this->internamento->id}", payloadInternamento($this->internamento, ['diagnosticos' => [$cr]]))->assertSessionHasNoErrors();
    expect($referenciacao->diagnosticos()->count())->toBe(1);
});

test('a migration associa às referenciações ativas os diagnósticos CR já registados nos internamentos', function () {
    $migration = require database_path('migrations/2026_11_17_000002_associar_diagnosticos_cr_as_referenciacoes.php');
    $utente = $this->internamento->utente;
    $cr = DB::table('diagnosticos')->where('centro_de_referencia', 1)->value('id');
    $this->internamento->diagnosticos()->sync([$cr]);
    $ativa = new \App\Models\Referenciacao(['data_de_referenciacao' => now()->subDays(10)->format('Y-m-d'), 'origem_id' => 1, 'responsavel_id' => $this->utilizador->id]);
    $ativa->utente_id = $utente->id;
    $ativa->created_by_id = $this->utilizador->id;
    $ativa->save();

    $migration->up();
    $migration->up(); // idempotente

    expect($ativa->diagnosticos()->pluck('diagnosticos.id')->all())->toBe([$cr])
        ->and($ativa->diagnosticos()->first()->pivot->modo_id)->toBe(99);
});
