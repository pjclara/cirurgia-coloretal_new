<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function utilizadorComPerfil(string $perfil): User
{
    Role::findOrCreate($perfil, 'web');
    $u = User::factory()->create(['activo' => true]);
    $u->assignRole($perfil);

    return $u;
}

beforeEach(function () {
    foreach (['show internamento', 'create internamento', 'show referenciacao', 'create referenciacao', 'update referenciacao', 'show agendamento'] as $p) {
        Permission::findOrCreate($p, 'web');
    }
    $this->perfil = Role::findOrCreate('perfil de teste', 'web');
    $this->perfil->syncPermissions(['show internamento']);
});

test('só o superAdmin vê a página de perfis e permissões', function () {
    $this->actingAs(utilizadorComPerfil('admin'))->get('/admin/perfis')->assertForbidden();
    $this->actingAs(utilizadorClinico())->get('/admin/perfis')->assertForbidden();
    $this->actingAs(utilizadorComPerfil('superAdmin'))->get('/admin/perfis')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('admin/perfis')->has('grupos', 3)->has('perfis'));
});

test('o superAdmin altera as permissões de um perfil, com «ver» implícito e auditoria', function () {
    $super = utilizadorComPerfil('superAdmin');
    $semAuditorias = DB::table('audits')->where('auditable_type', (new Role)->getMorphClass())->count();

    $this->actingAs($super)->put('/admin/perfis', ['perfis' => ['perfil de teste' => ['update referenciacao', 'show internamento']]])
        ->assertSessionHasNoErrors();

    // «ver referenciação» junta-se por implicação, e o que já tinha mantém-se se foi pedido.
    expect($this->perfil->fresh()->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['show internamento', 'show referenciacao', 'update referenciacao']);
    expect(DB::table('audits')->where('auditable_type', (new Role)->getMorphClass())->count())->toBe($semAuditorias + 1);

    // Tirar «ver» tira o resto da área só se não for pedido: o servidor volta a acrescentá-lo.
    $this->actingAs($super)->put('/admin/perfis', ['perfis' => ['perfil de teste' => ['create referenciacao']]]);
    expect($this->perfil->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['create referenciacao', 'show referenciacao']);
});

test('os perfis de administração e permissões inexistentes não se alteram', function () {
    $super = utilizadorComPerfil('superAdmin');
    $admin = Role::findOrCreate('admin', 'web');
    $admin->syncPermissions(['show internamento']);

    $this->actingAs($super)->put('/admin/perfis', ['perfis' => ['admin' => [], 'superAdmin' => []]])->assertSessionHasNoErrors();
    expect($admin->fresh()->permissions->pluck('name')->all())->toBe(['show internamento']);

    $this->actingAs($super)->put('/admin/perfis', ['perfis' => ['perfil de teste' => ['permissao que nao existe']]])->assertSessionHasErrors();
    $this->actingAs(utilizadorComPerfil('admin'))->put('/admin/perfis', ['perfis' => ['perfil de teste' => ['show agendamento']]])->assertForbidden();
});
