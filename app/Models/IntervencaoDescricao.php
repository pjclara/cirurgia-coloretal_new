<?php

namespace App\Models;

use App\Models\Concerns\RegistaAuditoria;
use App\Models\Concerns\RegistaAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Descrição de uma intervenção da cirurgia (anastomose, reconstrução, peça operatória). `intervencao_id` é o id da
 * linha de `bloco_operatorio_intervencao`, não o da intervenção do catálogo.
 */
class IntervencaoDescricao extends Model
{
    use RegistaAuditoria, RegistaAutor, SoftDeletes;

    protected $table = 'intervencao_descricaos';

    protected $fillable = [
        'intervencao_id',
        'anastemose_modo_id',
        'anastemose_via_id',
        'anastemose_sentido_id',
        'tipo_de_reconstrucao_id',
        'reforco_anastemose',
        'localizacao_anastemose_id',
        'confirmacao_anastemose_id',
        'libertacao_angulo',
        'numero_cargas',
        'distancia_linha_pectinea_anastemose',
        'distancia_linha_pectinea_tumor',
        'qualidade_peca_operatoria_id',
        'comentarios',
    ];
}
