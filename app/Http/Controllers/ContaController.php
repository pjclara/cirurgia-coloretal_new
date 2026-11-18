<?php

namespace App\Http\Controllers;

use App\Support\RegrasPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alteração obrigatória da password para contas que ainda usam a password inicial.
 */
class ContaController extends Controller
{
    public function editarPassword(): Response
    {
        return Inertia::render('conta/password');
    }

    public function atualizarPassword(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'password' => ['required', 'confirmed', RegrasPassword::regra()],
        ], RegrasPassword::mensagens());

        $utilizador = $request->user();

        if (Hash::check($dados['password'], $utilizador->password)) {
            throw ValidationException::withMessages([
                'password' => 'A nova password tem de ser diferente da atual.',
            ]);
        }

        $utilizador->forceFill([
            'password' => $dados['password'],
            'must_change_password' => false,
        ])->save();

        return redirect(config('clinico.inicio'))->with('sucesso', 'Password alterada.');
    }
}
