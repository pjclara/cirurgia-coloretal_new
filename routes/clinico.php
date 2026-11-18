<?php

use App\Http\Controllers\Admin\PerfilController;
use App\Http\Controllers\Admin\UtilizadorController;
use App\Http\Controllers\AgendamentoController;
use App\Http\Controllers\AnaliseController;
use App\Http\Controllers\CancroRetalAnaliseController;
use App\Http\Controllers\CirurgiaController;
use App\Http\Controllers\ContaController;
use App\Http\Controllers\InternamentoController;
use App\Http\Controllers\QuadroController;
use App\Http\Controllers\ReferenciacaoAnaliseController;
use App\Http\Controllers\ReferenciacaoController;
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
    Route::get('dashboard', AnaliseController::class)->name('dashboard');
    Route::get('quadro/imprimir', [QuadroController::class, 'imprimir'])->name('quadro.imprimir');
    Route::get('quadro/novo', [QuadroController::class, 'novo'])->middleware('can:create internamento')->name('quadro.novo');
    Route::get('quadro/novo-utente', [QuadroController::class, 'novoUtente'])->middleware('can:create internamento')->name('quadro.novo-utente');
    Route::get('quadro/cirurgias/{bloco}', [QuadroController::class, 'editarCirurgia'])->whereNumber('bloco')->name('quadro.cirurgia.editar');
    Route::get('quadro/{internamento}/cirurgias/nova', [QuadroController::class, 'novaCirurgia'])->whereNumber('internamento')->middleware('can:create internamento')->name('quadro.cirurgia.nova');
    Route::get('quadro/{internamento}', [QuadroController::class, 'editar'])->whereNumber('internamento')->name('quadro.editar');

    Route::get('utentes', [UtenteController::class, 'index'])->name('utentes.index');
    Route::get('utentes/pesquisa', [UtenteController::class, 'pesquisa'])->name('utentes.pesquisa');

    Route::get('internamentos', [InternamentoController::class, 'index'])->name('internamentos.index');
    Route::get('internamentos/seguimentos', [InternamentoController::class, 'seguimentos'])->name('internamentos.seguimentos');
});

// Análise do cancro retal: doentes referenciados ao centro de referência, com nome e processo.
Route::middleware(['can:show internamento', 'can:show referenciacao'])->group(function () {
    Route::get('analise/cancro-retal', CancroRetalAnaliseController::class)->name('analise.cancro-retal');
    Route::get('analise/cancro-retal/qualidade', [CancroRetalAnaliseController::class, 'qualidade'])->name('analise.cancro-retal.qualidade');
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
    Route::get('internamentos/{internamento}/editar', [InternamentoController::class, 'editarNaLista'])->whereNumber('internamento')->name('internamentos.editar-na-lista');
});

