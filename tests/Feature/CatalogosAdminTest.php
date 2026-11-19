<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function administrador(): User
{
    Role::findOrCreate('admin', 'web');
    $u = User::factory()->create(['activo' => true]);
    $u->assignRole('admin');

    return $u;
}

test('os catálogos de diagnósticos e intervenções são só para administradores', function () {
    $this->actingAs(utilizadorClinico())->get('/admin/catalogos/diagnosticos')->assertForbidden();
    $this->actingAs(utilizadorClinico())->post('/admin/catalogos/intervencoes', [])->assertForbidden();
    $this->actingAs(administrador())->get('/admin/catalogos/diagnosticos')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('admin/catalogo')->where('catalogo.chave', 'diagnosticos')->where('catalogo.temTipo', true)->has('grupos')->has('itens.data'));
    $this->actingAs(administrador())->get('/admin/catalogos/intervencoes')->assertInertia(fn (Assert $p) => $p->where('catalogo.temRessecao', true));
    $this->actingAs(administrador())->get('/admin/catalogos/outra-coisa')->assertNotFound();
});

test('cria, edita e apaga um diagnóstico, com nome único e auditoria', function () {
    $admin = administrador();
    DB::table('tipo_de_diagnosticos')->insertOrIgnore(['id' => 1, 'nome' => 'Medico-cirurgico']);   // a base de testes não traz esta tabela
    $grupo = DB::table('grupo_diagnosticos')->value('id');
    $tipo = DB::table('tipo_de_diagnosticos')->value('id');
    $corpo = ['nome' => ' Diagnóstico de Teste ', 'abrev' => 'DT', 'grupo_id' => $grupo, 'tipo_id' => $tipo, 'centro_de_referencia' => true];

    $this->actingAs($admin)->post('/admin/catalogos/diagnosticos', $corpo)->assertSessionHasNoErrors();
    $registo = DB::table('diagnosticos')->where('nome', 'Diagnóstico de Teste')->first();
    expect($registo)->not->toBeNull()->and((int) $registo->centro_de_referencia)->toBe(1);

    // Nome repetido: recusa; campos obrigatórios em falta: recusa.
    $this->actingAs($admin)->post('/admin/catalogos/diagnosticos', $corpo)->assertSessionHasErrors('nome');
    $this->actingAs($admin)->post('/admin/catalogos/diagnosticos', ['nome' => 'X'])->assertSessionHasErrors(['grupo_id', 'tipo_id']);

    $this->actingAs($admin)->put("/admin/catalogos/diagnosticos/{$registo->id}", ['nome' => 'Diagnóstico Editado', 'grupo_id' => $grupo, 'tipo_id' => $tipo, 'centro_de_referencia' => false])->assertSessionHasNoErrors();
    expect(DB::table('diagnosticos')->where('id', $registo->id)->value('nome'))->toBe('Diagnóstico Editado')
        ->and((int) DB::table('diagnosticos')->where('id', $registo->id)->value('centro_de_referencia'))->toBe(0);

    $this->actingAs($admin)->delete("/admin/catalogos/diagnosticos/{$registo->id}")->assertSessionHas('sucesso');
    expect(DB::table('diagnosticos')->where('id', $registo->id)->exists())->toBeFalse()
        ->and(DB::table('audits')->where('auditable_type', \App\Models\Diagnostico::class)->where('auditable_id', $registo->id)->count())->toBe(3);
});

test('um registo em uso ou de que a aplicação depende não se apaga', function () {
    $admin = administrador();
    $utilizador = utilizadorClinico();
    $emUso = (int) DB::table('diagnosticos')->where('centro_de_referencia', 0)->where('id', '<>', \App\Models\Diagnostico::OUTRO)->value('id');
    novoInternamento(novoUtente(), $utilizador, ['data_de_entrada' => now()->subDays(5)->format('Y-m-d')])->diagnosticos()->sync([$emUso]);

    $this->actingAs($admin)->delete("/admin/catalogos/diagnosticos/$emUso")->assertSessionHas('erro');
    $this->actingAs($admin)->delete('/admin/catalogos/diagnosticos/'.\App\Models\Diagnostico::OUTRO)->assertSessionHas('erro');
    $this->actingAs($admin)->delete('/admin/catalogos/intervencoes/'.\App\Http\Controllers\CancroRetalAnaliseController::RESSECCAO_ANTERIOR)->assertSessionHas('erro');
    expect(DB::table('diagnosticos')->where('id', $emUso)->exists())->toBeTrue();

    // A lista mostra quantas vezes está em uso (procura-se pelo nome: a lista é paginada).
    $nome = trim(DB::table('diagnosticos')->where('id', $emUso)->value('nome'));
    $this->actingAs($admin)->get('/admin/catalogos/diagnosticos?q='.urlencode($nome))->assertInertia(fn (Assert $p) => $p
        ->where('itens.data', fn ($d) => (collect($d)->firstWhere('id', $emUso)['usos'] ?? 0) >= 1));
});

test('as intervenções têm a marca de cirurgia de ressecção e filtram-se por grupo e CR', function () {
    $admin = administrador();
    $grupo = DB::table('grupo_intervencaos')->value('id');

    $this->actingAs($admin)->post('/admin/catalogos/intervencoes', ['nome' => 'Intervenção de Teste', 'grupo_id' => $grupo, 'centro_de_referencia' => true, 'cirurgia_de_ressecao' => true])->assertSessionHasNoErrors();
    $registo = DB::table('intervencaos')->where('nome', 'Intervenção de Teste')->first();
    expect((int) $registo->cirurgia_de_ressecao)->toBe(1)->and($registo->abrev)->toBeNull();

    $this->actingAs($admin)->get("/admin/catalogos/intervencoes?q=Interven%C3%A7%C3%A3o+de+Teste&cr=1&grupo=$grupo")->assertInertia(fn (Assert $p) => $p
        ->where('itens.total', 1)->where('itens.data.0.cirurgia_de_ressecao', true));
});
