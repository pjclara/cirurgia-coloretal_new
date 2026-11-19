<?php

use App\Models\Internamento;
use App\Models\Morbilidade;
use Inertia\Testing\AssertableInertia as Assert;

function internamentoComAlta(object $t, int $diasDeAlta, array $extra = []): Internamento
{
    $i = novoInternamento(novoUtente(), $t->utilizador, ['data_de_entrada' => now()->subDays($diasDeAlta + 5)->format('Y-m-d')]);
    internamentoOperado($t, $i);
    $i->forceFill($extra + [
        'data_de_alta' => now()->subDays($diasDeAlta)->format('Y-m-d'),
        'destino_id' => DB::table('destinos')->where('id', '<>', Internamento::DESTINO_FALECIDO)->value('id'),
        'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA,
    ])->save();

    return $i;
}

beforeEach(function () {
    $this->utilizador = utilizadorClinico();
});

test('lista altas em atraso e a decorrer sem registo aos 30 dias', function () {
    $atrasada = internamentoComAlta($this, 40);
    internamentoComAlta($this, 10);

    $this->actingAs($this->utilizador)->get('/internamentos/seguimentos')->assertInertia(fn (Assert $p) => $p
        ->component('internamentos/seguimentos')
        ->where('contagens.em_atraso', 1)
        ->where('contagens.a_decorrer', 1)
        ->where('seguimentos.data.0.id', $atrasada->id)
        ->where('seguimentos.data.0.dias_de_atraso', 10));
});

test('não inclui falecidos nem quem já tem o seguimento registado', function () {
    internamentoComAlta($this, 40, ['destino_id' => Internamento::DESTINO_FALECIDO]);
    $feito = internamentoComAlta($this, 40);
    Morbilidade::create(['internamento_id' => $feito->id, 'momento' => Morbilidade::AOS_30_DIAS]);

    $this->actingAs($this->utilizador)->get('/internamentos/seguimentos')->assertInertia(fn (Assert $p) => $p
        ->where('contagens.em_atraso', 0)
        ->where('contagens.a_decorrer', 0));
});

test('por defeito só mostra altas dos últimos 90 dias', function () {
    internamentoComAlta($this, 200);

    $this->actingAs($this->utilizador)->get('/internamentos/seguimentos')->assertInertia(fn (Assert $p) => $p->where('contagens.em_atraso', 0));
    $this->actingAs($this->utilizador)->get('/internamentos/seguimentos?periodo=365')->assertInertia(fn (Assert $p) => $p->where('contagens.em_atraso', 1));
});

test('a lista não mostra o grau da alta: o grau definitivo só existe depois dos 30 dias', function () {
    $i = internamentoComAlta($this, 40);
    $i->morbilidades()->create(['momento' => 'alta', 'clavien_dindo_id' => 3]);

    $this->actingAs($this->utilizador)->get('/internamentos/seguimentos')->assertInertia(fn (Assert $p) => $p
        ->missing('seguimentos.data.0.clavien'));
    expect($i->fresh()->load('morbilidades')->claviemMaisGrave())->toBeNull();
});

test('a lista mostra todos por ordem de alta, com os doentes do CR identificados e filtráveis', function () {
    $this->utilizador->givePermissionTo('show referenciacao');
    $antiga = internamentoComAlta($this, 60);       // sem CR, mais antiga
    $recente = internamentoComAlta($this, 10);      // doente do CR, mais recente
    DB::table('referenciacao_centro_de_referencias')->insert([
        'utente_id' => $recente->utente_id, 'data_de_referenciacao' => now()->subDays(30)->format('Y-m-d'),
        'origem_id' => 1, 'responsavel_id' => $this->utilizador->id, 'created_by_id' => $this->utilizador->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(),
    ]);

    $this->actingAs($this->utilizador)->get('/internamentos/seguimentos')->assertInertia(fn (Assert $p) => $p
        ->where('seguimentos.total', 2)
        ->where('seguimentos.data.0.id', $antiga->id)->where('seguimentos.data.0.cr', false)   // a mais antiga primeiro, CR ou não
        ->where('seguimentos.data.1.id', $recente->id)->where('seguimentos.data.1.cr', true)
        ->where('contagens.cr', 1));

    $this->actingAs($this->utilizador)->get('/internamentos/seguimentos?cr=1')->assertInertia(fn (Assert $p) => $p
        ->where('seguimentos.total', 1)->where('filtros.cr', true)->where('contagens.cr', 1));
});

test('os seguimentos podem procurar-se por nome ou processo', function () {
    $um = internamentoComAlta($this, 40);
    internamentoComAlta($this, 41);
    $um->utente->update(['nome' => 'Zacarias Teste']);

    $this->actingAs($this->utilizador)->get('/internamentos/seguimentos?q=Zacarias')->assertInertia(fn (Assert $p) => $p
        ->where('seguimentos.total', 1)->where('seguimentos.data.0.id', $um->id)->where('filtros.q', 'Zacarias'));
    $this->actingAs($this->utilizador)->get('/internamentos/seguimentos?q='.$um->utente->numero_processo)->assertInertia(fn (Assert $p) => $p->where('seguimentos.data.0.id', $um->id));
});

test('sem permissão de ver referenciações os doentes do CR não são identificados nos seguimentos', function () {
    $doente = internamentoComAlta($this, 40);
    DB::table('referenciacao_centro_de_referencias')->insert([
        'utente_id' => $doente->utente_id, 'data_de_referenciacao' => now()->subDays(60)->format('Y-m-d'),
        'origem_id' => 1, 'responsavel_id' => $this->utilizador->id, 'created_by_id' => $this->utilizador->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(),
    ]);

    $this->actingAs($this->utilizador)->get('/internamentos/seguimentos?cr=1')->assertInertia(fn (Assert $p) => $p
        ->where('podeVerCr', false)->where('contagens.cr', null)->where('filtros.cr', false)->where('seguimentos.data.0.cr', false));
});
