<?php

use App\Models\User;

function utilizadorComEmail(string $email, array $extra = []): User
{
    return User::factory()->create($extra + ['email' => $email, 'activo' => true, 'password' => bcrypt('segredo-123')]);
}

test('entra-se com o nome antes do @', function () {
    $utilizador = utilizadorComEmail('maria.exemplo@hospital.pt');

    $this->post('/login', ['email' => 'maria.exemplo', 'password' => 'segredo-123'])->assertRedirect();

    $this->assertAuthenticatedAs($utilizador);
});

test('o email completo e as maiúsculas também funcionam', function () {
    $utilizador = utilizadorComEmail('joao.exemplo@hospital.pt');

    $this->post('/login', ['email' => 'joao.exemplo@hospital.pt', 'password' => 'segredo-123'])->assertRedirect();
    $this->assertAuthenticatedAs($utilizador);
    auth()->logout();

    $this->post('/login', ['email' => 'JOAO.Exemplo', 'password' => 'segredo-123'])->assertRedirect();
    $this->assertAuthenticatedAs($utilizador);
});

test('com a palavra-passe errada, um nome inexistente ou um utilizador inativo não se entra', function () {
    utilizadorComEmail('rita.exemplo@hospital.pt');
    utilizadorComEmail('inativo.exemplo@hospital.pt', ['activo' => false]);

    foreach ([['rita.exemplo', 'errada'], ['ninguem', 'segredo-123'], ['inativo.exemplo', 'segredo-123']] as [$nome, $senha]) {
        $this->post('/login', ['email' => $nome, 'password' => $senha])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
});

test('se o nome for ambíguo não se entra por ele, só pelo email completo', function () {
    utilizadorComEmail('ana@hospital.pt');
    $outra = utilizadorComEmail('ana@outro.pt');

    $this->post('/login', ['email' => 'ana', 'password' => 'segredo-123'])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post('/login', ['email' => 'ana@outro.pt', 'password' => 'segredo-123'])->assertRedirect();
    $this->assertAuthenticatedAs($outra);
});

test('um email mal escrito continua a ser recusado e os carateres especiais não servem de padrão', function () {
    utilizadorComEmail('pedro.exemplo@hospital.pt');

    $this->post('/login', ['email' => 'pedro@@', 'password' => 'segredo-123'])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => '%', 'password' => 'segredo-123'])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => 'pedro_exemplo', 'password' => 'segredo-123'])->assertSessionHasErrors('email');
    $this->assertGuest();
});
