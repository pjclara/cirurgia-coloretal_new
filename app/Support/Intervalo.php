<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Intervalo por trimestres lido do pedido, para as páginas de análise. */
class Intervalo
{
    /** "2023 T2" a partir de uma data. */
    public static function rotuloTrimestre(Carbon $data): string
    {
        return $data->format('Y').' T'.$data->quarter;
    }

    /**
     * Intervalo por trimestres, lido de `de` e `ate` ("2023 T1"). Os trimestres disponíveis vão do da `$primeiraData`
     * ao em curso. Por omissão, o último trimestre completo (o em curso só se for o único); valores inválidos ignoram-se
     * e, se invertidos, trocam. `desde`/`fim` são os limites do intervalo e `anteriorDesde` o início do período anterior,
     * com o mesmo número de trimestres.
     *
     * @return array{trimestres: list<string>, de: ?string, ate: ?string, desde: ?Carbon, fim: ?Carbon, anteriorDesde: ?Carbon}
     */
    public static function trimestres(Request $request, ?string $primeiraData, Carbon $hoje): array
    {
        $trimestres = [];
        if ($primeiraData) {
            $t = Carbon::parse($primeiraData)->firstOfQuarter()->startOfDay();
            for (; $t->lte($hoje); $t->addMonthsNoOverflow(3)) {
                $trimestres[] = self::rotuloTrimestre($t);
            }
        }
        if ($trimestres === []) {
            return ['trimestres' => [], 'de' => null, 'ate' => null, 'desde' => null, 'fim' => null, 'anteriorDesde' => null];
        }

        $ultimo = count($trimestres) > 1 ? $trimestres[count($trimestres) - 2] : $trimestres[0];
        $de = in_array($request->query('de'), $trimestres, true) ? $request->query('de') : $ultimo;
        $ate = in_array($request->query('ate'), $trimestres, true) ? $request->query('ate') : $ultimo;
        if ($de > $ate) {
            [$de, $ate] = [$ate, $de];
        }

        $inicio = fn (string $rotulo) => Carbon::create((int) substr($rotulo, 0, 4), ((int) substr($rotulo, -1) - 1) * 3 + 1, 1)->startOfDay();
        $desde = $inicio($de);
        $fim = $inicio($ate)->addMonthsNoOverflow(3)->subDay()->endOfDay();
        $n = array_search($ate, $trimestres, true) - array_search($de, $trimestres, true) + 1;

        return ['trimestres' => $trimestres, 'de' => $de, 'ate' => $ate, 'desde' => $desde, 'fim' => $fim, 'anteriorDesde' => $desde->copy()->subMonthsNoOverflow(3 * $n)];
    }
}
