<?php

it('a raiz da aplicação leva ao início de sessão', function () {
    $this->get('/')->assertRedirect('/login');
});
