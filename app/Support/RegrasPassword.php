<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** Regra única da palavra-passe, usada na troca obrigatória e nas Definições. */
class RegrasPassword
{
    public static function regra(): Password
    {
        return Password::min(10)->letters()->numbers();
    }

    /** @return array<string, string> */
    public static function mensagens(string $campo = 'password'): array
    {
        return [
            "$campo.required" => 'Escreva a nova password.',
            "$campo.confirmed" => 'As duas passwords não coincidem.',
            "$campo.min" => 'A password tem de ter pelo menos :min caracteres.',
            "$campo.letters" => 'A password tem de incluir letras.',
            "$campo.numbers" => 'A password tem de incluir números.',
        ];
    }
}
