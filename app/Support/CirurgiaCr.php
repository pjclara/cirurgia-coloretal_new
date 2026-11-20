<?php

namespace App\Support;

use App\Models\Internamento;
use Illuminate\Support\Facades\DB;

/** Quando uma cirurgia tem os dados do centro de referência (CR): doente referenciado e cirurgia de ressecção. */
class CirurgiaCr
{
    /**
     * Intervenções de ressecção colorretal (catálogo `intervencaos`): ressecção anterior do reto, sigmoidectomia,
     * hemicolectomias, colectomias, Hartmann, amputação abdomino-perineal, ressecções segmentares e reto-sigmoideia.
     */
    public const RESSECCOES = [1, 2, 3, 5, 11, 15, 18, 24, 46, 53, 54, 69, 75, 88, 103];

    /** Data da primeira referenciação do utente ao CR (Y-m-d), ou null se nunca foi referenciado. */
    public static function referenciadoDesde(int $utenteId): ?string
    {
        $data = DB::table('referenciacao_centro_de_referencias')
            ->where('utente_id', $utenteId)->whereNull('deleted_at')->min('data_de_referenciacao');

        return $data ? substr((string) $data, 0, 10) : null;
    }

    /**
     * @param  array<int, int|string>  $intervencoes  ids do catálogo escolhidos na cirurgia
     */
    public static function aplica(Internamento $internamento, string $dataDaCirurgia, array $intervencoes): bool
    {
        $desde = self::referenciadoDesde($internamento->utente_id);

        return $desde !== null && $desde <= $dataDaCirurgia && array_intersect(array_map('intval', $intervencoes), self::RESSECCOES) !== [];
    }
}
