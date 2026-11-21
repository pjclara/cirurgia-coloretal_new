<?php

use App\Models\Internamento;
use Spatie\Permission\Models\Role;

test('só o responsável pelo internamento e os administradores o podem editar', function () {
    $responsavel = utilizadorClinico();
    $outro = utilizadorClinico();
    $admin = utilizadorClinico();
    Role::findOrCreate('admin', 'web');
    $admin->assignRole('admin');
    $internamento = novoInternamento(novoUtente(), $responsavel);

    $podeEditar = fn ($utilizador) => $this->actingAs($utilizador)->get("/internamentos/{$internamento->id}");

    foreach ([[$responsavel, true], [$admin, true], [$outro, false]] as [$utilizador, $esperado]) {
        $podeEditar($utilizador)->assertInertia(fn ($p) => $p->where('podeEditar', $esperado));
    }

    // O servidor também recusa a gravação a quem não é o responsável.
    $this->actingAs($outro)->put("/internamentos/{$internamento->id}", ['motivo_internamento' => 'Alterado'])->assertForbidden();
    expect(Internamento::find($internamento->id)->motivo_internamento)->toBe('Motivo de teste');
});

test('quem tem a permissão de editar internamentos de outros pode editá-los, exceto se estiverem bloqueados', function () {
    $responsavel = utilizadorClinico();
    $interno = utilizadorClinico();
    \Spatie\Permission\Models\Permission::findOrCreate('update internamento de outros', 'web');
    $interno->givePermissionTo('update internamento de outros');
    $internamento = novoInternamento(novoUtente(), $responsavel);

    $this->actingAs($interno)->get("/internamentos/{$internamento->id}")->assertInertia(fn ($p) => $p->where('podeEditar', true));
    // A gravação não é recusada por permissão (os erros que restam são só de validação do formulário).
    $this->actingAs($interno)->put("/internamentos/{$internamento->id}", ['motivo_internamento' => 'Alterado'])->assertStatus(302);
    $this->actingAs($interno)->post("/internamentos/{$internamento->id}/cirurgias", [])->assertStatus(302);

    $internamento->forceFill(['bloquear_tabela' => true])->save();
    $this->actingAs($interno)->get("/internamentos/{$internamento->id}")->assertInertia(fn ($p) => $p->where('podeEditar', false));
});
