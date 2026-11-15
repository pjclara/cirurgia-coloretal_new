<?php

use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->gestor = utilizadorClinico(['show referenciacao', 'create referenciacao', 'update referenciacao', 'show internamento']);
    $this->origem = DB::table('origem_da_referenciacaos')->value('id');
});

/** Referencia um utente novo, com datas relativas a hoje (dias atrás). */
function referenciarUtente(object $t, int $referenciadoHa, ?int $entradaHa = null, ?int $saidaHa = null, array $extra = []): App\Models\Utente
{
    $utente = novoUtente();
    $corpo = [
        'data_de_referenciacao' => now()->subDays($referenciadoHa)->format('Y-m-d'),
        'data_de_diagnostico' => now()->subDays($referenciadoHa + 5)->format('Y-m-d'),
        'origem_id' => $t->origem,
        'responsavel_id' => $t->gestor->id,
        'diagnosticos' => [[
            'diagnostico_id' => DB::table('diagnosticos')->value('id'),
            'modo_id' => DB::table('diagnostico_referenciacao_modos')->value('id'),
            'histologia_id' => DB::table('histologias')->value('id'),
            'data_diagnostico' => now()->subDays($referenciadoHa + 5)->format('Y-m-d'),
        ]],
    ] + ($entradaHa !== null ? ['data_de_entrada' => now()->subDays($entradaHa)->format('Y-m-d')] : [])
      + ($saidaHa !== null ? ['data_de_saida' => now()->subDays($saidaHa)->format('Y-m-d'), 'destino_id' => 1] : []) + $extra;

    $t->actingAs($t->gestor)->post("/referenciacao/utentes/{$utente->id}", $corpo)->assertSessionHasNoErrors();

    return $utente;
}

test('a análise do CR exige a permissão de ver referenciações', function () {
    $this->actingAs(utilizadorClinico(['show internamento']))->get('/referenciacao/analise')->assertForbidden();
    $this->actingAs($this->gestor)->get('/referenciacao/analise')->assertOk();
});

test('a análise do CR conta as referenciações, as situações e a espera até à entrada', function () {
    referenciarUtente($this, 20, 12);      // entrou ao fim de 8 dias, no centro
    referenciarUtente($this, 40);          // ainda aguarda entrada
    referenciarUtente($this, 60, 30, 5);   // entrou ao fim de 30 dias, já saiu
    referenciarUtente($this, 1500, 1490);  // fora dos 12 meses

    $this->actingAs($this->gestor)->get('/referenciacao/analise')->assertInertia(fn (Assert $p) => $p
        ->component('referenciacao/analise')
        ->where('periodo', '12')
        ->where('indicadores.referenciacoes.valor', 3)
        ->where('indicadores.referenciados', 1)
        ->where('indicadores.no_centro', 1)
        ->where('indicadores.saidas', 1)
        ->where('indicadores.ate_entrada', fn ($v) => (float) $v === 19.0)   // mediana de 8 e 30
        ->where('indicadores.diagnostico_ate_referenciacao', fn ($v) => (float) $v === 5.0)
        ->has('mensal', 24)
        ->where('origens.0.valor', 3));

    // Todo o período inclui a mais antiga.
    $this->actingAs($this->gestor)->get('/referenciacao/analise?periodo=todos')->assertInertia(fn (Assert $p) => $p
        ->where('indicadores.referenciacoes.valor', 4)
        ->where('indicadores.referenciacoes.anterior', null));
});

test('a análise do CR compara os internamentos dos doentes do CR com os restantes', function () {
    $doente = referenciarUtente($this, 30, 20);
    $internamentoCr = novoInternamento($doente, $this->gestor, ['data_de_entrada' => now()->subDays(10)->format('Y-m-d'), 'data_de_alta' => now()->subDays(2)->format('Y-m-d')]);
    $internamentoOutro = novoInternamento(novoUtente(), $this->gestor, ['data_de_entrada' => now()->subDays(10)->format('Y-m-d'), 'data_de_alta' => now()->subDays(4)->format('Y-m-d')]);

    $this->actingAs($this->gestor)->get('/referenciacao/analise')->assertInertia(fn (Assert $p) => $p
        ->where('indicadores.internados', 1)
        ->where('comparacao.cr.internamentos', 1)
        ->where('comparacao.cr.demora', fn ($v) => (float) $v === 8.0)
        ->where('comparacao.restantes.internamentos', fn ($n) => $n >= 1)
        ->has('comparacao.restantes.complicacoes'));

    expect($internamentoCr->exists && $internamentoOutro->exists)->toBeTrue();
});

