<?php

use App\Models\Internamento;
use App\Models\Morbilidade;

function csvDeInternamentos(array $internamentos): string
{
    $caminho = tempnam(sys_get_temp_dir(), 'morbi').'.csv';
    $linhas = ["numero_processo,data_de_entrada"];
    foreach ($internamentos as $i) {
        $linhas[] = $i->utente->numero_processo.','.$i->data_de_entrada->format('Y-m-d');
    }
    file_put_contents($caminho, implode("\n", $linhas)."\n");

    return $caminho;
}

function internamentoParaSeguimento(object $t, int $diasDeAlta, array $extra = []): Internamento
{
    $i = novoInternamento(novoUtente(), $t->utilizador, ['data_de_entrada' => now()->subDays($diasDeAlta + 5)->format('Y-m-d')]);
    internamentoOperado($t, $i);
    $i->forceFill($extra + [
        'data_de_alta' => now()->subDays($diasDeAlta)->format('Y-m-d'),
        'destino_id' => DB::table('destinos')->where('id', '<>', Internamento::DESTINO_FALECIDO)->value('id'),
    ])->save();

    return $i->load('utente');
}

beforeEach(fn () => $this->utilizador = utilizadorClinico());

test('regista sem complicações aos 30 dias só onde é seguro e não repete', function () {
    $bom = internamentoParaSeguimento($this, 60);
    $recente = internamentoParaSeguimento($this, 10);
    $falecido = internamentoParaSeguimento($this, 60, ['destino_id' => Internamento::DESTINO_FALECIDO]);
    $jaTem = internamentoParaSeguimento($this, 60);
    Morbilidade::create(['internamento_id' => $jaTem->id, 'momento' => Morbilidade::AOS_30_DIAS]);
    $ficheiro = csvDeInternamentos([$bom, $recente, $falecido, $jaTem]);

    // Simulação: não grava.
    $this->artisan('seguimentos:importar-sem-complicacoes', ['ficheiro' => $ficheiro])->assertSuccessful();
    expect(Morbilidade::where('momento', Morbilidade::AOS_30_DIAS)->count())->toBe(1);

    $this->artisan('seguimentos:importar-sem-complicacoes', ['ficheiro' => $ficheiro, '--aplicar' => true])->assertSuccessful();

    $m = $bom->morbilidades()->where('momento', Morbilidade::AOS_30_DIAS)->first();
    expect($m)->not->toBeNull()
        ->and($m->data_registo->format('Y-m-d'))->toBe($bom->data_de_alta->copy()->addDays(30)->format('Y-m-d'))
        ->and($m->complicacoes()->pluck('complicacaos.id')->all())->toBe([1])
        ->and($recente->morbilidades()->where('momento', Morbilidade::AOS_30_DIAS)->exists())->toBeFalse()
        ->and($falecido->morbilidades()->where('momento', Morbilidade::AOS_30_DIAS)->exists())->toBeFalse();

    // Repetir não duplica.
    $this->artisan('seguimentos:importar-sem-complicacoes', ['ficheiro' => $ficheiro, '--aplicar' => true])->assertSuccessful();
    expect(Morbilidade::where('momento', Morbilidade::AOS_30_DIAS)->count())->toBe(2);
});
