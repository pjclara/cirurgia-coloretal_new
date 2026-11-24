<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Resolução de uma complicação (o que foi feito para a tratar), com o grau de Clavien-Dindo que implica. */
class ResolucaoComplicacao extends Model
{
    protected $table = 'resolucao_complicacaos';

    public $timestamps = false;

    protected $guarded = ['id'];
}
