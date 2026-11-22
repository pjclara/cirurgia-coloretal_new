<?php

use App\Models\Complicacao;
use App\Models\Internamento;
use App\Models\Morbilidade;

function altaParaFechar(object $t, array $extra = [], bool $semComplicacoesNaAlta = true): Internamento
{
    $i = novoInternamento(novoUtente(), $t->utilizador, ['data_de_entrada' => now()->subDays(65)->format('Y-m-d')]);
    internamentoOperado($t, $i);
    $i->forceFill($extra + [
        'data_de_alta' => now()->subDays(60)->format('Y-m-d'),
        'destino_id' => DB::table('destinos')->where('id', '<>', Internamento::DESTINO_FALECIDO)->value('id'),
        'estado_da_alta_id' => Internamento::ALTA_CONCLUIDA,
    ])->save();
    $m = Morbilidade::create(['internamento_id' => $i->id, 'momento' => Morbilidade::NA_ALTA, 'data_registo' => $i->data_de_alta]);
    $m->complicacoes()->sync([$semComplicacoesNaAlta ? Complicacao::SEM_COMPLICACOES : 2 => ['resolucao' => null]]);

    return $i;
}

beforeEach(fn () => $this->utilizador = utilizadorClinico());

test('fecha os 30 dias só nas altas concluídas, operadas e sem complicações na alta, e não repete', function () {
    $bom = altaParaFechar($this);
    $comComplicacao = altaParaFechar($this, [], false);
    $pendente = altaParaFechar($this, ['estado_da_alta_id' => Internamento::ALTA_PENDENTE]);
    $falecido = altaParaFechar($this, ['destino_id' => Internamento::DESTINO_FALECIDO]);
    $jaTem = altaParaFechar($this);
    Morbilidade::create(['internamento_id' => $jaTem->id, 'momento' => Morbilidade::AOS_30_DIAS]);
    $contar = fn () => Morbilidade::where('momento', Morbilidade::AOS_30_DIAS)->count();

    $this->artisan('seguimentos:fechar-altas-concluidas')->assertSuccessful();
    expect($contar())->toBe(1); // simulação: não grava

    $this->artisan('seguimentos:fechar-altas-concluidas', ['--aplicar' => true])->assertSuccessful();

    $m = $bom->morbilidades()->where('momento', Morbilidade::AOS_30_DIAS)->first();
    expect($m)->not->toBeNull()
        ->and($m->data_registo->format('Y-m-d'))->toBe($bom->data_de_alta->copy()->addDays(30)->format('Y-m-d'))
        ->and($m->complicacoes()->pluck('complicacaos.id')->all())->toBe([Complicacao::SEM_COMPLICACOES])
        ->and($comComplicacao->morbilidades()->where('momento', Morbilidade::AOS_30_DIAS)->exists())->toBeFalse()
        ->and($pendente->morbilidades()->where('momento', Morbilidade::AOS_30_DIAS)->exists())->toBeFalse()
        ->and($falecido->morbilidades()->where('momento', Morbilidade::AOS_30_DIAS)->exists())->toBeFalse()
        ->and($contar())->toBe(2);

    $this->artisan('seguimentos:fechar-altas-concluidas', ['--aplicar' => true])->assertSuccessful();
    expect($contar())->toBe(2); // repetir não duplica
});
