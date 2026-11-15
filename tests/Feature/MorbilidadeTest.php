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
    guardar($this, ['morbilidade' => ['alta' => ['complicacoes' => [['id' => $this->complicacao, 'resolucoes' => []]], 'clavien_dindo_id' => null]]])
        ->assertSessionHasErrors('morbilidade.alta.clavien_dindo_id');
});

test('"sem complicações" não pode vir com outras complicações', function () {
    guardar($this, ['morbilidade' => ['alta' => [
        'complicacoes' => [['id' => Complicacao::SEM_COMPLICACOES, 'resolucoes' => []], ['id' => $this->complicacao, 'resolucoes' => []]],
        'clavien_dindo_id' => 2,
    ]]])->assertSessionHasErrors('morbilidade.alta.complicacoes');
});

test('a morbilidade aos 30 dias só pode ser registada depois da alta', function () {
    guardar($this, ['morbilidade' => ['30_dias' => semComplicacoes()]])
        ->assertSessionHasErrors('morbilidade.30_dias.complicacoes');
});

test('grava a alta e os 30 dias e o grau mais grave é o maior', function () {
    $alta = now()->subDays(35)->format('Y-m-d');

    guardar($this, [
        'data_de_alta' => $alta,
        'destino_id' => $this->destinoVivo,
        'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA,
        'morbilidade' => [
            'alta' => ['complicacoes' => [['id' => $this->complicacao, 'resolucoes' => []]], 'clavien_dindo_id' => 2],
            '30_dias' => ['complicacoes' => [['id' => $this->complicacao, 'resolucoes' => []]], 'clavien_dindo_id' => 3, 'data_registo' => now()->subDays(2)->format('Y-m-d')],
        ],
    ])->assertSessionHasNoErrors();

    $internamento = $this->internamento->fresh()->load('morbilidades');
    expect($internamento->morbilidades)->toHaveCount(2)
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
    guardar($this, ['morbilidade' => ['alta' => semComplicacoes()]])->assertSessionHasNoErrors();
    expect($this->internamento->morbilidades()->count())->toBe(1);

    guardar($this, ['morbilidade' => ['alta' => ['complicacoes' => [], 'clavien_dindo_id' => null]]])->assertSessionHasNoErrors();
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
    return ['morbilidade' => ['alta' => ['complicacoes' => [['id' => $t->complicacao, 'resolucoes' => $resolucoes]], 'clavien_dindo_id' => $clavien]]];
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
    guardar($this, morbilidadeInterna($this, [resolucaoComGrau(null)]))->assertSessionHasErrors('morbilidade.alta.clavien_dindo_id');
    guardar($this, morbilidadeInterna($this, []))->assertSessionHasErrors('morbilidade.alta.clavien_dindo_id');

    guardar($this, morbilidadeInterna($this, [resolucaoComGrau(null)], 2))->assertSessionHasNoErrors();
});

test('o momento antigo "durante o internamento" é recusado e não conta nos graus', function () {
    guardar($this, [
        'data_de_alta' => now()->format('Y-m-d'),
        'destino_id' => $this->destinoVivo,
        'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA,
        'morbilidade' => [
            'internamento' => ['complicacoes' => [['id' => $this->complicacao, 'resolucoes' => []]], 'clavien_dindo_id' => 5],
            'alta' => semComplicacoes(),
        ],
    ])->assertSessionHasErrors('morbilidade');

    // Um registo antigo desse momento, já existente na base de dados, não entra no grau mais grave.
    $this->internamento->morbilidades()->create(['momento' => 'internamento', 'clavien_dindo_id' => 5]);
    expect($this->internamento->fresh()->load('morbilidades')->claviemMaisGrave())->toBeNull();
});

test('o estado da alta "Outro" já não se escolhe, mas um registo antigo que o tem mantém-no', function () {
    $outro = DB::table('estado_da_altas')->where('nome', 'Outro')->value('id');

    guardar($this, ['estado_da_alta_id' => $outro])->assertSessionHasErrors('estado_da_alta_id');

    DB::table('internamentos')->where('id', $this->internamento->id)->update(['estado_da_alta_id' => $outro]);
    guardar($this, ['estado_da_alta_id' => $outro])->assertSessionHasNoErrors();
});

