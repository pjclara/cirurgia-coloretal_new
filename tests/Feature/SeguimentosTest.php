<?php

use App\Models\Internamento;
use App\Models\Morbilidade;
use Inertia\Testing\AssertableInertia as Assert;

function internamentoComAlta(object $t, int $diasDeAlta, array $extra = []): Internamento
{
    $i = novoInternamento(novoUtente(), $t->utilizador, ['data_de_entrada' => now()->subDays($diasDeAlta + 5)->format('Y-m-d')]);
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
