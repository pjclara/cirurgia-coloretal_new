<?php

use App\Http\Controllers\CirurgiaController;
use App\Http\Controllers\ContaController;
use App\Http\Controllers\InternamentoController;
use App\Http\Controllers\QuadroController;
use App\Http\Controllers\UtenteController;
use Illuminate\Support\Facades\Route;

/*
| Rotas da aplicação clínica.
| Carregadas por App\Providers\ClinicoServiceProvider com os middleware
| web, auth, clinico.ativo e clinico.password.
|
| As permissões são as que já existem na base de dados (spatie/laravel-permission).
*/

Route::get('conta/password', [ContaController::class, 'editarPassword'])->name('conta.password.editar');
Route::put('conta/password', [ContaController::class, 'atualizarPassword'])->name('conta.password.atualizar');

Route::middleware('can:show internamento')->group(function () {
    Route::get('quadro', QuadroController::class)->name('quadro');

    Route::get('utentes', [UtenteController::class, 'index'])->name('utentes.index');
    Route::get('utentes/pesquisa', [UtenteController::class, 'pesquisa'])->name('utentes.pesquisa');

    Route::get('internamentos', [InternamentoController::class, 'index'])->name('internamentos.index');
});

Route::middleware('can:create internamento')->group(function () {
    Route::get('utentes/novo', [UtenteController::class, 'create'])->name('utentes.create');
    Route::post('utentes', [UtenteController::class, 'store'])->name('utentes.store');

    Route::get('internamentos/novo', [InternamentoController::class, 'create'])->name('internamentos.create');
    Route::post('internamentos', [InternamentoController::class, 'store'])->name('internamentos.store');
});

Route::middleware('can:show internamento')->group(function () {
    Route::get('utentes/{utente}', [UtenteController::class, 'show'])->whereNumber('utente')->name('utentes.show');
    Route::get('internamentos/{internamento}', [InternamentoController::class, 'edit'])->whereNumber('internamento')->name('internamentos.edit');
});

Route::middleware('can:update internamento')->group(function () {
    Route::get('utentes/{utente}/editar', [UtenteController::class, 'edit'])->whereNumber('utente')->name('utentes.edit');
    Route::put('utentes/{utente}', [UtenteController::class, 'update'])->whereNumber('utente')->name('utentes.update');
    Route::put('internamentos/{internamento}', [InternamentoController::class, 'update'])->whereNumber('internamento')->name('internamentos.update');
});

Route::delete('internamentos/{internamento}', [InternamentoController::class, 'destroy'])
    ->whereNumber('internamento')
    ->middleware('can:delete internamento')
    ->name('internamentos.destroy');

// Cirurgias (bloco operatório)
Route::middleware('can:show internamento')->group(function () {
    Route::get('utentes/{utente}/cirurgias', [CirurgiaController::class, 'porUtente'])->whereNumber('utente')->name('utentes.cirurgias');
    Route::get('cirurgias/{bloco}', [CirurgiaController::class, 'edit'])->whereNumber('bloco')->name('cirurgias.edit');
});
Route::middleware('can:create internamento')->group(function () {
    Route::get('internamentos/{internamento}/cirurgias/nova', [CirurgiaController::class, 'create'])->whereNumber('internamento')->name('cirurgias.create');
    Route::post('internamentos/{internamento}/cirurgias', [CirurgiaController::class, 'store'])->whereNumber('internamento')->name('cirurgias.store');
});
Route::middleware('can:update internamento')->group(function () {
    Route::put('cirurgias/{bloco}', [CirurgiaController::class, 'update'])->whereNumber('bloco')->name('cirurgias.update');
});
Route::delete('cirurgias/{bloco}', [CirurgiaController::class, 'destroy'])->whereNumber('bloco')->middleware('can:delete internamento')->name('cirurgias.destroy');