function comCirurgia(object $teste): void
{
    $teste->actingAs($teste->utilizador)->post("/internamentos/{$teste->internamento->id}/cirurgias", [
        'data_de_inicio' => now()->subDays(50)->format('Y-m-d'),
        'tipo_de_cirurgia_id' => DB::table('tipo_de_cirurgias')->value('id'),
        'tipo_de_abordagem_id' => DB::table('tipo_de_abordagems')->value('id'),
        're_intervencao_nao_programada_id' => 1,
        'intervencoes' => [DB::table('intervencaos')->value('id')],
    ])->assertSessionHasNoErrors();
}

test('com cirurgia a alta fica pendente até à revisão aos 30 dias', function () {
    comCirurgia($this);
    $alta = [
        'data_de_alta' => now()->subDays(40)->format('Y-m-d'),
        'destino_id' => $this->destinoVivo,
        'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA,
    ];

    // Pede-se "Concluída" mas, sem o 30.º dia revisto, fica Pendente.
    guardar($this, $alta + ['morbilidade' => ['alta' => semComplicacoes()]])->assertSessionHasNoErrors();
    expect($this->internamento->fresh()->estado_da_alta_id)->toBe(Internamento::ALTA_PENDENTE);

    // Com a revisão aos 30 dias registada, já pode ficar concluída.
    guardar($this, $alta + ['morbilidade' => ['alta' => semComplicacoes(), '30_dias' => semComplicacoes() + ['data_registo' => now()->subDays(5)->format('Y-m-d')]]])
        ->assertSessionHasNoErrors();
    expect($this->internamento->fresh()->estado_da_alta_id)->toBe(Internamento::ALTA_CONCLUIDA);
});

test('sem cirurgia a alta pode ficar concluída logo', function () {
    guardar($this, [
        'data_de_alta' => now()->format('Y-m-d'),
        'destino_id' => $this->destinoVivo,
        'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA,
        'morbilidade' => ['alta' => semComplicacoes()],
    ])->assertSessionHasNoErrors();

    expect($this->internamento->fresh()->estado_da_alta_id)->toBe(Internamento::ALTA_CONCLUIDA);
});

test('registar o seguimento aos 30 dias conclui a alta de quem teve cirurgia', function () {
    comCirurgia($this);
    guardar($this, [
        'data_de_alta' => now()->subDays(40)->format('Y-m-d'),
        'destino_id' => $this->destinoVivo,
        'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA,
        'morbilidade' => ['alta' => semComplicacoes()],
    ])->assertSessionHasNoErrors();
    expect($this->internamento->fresh()->estado_da_alta_id)->toBe(Internamento::ALTA_PENDENTE);

    $this->actingAs($this->utilizador)->post("/internamentos/{$this->internamento->id}/seguimento", [
        'data_registo' => now()->format('Y-m-d'),
        'complicacoes' => [['id' => Complicacao::SEM_COMPLICACOES, 'resolucoes' => []]],
    ])->assertSessionHasNoErrors();

    expect($this->internamento->fresh()->estado_da_alta_id)->toBe(Internamento::ALTA_CONCLUIDA);
});

test('o formulário já não tem o bloqueio: gravar não bloqueia nem desbloqueia o registo', function () {
    // Um registo já bloqueado, gravado por um administrador, continua bloqueado.
    Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
    $this->utilizador->assignRole('admin');
    $this->internamento->forceFill(['bloquear_tabela' => true])->save();

    guardar($this, ['motivo_internamento' => 'Motivo alterado'])->assertSessionHasNoErrors();
    expect($this->internamento->fresh()->bloquear_tabela)->toBeTrue();

    // E um enviado com o campo (versão antiga do formulário) não bloqueia um registo livre.
    $this->internamento->forceFill(['bloquear_tabela' => false])->save();
    guardar($this, ['bloquear_tabela' => true])->assertSessionHasNoErrors();
    expect($this->internamento->fresh()->bloquear_tabela)->toBeFalse();
});
