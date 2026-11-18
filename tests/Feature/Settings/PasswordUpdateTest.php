<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('password can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/settings/password')
        ->put('/settings/password', [
            'current_password' => 'password',
            'password' => 'NovaPassword123',
            'password_confirmation' => 'NovaPassword123',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/password');

    expect(Hash::check('NovaPassword123', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/settings/password')
        ->put('/settings/password', [
            'current_password' => 'wrong-password',
            'password' => 'NovaPassword123',
            'password_confirmation' => 'NovaPassword123',
        ]);

    $response
        ->assertSessionHasErrors('current_password')
        ->assertRedirect('/settings/password');
});

test('a mesma regra da troca obrigatória aplica-se e a troca limpa o aviso', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user)->from('/settings/password')->put('/settings/password', [
        'current_password' => 'password', 'password' => 'curta1', 'password_confirmation' => 'curta1',
    ])->assertSessionHasErrors('password');
    expect($user->fresh()->must_change_password)->toBeTruthy();

    $this->actingAs($user)->from('/settings/password')->put('/settings/password', [
        'current_password' => 'password', 'password' => 'NovaPassword123', 'password_confirmation' => 'NovaPassword123',
    ])->assertSessionHasNoErrors();
    expect($user->fresh()->must_change_password)->toBeFalsy();
});
