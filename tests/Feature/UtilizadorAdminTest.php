<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

test('um administrador consegue editar os dados de outra conta', function () {
    Role::findOrCreate('admin');
    $admin = User::factory()->create(['activo' => true]);
    $admin->assignRole('admin');
    $alvo = User::factory()->create(['activo' => true, 'name' => 'Nome Antigo']);

    $this->actingAs($admin)->put("/admin/utilizadores/{$alvo->id}", [
        'name' => 'Nome Novo',
        'email' => 'novo@example.com',
        'username' => 'novo',
        'abrev' => 'NN',
        'equipa' => 'A',
        'activo' => true,
        'roles' => [],
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.utilizadores.index'));

    expect($alvo->fresh()->only(['name', 'email', 'abrev', 'equipa']))
        ->toBe(['name' => 'Nome Novo', 'email' => 'novo@example.com', 'abrev' => 'NN', 'equipa' => 'A']);
});

test('a lista permite ativar e desativar contas, menos a própria', function () {
    $admin = User::factory()->create(['activo' => true]);
    $admin->assignRole('superAdmin');
    $outro = User::factory()->create(['activo' => true]);

    $this->actingAs($admin)->put("/admin/utilizadores/{$outro->id}/estado", ['activo' => false])->assertSessionHas('sucesso', 'Conta desativada.');
    expect((bool) $outro->fresh()->activo)->toBeFalse();

    $this->actingAs($admin)->put("/admin/utilizadores/{$outro->id}/estado", ['activo' => true])->assertSessionHas('sucesso', 'Conta ativada.');
    expect((bool) $outro->fresh()->activo)->toBeTrue();

    $this->actingAs($admin)->put("/admin/utilizadores/{$admin->id}/estado", ['activo' => false])->assertStatus(422);
    expect((bool) $admin->fresh()->activo)->toBeTrue();
});
