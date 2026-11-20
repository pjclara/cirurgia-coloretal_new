<?php

namespace App\Models;

use App\Models\Concerns\RegistaAuditoria;
use App\Models\Concerns\RegistaAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Dados adicionais (centro de referência) de uma cirurgia de ressecção: tabela bloco_operatorio_c_r_s. */
class BlocoOperatorioCr extends Model
{
    use RegistaAuditoria, RegistaAutor, SoftDeletes;

    protected $table = 'bloco_operatorio_c_r_s';

    protected $fillable = [
        'bloco_operatorio_id',
        'asa',
        'intencao_id',
        'paleativa_causa',
        'duracao',
        'estoma_de_protecao_id',
        'local_extracao_peca_id',
        'tipo_de_dreno_id',
        'resseccao_multi_orgao',
        'resseccao_multi_orgao_quais',
        'aderencia_id',
        'tipo_de_resseccao_id',
        'neoplasia_residual_id',
        'perdas_hematica_id',
        'transfusao_intra_operatoria',
        'unidades_globulos',
        'protector_de_parede',
        'complicacoes',
        'complicacoes_quais',
    ];
}
