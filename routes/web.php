<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// A raiz da aplicação leva sempre ao início de sessão (quem já tem sessão segue daí para o início).
Route::redirect('/', '/login')->name('home');

Route::middleware(['auth'])->group(function () {
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
