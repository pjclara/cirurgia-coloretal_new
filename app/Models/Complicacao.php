<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Complicacao extends Model
{
    /** Registo "Sem complicações" da tabela `complicacaos`. */
    public const SEM_COMPLICACOES = 1;

    protected $table = 'complicacaos';

    public $timestamps = false;

    protected $guarded = ['id'];
}
