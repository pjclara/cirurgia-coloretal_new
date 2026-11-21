<?php

use App\Models\Complicacao;
use App\Models\Internamento;
use App\Models\Morbilidade;
use Inertia\Testing\AssertableInertia as Assert;

/** Internamento com alta há `$dias` dias e sem seguimento registado. */
function altaParaSeguimento(object $t, int $dias = 40, array $extra = []): Internamento
{
    $i = novoInternamento(novoUtente(), $t->utilizador, ['data_de_entrada' => now()->subDays($dias + 5)->format('Y-m-d')]);
    internamentoOperado($t, $i);
    $i->forceFill($extra + [
        'data_de_alta' => now()->subDays($dias)->format('Y-m-d'),
        'destino_id' => DB::table('destinos')->where('id', '<>', Internamento::DESTINO_FALECIDO)->value('id'),
        'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA,
    ])->save();

    return $i;
}

function registarSeguimento(object $t, Internamento $i, array $extra = [])
{
    return $t->actingAs($t->utilizador)->post("/internamentos/{$i->id}/seguimento", $extra + [
        'data_registo' => now()->format('Y-m-d'),
        'complicacoes' => [['id' => Complicacao::SEM_COMPLICACOES, 'resolucoes' => []]],
    ]);
}

beforeEach(function () {
    $this->utilizador = utilizadorClinico();
    $this->complicacao = DB::table('complicacaos')->where('id', '<>', Complicacao::SEM_COMPLICACOES)->value('id');
    $this->internamento = altaParaSeguimento($this);
});

test('registar "sem complicações" grava o seguimento e tira-o da lista', function () {
    registarSeguimento($this, $this->internamento)->assertSessionHasNoErrors()->assertSessionHas('sucesso');

    $m = $this->internamento->morbilidades()->where('momento', Morbilidade::AOS_30_DIAS)->first();
    expect($m)->not->toBeNull()->and($m->clavien_dindo_id)->toBeNull()->and($m->data_registo->format('Y-m-d'))->toBe(now()->format('Y-m-d'));

    $this->actingAs($this->utilizador)->get('/internamentos/seguimentos')->assertInertia(fn (Assert $p) => $p->where('contagens.em_atraso', 0));
});

test('com complicação, o grau definitivo calcula-se pelas resoluções e o indicado à mão é ignorado', function () {
    $resolucao = (int) DB::table('resolucao_complicacaos')->where('clavien_dindo_id', 3)->value('id');
    $corpo = ['complicacoes' => [['id' => $this->complicacao, 'resolucoes' => [$resolucao]]]];

    registarSeguimento($this, $this->internamento, $corpo + ['clavien_dindo_id' => 6])->assertSessionHasNoErrors();
    expect($this->internamento->morbilidades()->where('momento', '30_dias')->value('clavien_dindo_id'))->toBe(3);

    registarSeguimento($this, $this->internamento, $corpo)->assertSessionHasNoErrors();
    expect($this->internamento->morbilidades()->where('momento', '30_dias')->count())->toBe(1);
});

test('o seguimento junta as resoluções da alta: vale o grau mais alto dos dois momentos', function () {
    $alta = $this->internamento->morbilidades()->create(['momento' => 'alta']);
    $alta->complicacoes()->sync([$this->complicacao => ['resolucao' => json_encode([(string) DB::table('resolucao_complicacaos')->where('clavien_dindo_id', 5)->value('id')])]]);

    registarSeguimento($this, $this->internamento)->assertSessionHasNoErrors();

    expect($this->internamento->fresh()->load('morbilidades')->claviemMaisGrave())->toBe(5);
});

test('complicação sem resolução com grau regista-se sem grau definitivo', function () {
    registarSeguimento($this, $this->internamento, ['complicacoes' => [['id' => $this->complicacao, 'resolucoes' => []]]])
        ->assertSessionHasNoErrors();

    expect($this->internamento->fresh()->load('morbilidades')->claviemMaisGrave())->toBeNull();
});

test('"sem complicações" não vem com outras e é preciso escolher uma opção', function () {
    registarSeguimento($this, $this->internamento, ['complicacoes' => [['id' => Complicacao::SEM_COMPLICACOES], ['id' => $this->complicacao]], 'clavien_dindo_id' => 2])
        ->assertSessionHasErrors('complicacoes');
    registarSeguimento($this, $this->internamento, ['complicacoes' => []])->assertSessionHasErrors('complicacoes');
});

test('a data não pode ser anterior à alta nem futura', function () {
    registarSeguimento($this, $this->internamento, ['data_registo' => now()->subDays(50)->format('Y-m-d')])->assertSessionHasErrors('data_registo');
    registarSeguimento($this, $this->internamento, ['data_registo' => now()->addDay()->format('Y-m-d')])->assertSessionHasErrors('data_registo');
});

test('sem alta não há seguimento', function () {
    $internado = novoInternamento(novoUtente(), $this->utilizador);

    registarSeguimento($this, $internado)->assertStatus(422);
});

test('permissões e bloqueio: só quem pode alterar, e registo bloqueado só um administrador', function () {
    $leitor = utilizadorClinico(['show internamento']);
    $this->actingAs($leitor)->post("/internamentos/{$this->internamento->id}/seguimento", ['data_registo' => now()->format('Y-m-d'), 'complicacoes' => [['id' => 1]]])->assertForbidden();

    $this->internamento->forceFill(['bloquear_tabela' => true])->save();
    registarSeguimento($this, $this->internamento)->assertForbidden();
    expect($this->internamento->morbilidades()->count())->toBe(0);
});

test('a lista traz os domínios para o registo rápido e a permissão de registar', function () {
    $this->actingAs($this->utilizador)->get('/internamentos/seguimentos')->assertInertia(fn (Assert $p) => $p
        ->where('podeRegistar', true)
        ->has('dominios.complicacoes')->has('dominios.clavien')
        ->where('dominios.resolucoes', fn ($r) => collect($r)->contains(fn ($x) => $x['clavien_dindo_id'] !== null))
        ->where('seguimentos.data.0.bloqueado', false));

    $leitor = utilizadorClinico(['show internamento']);
    $this->actingAs($leitor)->get('/internamentos/seguimentos')->assertInertia(fn (Assert $p) => $p->where('podeRegistar', false));
});

test('um registo bloqueado aparece na lista como bloqueado para quem não é administrador', function () {
    $this->internamento->forceFill(['bloquear_tabela' => true])->save();

    $this->actingAs($this->utilizador)->get('/internamentos/seguimentos')->assertOk()->assertInertia(fn (Assert $p) => $p->where('seguimentos.data.0.bloqueado', true));
});

test('o seguimento aos 30 dias pode ser registado por qualquer utilizador com permissão de editar, não só pelo responsável', function () {
    $outro = utilizadorClinico();
    expect($this->internamento->responsavel_id)->not->toBe($outro->id);

    $this->actingAs($outro)->get('/internamentos/seguimentos')->assertInertia(fn (Assert $p) => $p->where('seguimentos.data.0.bloqueado', false));
    $this->actingAs($outro)->post("/internamentos/{$this->internamento->id}/seguimento", ['data_registo' => now()->format('Y-m-d'), 'complicacoes' => [['id' => 1]]])
        ->assertSessionHasNoErrors();
    expect($this->internamento->morbilidades()->count())->toBe(1);
});
