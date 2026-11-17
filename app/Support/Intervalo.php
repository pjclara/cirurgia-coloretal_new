<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Intervalo de datas (`de` e `ate`, Y-m-d) lido do pedido; valores inválidos ignoram-se e, se invertidos, trocam. */
class Intervalo
{
    /** @return array{0: string, 1: string} '' quando não há limite. */
    public static function datas(Request $request): array
    {
        $ler = fn (string $chave) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($chave)) && strtotime($request->query($chave)) !== false
            ? $request->query($chave) : '';
        [$de, $ate] = [$ler('de'), $ler('ate')];

        return $de !== '' && $ate !== '' && $de > $ate ? [$ate, $de] : [$de, $ate];
    }
}