Route::middleware('can:update internamento')->group(function () {
    Route::get('utentes/{utente}/editar', [UtenteController::class, 'edit'])->whereNumber('utente')->name('utentes.edit');
    Route::put('utentes/{utente}', [UtenteController::class, 'update'])->whereNumber('utente')->name('utentes.update');
    Route::put('internamentos/{internamento}', [InternamentoController::class, 'update'])->whereNumber('internamento')->name('internamentos.update');
    Route::post('internamentos/{internamento}/seguimento', [InternamentoController::class, 'registarSeguimento'])->whereNumber('internamento')->name('internamentos.seguimento');
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

// Administração
Route::middleware('clinico.admin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('utilizadores', [UtilizadorController::class, 'index'])->name('utilizadores.index');
    Route::get('utilizadores/novo', [UtilizadorController::class, 'create'])->name('utilizadores.create');
    Route::post('utilizadores', [UtilizadorController::class, 'store'])->name('utilizadores.store');
    Route::get('utilizadores/{utilizador}', [UtilizadorController::class, 'edit'])->whereNumber('utilizador')->name('utilizadores.edit');
    Route::put('utilizadores/{utilizador}', [UtilizadorController::class, 'update'])->whereNumber('utilizador')->name('utilizadores.update');

    // Perfis e permissões: só o superAdmin.
    Route::middleware('clinico.superadmin')->group(function () {
        Route::get('perfis', [PerfilController::class, 'index'])->name('perfis.index');
        Route::put('perfis', [PerfilController::class, 'update'])->name('perfis.update');
    });
});

// Agendamento cirúrgico
Route::prefix('agendamento')->name('agendamento.')->group(function () {
    Route::middleware('can:show agendamento')->group(function () {
        Route::get('/', [AgendamentoController::class, 'agenda'])->name('agenda');
        Route::get('mapa', [AgendamentoController::class, 'mapa'])->name('mapa');
        Route::get('lista-de-espera', [AgendamentoController::class, 'listaDeEspera'])->name('espera');
        Route::get('lista-de-espera/{espera}', [AgendamentoController::class, 'editarInscricao'])->whereNumber('espera')->name('espera.edit');
        Route::get('{agendamento}', [AgendamentoController::class, 'editarAgendamento'])->whereNumber('agendamento')->name('edit');
    });
    Route::middleware('can:create agendamento')->group(function () {
        Route::get('utentes/{utente}/inscrever', [AgendamentoController::class, 'criarInscricao'])->whereNumber('utente')->name('espera.create');
        Route::post('utentes/{utente}/inscrever', [AgendamentoController::class, 'guardarInscricao'])->whereNumber('utente')->name('espera.store');
        Route::get('lista-de-espera/{espera}/agendar', [AgendamentoController::class, 'criarAgendamento'])->whereNumber('espera')->name('create');
        Route::post('lista-de-espera/{espera}/agendar', [AgendamentoController::class, 'guardarAgendamento'])->whereNumber('espera')->name('store');
    });
    Route::middleware('can:update agendamento')->group(function () {
        Route::put('lista-de-espera/{espera}', [AgendamentoController::class, 'atualizarInscricao'])->whereNumber('espera')->name('espera.update');
        Route::post('lista-de-espera/{espera}/cancelar', [AgendamentoController::class, 'cancelarInscricao'])->whereNumber('espera')->name('espera.cancel');
        Route::patch('{agendamento}/mover', [AgendamentoController::class, 'moverAgendamento'])->whereNumber('agendamento')->name('move');
        Route::put('{agendamento}', [AgendamentoController::class, 'atualizarAgendamento'])->whereNumber('agendamento')->name('update');
    });
    Route::delete('{agendamento}', [AgendamentoController::class, 'apagarAgendamento'])->whereNumber('agendamento')->middleware('can:delete agendamento')->name('destroy');
});

// Referenciação ao centro de referência
Route::prefix('referenciacao')->name('referenciacao.')->group(function () {
    Route::middleware('can:show referenciacao')->group(function () {
        Route::get('/', [ReferenciacaoController::class, 'index'])->name('index');
        Route::get('analise', ReferenciacaoAnaliseController::class)->name('analise');
        Route::get('{referenciacao}', [ReferenciacaoController::class, 'editar'])->whereNumber('referenciacao')->name('edit');
    });
    Route::middleware('can:create referenciacao')->group(function () {
        Route::get('utentes/{utente}/nova', [ReferenciacaoController::class, 'criar'])->whereNumber('utente')->name('create');
        Route::post('utentes/{utente}', [ReferenciacaoController::class, 'guardar'])->whereNumber('utente')->name('store');
        Route::post('utentes/{utente}/sinalizar', [ReferenciacaoController::class, 'sinalizar'])->whereNumber('utente')->name('sinalizar');
    });
    Route::middleware('can:update referenciacao')->group(function () {
        Route::put('{referenciacao}', [ReferenciacaoController::class, 'atualizar'])->whereNumber('referenciacao')->name('update');
        Route::delete('{referenciacao}', [ReferenciacaoController::class, 'apagar'])->whereNumber('referenciacao')->name('destroy');
    });
});
