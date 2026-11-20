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
