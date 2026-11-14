<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Preenche created_by_id / updated_by_id com o utilizador autenticado.
 */
trait RegistaAutor
{
    public static function bootRegistaAutor(): void
    {
        static::creating(function ($modelo) {
            if ($id = Auth::id()) {
                $modelo->created_by_id ??= $id;
                $modelo->updated_by_id = $id;
            }
        });

        static::updating(function ($modelo) {
            if ($id = Auth::id()) {
                $modelo->updated_by_id = $id;
            }
        });
    }
}
