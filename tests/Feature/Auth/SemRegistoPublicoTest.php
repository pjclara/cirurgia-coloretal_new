<?php

test('não há registo público nem recuperação de palavra-passe por email', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password'])->assertNotFound();
    $this->get('/forgot-password')->assertNotFound();
    $this->post('/forgot-password', ['email' => 'x@example.com'])->assertNotFound();
    $this->get('/reset-password/abc')->assertNotFound();
    $this->post('/reset-password', [])->assertNotFound();

    $this->assertGuest();
});

test('o ecrã de login continua a funcionar e já não indica registo nem recuperação', function () {
    $this->get('/login')->assertOk()->assertInertia(fn ($p) => $p->component('auth/login')->missing('canResetPassword'));
});
