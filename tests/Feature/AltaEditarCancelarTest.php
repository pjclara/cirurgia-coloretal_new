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