test('referenciar rapidamente: a pesquisa assinala quem já está referenciado e a página traz as origens', function () {
    $referenciado = referenciarUtente($this, 10);
    $livre = novoUtente(['nome' => 'Doente Sem Internamento Nem Agenda']);

    $porNome = fn ($u) => collect($this->actingAs($this->gestor)->getJson('/utentes/pesquisa?q='.urlencode($u->nome))->json())->firstWhere('id', $u->id);
    expect($porNome($referenciado)['referenciado'])->toBeTrue()
        ->and($porNome($livre)['referenciado'])->toBeFalse()
        ->and($porNome($livre)['internamento_aberto_id'])->toBeNull();

    $this->actingAs($this->gestor)->get('/referenciacao')->assertInertia(fn (Assert $p) => $p
        ->where('referenciar', null)
        ->where('origens', fn ($o) => collect($o)->count() >= 1));

    // Sinalizar sem internamento nem agendamento.
    $this->actingAs($this->gestor)->post("/referenciacao/utentes/{$livre->id}/sinalizar", [
        'data_de_referenciacao' => now()->format('Y-m-d'),
        'origem_id' => $this->origem,
    ])->assertSessionHasNoErrors();
    expect($livre->referenciacoesAtivas()->count())->toBe(1);
});

test('um utente criado a partir da Referenciação segue para a sinalização rápida', function () {
    // Criar utentes exige a permissão de criar internamentos (a mesma da ficha do utente).
    $gestorComUtentes = utilizadorClinico();
    $gestorComUtentes->givePermissionTo(['show referenciacao', 'create referenciacao']);
    $this->actingAs($gestorComUtentes)->get('/quadro/novo-utente?origem=referenciacao')->assertInertia(fn (Assert $p) => $p
        ->where('modalUtente.origem', 'referenciacao')
        ->where('modalUtente.fechar_url', '/referenciacao'));

    $this->actingAs(utilizadorClinico())->post('/utentes', [
        'nome' => 'Utente Novo Para o CR',
        'numero_processo' => '7654321',
        'data_nascimento' => '1960-05-05',
        'sexo_id' => 1,
        'concelho_id' => DB::table('concelhos')->value('id'),
        'origem' => 'referenciacao',
    ]);
    $utente = App\Models\Utente::where('numero_processo', 7654321)->firstOrFail();

    $this->actingAs($this->gestor)->get('/referenciacao?referenciar='.$utente->id)->assertInertia(fn (Assert $p) => $p
        ->where('referenciar.id', $utente->id)
        ->where('referenciar.nome', 'Utente Novo Para o CR'));

    // Já sinalizado: a sinalização rápida não volta a abrir.
    $this->actingAs($this->gestor)->post("/referenciacao/utentes/{$utente->id}/sinalizar", ['data_de_referenciacao' => now()->format('Y-m-d'), 'origem_id' => $this->origem]);
    $this->actingAs($this->gestor)->get('/referenciacao?referenciar='.$utente->id)->assertInertia(fn (Assert $p) => $p->where('referenciar', null));

    // Sem permissão de referenciar, nunca se abre.
    $this->actingAs(utilizadorClinico(['show referenciacao']))->get('/referenciacao?referenciar='.novoUtente()->id)->assertInertia(fn (Assert $p) => $p->where('referenciar', null)->where('origens', []));
});

test('a sinalização rápida pode associar um médico; sem escolha fica quem registou', function () {
    $medico = utilizadorClinico(['show internamento']);
    $a = novoUtente();
    $b = novoUtente();
    $hoje = now()->format('Y-m-d');

    $this->actingAs($this->gestor)->post("/referenciacao/utentes/{$a->id}/sinalizar", ['data_de_referenciacao' => $hoje, 'origem_id' => $this->origem, 'responsavel_id' => $medico->id])->assertSessionHasNoErrors();
    $this->actingAs($this->gestor)->post("/referenciacao/utentes/{$b->id}/sinalizar", ['data_de_referenciacao' => $hoje, 'origem_id' => $this->origem])->assertSessionHasNoErrors();

    expect($a->referenciacoesAtivas()->first()->responsavel_id)->toBe($medico->id)
        ->and($b->referenciacoesAtivas()->first()->responsavel_id)->toBe($this->gestor->id);

    // Um utilizador inativo ou inexistente não pode ser associado.
    $inativo = utilizadorClinico(['show internamento']);
    $inativo->forceFill(['activo' => false])->save();
    $c = novoUtente();
    $this->actingAs($this->gestor)->post("/referenciacao/utentes/{$c->id}/sinalizar", ['data_de_referenciacao' => $hoje, 'origem_id' => $this->origem, 'responsavel_id' => $inativo->id])->assertSessionHasErrors('responsavel_id');

    // A página e a ficha trazem a lista de médicos.
    $this->actingAs($this->gestor)->get('/referenciacao')->assertInertia(fn (Assert $p) => $p->where('responsaveis', fn ($r) => collect($r)->pluck('id')->contains($medico->id)));
    $this->actingAs($this->gestor)->get("/utentes/{$c->id}")->assertInertia(fn (Assert $p) => $p->where('responsaveisReferenciacao', fn ($r) => collect($r)->pluck('id')->contains($medico->id)));
});
