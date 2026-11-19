<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Diagnostico extends Model
{
    /** Registo «Outro Colorectal» da tabela `diagnosticos`: o diagnóstico de uma referenciação que não é de nenhum dos do centro de referência. */
    public const OUTRO = 15;

    protected $table = 'diagnosticos';

    public $timestamps = false;

    protected $guarded = ['id'];
}
