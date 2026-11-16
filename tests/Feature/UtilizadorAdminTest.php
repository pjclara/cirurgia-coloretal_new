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
