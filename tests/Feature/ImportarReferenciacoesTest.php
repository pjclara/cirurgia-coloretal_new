<?php

use App\Models\Referenciacao;
use App\Models\User;
use App\Models\Utente;

function csvDeReferenciacoes(array $linhas): string
{
    $caminho = tempnam(sys_get_temp_dir(), 'ref').'.csv';
    $csv = ['Processo;Nome;Localização;Médico;Data'];
    foreach ($linhas as $l) {
        $csv[] = implode(';', $l);
    }
    file_put_contents($caminho, implode("\n", $csv)."\n");

    return $caminho;
}

test('importa a triagem: cria utentes e referenciações com médico e diagnóstico, e salta o que já existe', function () {
    $this->actingAs($admin = utilizadorClinico());
    $medico = User::factory()->create(['name' => 'Vera Pedro']);
    $existente = novoUtente();
    $jaSinalizado = novoUtente();
    $comData = novoUtente();
    foreach ([[$jaSinalizado, '2026-01-10', null], [$comData, '2026-02-02', '2026-03-01']] as [$u, $data, $saida]) {
        $r = new Referenciacao(['data_de_referenciacao' => $data, 'origem_id' => 6, 'responsavel_id' => $medico->id, 'data_de_saida' => $saida, 'destino_id' => $saida ? 1 : null]);
        $r->utente_id = $u->id;
        $r->created_by_id = $medico->id;
        $r->save();
    }
    $ontem = now()->subDay()->format('Y-m-d');
    $ficheiro = csvDeReferenciacoes([
        [$existente->numero_processo, 'Nome Existente', 'reto', 'Vera', '2026-03-05'],
        ['99200001', 'ana nova do teste', 'Sigmoide', 'Vera', '2026-03-06'],
        ['99200001', 'ana nova do teste', 'Sigmoide', 'Vera', '2026-03-07'],      // repetido
        [$jaSinalizado->numero_processo, 'Ja Sinalizado', 'reto', 'Vera', '2026-03-08'],
        [$comData->numero_processo, 'Mesma Data', 'reto', 'Vera', '2026-02-02'],    // já tem nessa data
        ['285757778', 'Sem Processo', 'reto', 'Vera', '2026-03-09'],                // n.º de utente (9 dígitos) desconhecido: cria a ficha sem processo
        ['', 'Sem Nada Nenhum', 'cego', 'Vera', '2026-03-11'],                      // nem processo nem utente: cria só com o nome
        ['99200002', 'Medico Desconhecido', 'reto', 'Zeca', '2026-03-10'],
        ['99200003', 'Data Futura', 'reto', 'Vera', now()->addDays(3)->format('Y-m-d')],
        ['99200004', 'Localizacao Estranha', '??', 'Vera', $ontem],
    ]);

    // Simulação: não grava.
    $this->artisan('referenciacoes:importar', ['ficheiro' => $ficheiro])->assertSuccessful();
    expect(Utente::where('numero_processo', 99200001)->exists())->toBeFalse();

    $this->artisan('referenciacoes:importar', ['ficheiro' => $ficheiro, '--autor' => $medico->id, '--aplicar' => true])->assertSuccessful();

    $r1 = Referenciacao::where('utente_id', $existente->id)->first();
    expect($r1)->not->toBeNull()
        ->and($r1->responsavel_id)->toBe($medico->id)
        ->and($r1->data_de_referenciacao->format('Y-m-d'))->toBe('2026-03-05')
        ->and((int) $r1->origem_id)->toBe(6)
        ->and($r1->comentarios)->toContain('reto')
        ->and($r1->diagnosticos()->pluck('diagnosticos.id')->all())->toBe([1]);

    $nova = Utente::where('numero_processo', 99200001)->first();
    expect($nova)->not->toBeNull()
        ->and(Referenciacao::where('utente_id', $nova->id)->count())->toBe(1)
        ->and(Referenciacao::where('utente_id', $nova->id)->first()->diagnosticos()->pluck('diagnosticos.id')->all())->toBe([16]);

    // Sem diagnóstico mapeado: cria na mesma, só com a localização nos comentários.
    $estranha = Utente::where('numero_processo', 99200004)->first();
    expect(Referenciacao::where('utente_id', $estranha->id)->first()->diagnosticos()->count())->toBe(0);

    // Sem n.º de processo: a ficha cria-se na mesma (com o n.º de utente, se tiver) e a referenciação avisa para a completar.
    $semProcesso = Utente::where('nome', 'like', 'Sem Processo%')->first();
    expect($semProcesso)->not->toBeNull()->and($semProcesso->numero_processo)->toBeNull()->and($semProcesso->numero_utente)->toBe(285757778)
        ->and(Referenciacao::where('utente_id', $semProcesso->id)->first()->comentarios)->toContain('completar na ficha');
    $semNada = Utente::where('nome', 'like', 'Sem Nada Nenhum%')->first();
    expect($semNada)->not->toBeNull()->and($semNada->numero_processo)->toBeNull()->and($semNada->numero_utente)->toBeNull()
        ->and(Referenciacao::where('utente_id', $semNada->id)->first()->diagnosticos()->pluck('diagnosticos.id')->all())->toBe([37]);

    // Saltados: já sinalizado, mesma data, médico desconhecido, data futura.
    expect(Referenciacao::where('utente_id', $jaSinalizado->id)->count())->toBe(1)
        ->and(Referenciacao::where('utente_id', $comData->id)->count())->toBe(1)
        ->and(Utente::where('numero_processo', 99200002)->exists())->toBeFalse()
        ->and(Utente::where('numero_processo', 99200003)->exists())->toBeFalse();

    // Repetir não duplica nem cria outra ficha (acha-se pelo n.º de utente ou pelo nome).
    $total = Referenciacao::count();
    $fichas = Utente::count();
    $this->artisan('referenciacoes:importar', ['ficheiro' => $ficheiro, '--autor' => $medico->id, '--aplicar' => true])->assertSuccessful();
    expect(Referenciacao::count())->toBe($total)->and(Utente::count())->toBe($fichas);
});

