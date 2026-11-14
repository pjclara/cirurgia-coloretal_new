<?php

use App\Models\Complicacao;
use App\Models\Internamento;
use App\Models\Morbilidade;

beforeEach(function () {
    $this->utilizador = utilizadorClinico();
    $this->internamento = novoInternamento(novoUtente(), $this->utilizador, ['data_de_entrada' => now()->subDays(60)->format('Y-m-d')]);
    $this->destinoVivo = DB::table('destinos')->where('id', '<>', Internamento::DESTINO_FALECIDO)->value('id');
    $this->complicacao = DB::table('complicacaos')->where('id', '<>', Complicacao::SEM_COMPLICACOES)->value('id');
});

function guardar(object $teste, array $extra)
{
    return $teste->actingAs($teste->utilizador)->put(
        "/internamentos/{$teste->internamento->id}",
        payloadInternamento($teste->internamento, $extra)
    );
}

function semComplicacoes(): array
{
    return ['complicacoes' => [['id' => Complicacao::SEM_COMPLICACOES, 'resolucoes' => []]], 'clavien_dindo_id' => null];
}

test('dar alta exige a morbilidade da alta', function () {
    guardar($this, ['data_de_alta' => now()->format('Y-m-d'), 'destino_id' => $this->destinoVivo])
        ->assertSessionHasErrors('morbilidade.alta.complicacoes');

    expect($this->internamento->fresh()->data_de_alta)->toBeNull();
});

test('dar alta exige destino', function () {
    guardar($this, ['data_de_alta' => now()->format('Y-m-d'), 'morbilidade' => ['alta' => semComplicacoes()]])
        ->assertSessionHasErrors('destino_id');
});

test('alta com "sem complicações" e destino é gravada', function () {
    guardar($this, [
        'data_de_alta' => now()->format('Y-m-d'),
        'destino_id' => $this->destinoVivo,
        'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA,
        'morbilidade' => ['alta' => semComplicacoes()],
    ])->assertSessionHasNoErrors();

    expect($this->internamento->fresh()->data_de_alta)->not->toBeNull()
        ->and($this->internamento->morbilidades()->where('momento', Morbilidade::NA_ALTA)->exists())->toBeTrue();
});

test('complicação real exige o grau de Clavien-Dindo', function () {
    guardar($this, ['morbilidade' => ['internamento' => ['complicacoes' => [['id' => $this->complicacao, 'resolucoes' => []]], 'clavien_dindo_id' => null]]])
        ->assertSessionHasErrors('morbilidade.internamento.clavien_dindo_id');
});

test('"sem complicações" não pode vir com outras complicações', function () {
    guardar($this, ['morbilidade' => ['internamento' => [
        'complicacoes' => [['id' => Complicacao::SEM_COMPLICACOES, 'resolucoes' => []], ['id' => $this->complicacao, 'resolucoes' => []]],
        'clavien_dindo_id' => 2,
    ]]])->assertSessionHasErrors('morbilidade.internamento.complicacoes');
});

test('a morbilidade aos 30 dias só pode ser registada depois da alta', function () {
    guardar($this, ['morbilidade' => ['30_dias' => semComplicacoes()]])
        ->assertSessionHasErrors('morbilidade.30_dias.complicacoes');
});

test('grava os três momentos e o grau mais grave é o maior', function () {
    $alta = now()->subDays(35)->format('Y-m-d');

    guardar($this, [
        'data_de_alta' => $alta,
        'destino_id' => $this->destinoVivo,
        'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA,
        'morbilidade' => [
            'internamento' => ['complicacoes' => [['id' => $this->complicacao, 'resolucoes' => []]], 'clavien_dindo_id' => 2],
            'alta' => semComplicacoes(),
            '30_dias' => ['complicacoes' => [['id' => $this->complicacao, 'resolucoes' => []]], 'clavien_dindo_id' => 3, 'data_registo' => now()->subDays(2)->format('Y-m-d')],
        ],
    ])->assertSessionHasNoErrors();

    $internamento = $this->internamento->fresh()->load('morbilidades');
    expect($internamento->morbilidades)->toHaveCount(3)
        ->and($internamento->claviemMaisGrave())->toBe(3);
});

test('óbito com complicação na alta fica em grau 5', function () {
    guardar($this, [
        'data_de_alta' => now()->format('Y-m-d'),
        'destino_id' => Internamento::DESTINO_FALECIDO,
        'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA,
        'morbilidade' => ['alta' => ['complicacoes' => [['id' => $this->complicacao, 'resolucoes' => []]], 'clavien_dindo_id' => null]],
    ])->assertSessionHasNoErrors();

    expect($this->internamento->fresh()->load('morbilidades')->claviemMaisGrave())->toBe(Internamento::CLAVIEN_GRAU_5);
});

test('um momento sem complicações escolhidas deixa de estar registado', function () {
    guardar($this, ['morbilidade' => ['internamento' => semComplicacoes()]])->assertSessionHasNoErrors();
    expect($this->internamento->morbilidades()->count())->toBe(1);

    guardar($this, ['morbilidade' => ['internamento' => ['complicacoes' => [], 'clavien_dindo_id' => null]]])->assertSessionHasNoErrors();
    expect($this->internamento->morbilidades()->count())->toBe(0);
});

/** Resolução com o grau associado (ids de clavien_dindos), ou sem grau. */
function resolucaoComGrau(?int $grau): int
{
    $q = DB::table('resolucao_complicacaos');

    return (int) ($grau === null ? $q->whereNull('clavien_dindo_id')->value('id') : $q->where('clavien_dindo_id', $grau)->value('id'));
}

function morbilidadeInterna(object $t, array $resolucoes, $clavien = null): array
{
    return ['morbilidade' => ['internamento' => ['complicacoes' => [['id' => $t->complicacao, 'resolucoes' => $resolucoes]], 'clavien_dindo_id' => $clavien]]];
}

test('o grau de Clavien-Dindo calcula-se pela resolução, sem o indicar', function () {
    guardar($this, morbilidadeInterna($this, [resolucaoComGrau(3)]))->assertSessionHasNoErrors();

    expect($this->internamento->morbilidades()->first()->clavien_dindo_id)->toBe(3);
});

test('com várias resoluções vale o grau mais alto', function () {
    guardar($this, morbilidadeInterna($this, [resolucaoComGrau(2), resolucaoComGrau(4), resolucaoComGrau(1)]))->assertSessionHasNoErrors();

    expect($this->internamento->morbilidades()->first()->clavien_dindo_id)->toBe(4);
});

test('o grau escolhido à mão prevalece sobre o calculado', function () {
    guardar($this, morbilidadeInterna($this, [resolucaoComGrau(5)], 6))->assertSessionHasNoErrors();

    expect($this->internamento->morbilidades()->first()->clavien_dindo_id)->toBe(6);
});

test('sem resolução com grau associado, o grau continua obrigatório', function () {
    guardar($this, morbilidadeInterna($this, [resolucaoComGrau(null)]))->assertSessionHasErrors('morbilidade.internamento.clavien_dindo_id');
    guardar($this, morbilidadeInterna($this, []))->assertSessionHasErrors('morbilidade.internamento.clavien_dindo_id');

    guardar($this, morbilidadeInterna($this, [resolucaoComGrau(null)], 2))->assertSessionHasNoErrors();
});
