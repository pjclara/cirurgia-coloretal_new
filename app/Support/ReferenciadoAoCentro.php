<?php

namespace App\Support;

/** Condição SQL «o utente já estava referenciado ao centro de referência nessa data», partilhada pelas análises e pelos seguimentos. */
class ReferenciadoAoCentro
{
    /**
     * @param  string  $utente  coluna com o id do utente (ex.: `i.utente_id`)
     * @param  string  $data  coluna com a data limite (ex.: `i.data_de_entrada`)
     */
    public static function existe(string $utente, string $data): string
    {
        return "exists (select 1 from referenciacao_centro_de_referencias rr
            where rr.utente_id = $utente and rr.deleted_at is null and rr.data_de_referenciacao <= $data)";
    }
}