test('o leitor de folhas lê a segunda folha de um .xlsx', function () {
    $linhas = iterator_to_array(App\Support\LeitorDeFolha::linhas(base_path('excels/triagem_cruzada_consultas.xlsx'), 1), false);
    expect($linhas)->toHaveCount(24)->and($linhas[0])->toHaveKey('Nome');
})->skip(fn () => ! file_exists(base_path('excels/triagem_cruzada_consultas.xlsx')), 'ficheiro de exemplo ausente');

test('o administrador importa referenciações por Excel: carrega, vê a simulação e confirma', function () {
    Illuminate\Support\Facades\Storage::fake('local');
    $admin = User::factory()->create(['activo' => true]);
    $admin->assignRole('admin');
    User::factory()->create(['name' => 'Vera Pedro']);
    $ficheiro = csvDeReferenciacoes([
        ['99300001', 'Ana Nova', 'reto', 'Vera', '2026-03-05'],
        ['', 'Rui Sem Processo', 'reto', 'Vera', '2026-03-09'],
    ]);
    $csv = Illuminate\Http\UploadedFile::fake()->createWithContent('triagem.csv', file_get_contents($ficheiro));

    $this->actingAs(utilizadorClinico())->get('/admin/importar/referenciacoes')->assertForbidden();
    $this->actingAs($admin)->get('/admin/importar/outra-coisa')->assertNotFound();

    $this->actingAs($admin)->post('/admin/importar/referenciacoes', ['ficheiro' => $csv])->assertRedirect('/admin/importar/referenciacoes');
    $previa = session('previa');
    expect($previa['a_criar'])->toBe(2)->and($previa['novos_utentes'])->toBe(2)
        ->and($previa['saltados_detalhe'])->toHaveCount(0)->and($previa['amostra'][0]['diagnostico'])->toContain('Recto')
        ->and(Utente::where('numero_processo', 99300001)->exists())->toBeFalse();
    $this->actingAs($admin)->withSession(['previa' => $previa])->get('/admin/importar/referenciacoes')->assertInertia(fn ($p) => $p
        ->component('admin/importar')->where('tipo.chave', 'referenciacoes')->where('previa.a_criar', 2));

    $this->actingAs($admin)->post('/admin/importar/referenciacoes/confirmar', ['token' => $previa['token']])->assertSessionHas('sucesso');
    $utente = Utente::where('numero_processo', 99300001)->first();
    expect($utente)->not->toBeNull()
        ->and(Referenciacao::where('utente_id', $utente->id)->first()->created_by_id)->toBe($admin->id);
});

test('um utente sem n.º de processo aparece nas páginas sem rebentar', function () {
    $u = utilizadorClinico();
    $u->givePermissionTo(Spatie\Permission\Models\Permission::where('name', 'like', '%referenciacao%')->pluck('name')->all());
    $utente = Utente::create(['nome' => 'Sem Numero Algum']);
    $r = new Referenciacao(['data_de_referenciacao' => '2026-03-05', 'origem_id' => 6, 'responsavel_id' => $u->id]);
    $r->utente_id = $utente->id;
    $r->created_by_id = $u->id;
    $r->save();

    $this->actingAs($u)->get('/referenciacao')->assertOk();
    $this->actingAs($u)->get('/utentes?q=Sem+Numero')->assertOk();
    $this->actingAs($u)->get("/utentes/{$utente->id}")->assertOk();
    $this->actingAs($u)->get('/agendamento')->assertOk();
});

test('fora das importações o n.º de processo continua obrigatório, ao criar e ao editar uma ficha', function () {
    $u = utilizadorClinico();
    $ficha = ['nome' => 'Utente Sem Processo', 'data_nascimento' => '1950-01-01', 'sexo_id' => DB::table('sexos')->value('id'), 'concelho_id' => DB::table('concelhos')->value('id')];

    $this->actingAs($u)->post('/utentes', $ficha)->assertSessionHasErrors('numero_processo');
    expect(Utente::where('nome', 'Utente Sem Processo')->exists())->toBeFalse();

    // Uma ficha importada sem processo só se grava depois de o completar.
    $importada = Utente::create(['nome' => 'Importado Sem Processo']);
    $this->actingAs($u)->put("/utentes/{$importada->id}", $ficha)->assertSessionHasErrors('numero_processo');
    $this->actingAs($u)->put("/utentes/{$importada->id}", $ficha + ['numero_processo' => 99400001])->assertSessionHasNoErrors();
    expect($importada->fresh()->numero_processo)->toBe(99400001);
});
