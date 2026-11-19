<?php

namespace App\Models;

use App\Models\Concerns\RegistaAuditoria;
use App\Models\Concerns\RegistaAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Uma cirurgia (tabela bloco_operatorios) realizada durante um internamento. */
class BlocoOperatorio extends Model
{
    use RegistaAuditoria, RegistaAutor, SoftDeletes;

    protected $table = 'bloco_operatorios';

    protected $fillable = [
        'internamento_id',
        'data_de_inicio',
        'tipo_de_cirurgia_id',
        'tipo_de_abordagem_id',
        'causa_de_conversao',
        'resseccao_de_orgao',
        're_intervencao_nao_programada_id',
        'comentarios',
    ];

    protected static function booted(): void
    {
        // Um internamento que passa a ter cirurgia recebe os diagnósticos CR da referenciação do utente, sem ninguém ter de o fazer.
        static::created(function (BlocoOperatorio $bloco) {
            $internamento = Internamento::withTrashed()->find($bloco->internamento_id);
            if ($internamento) {
                \App\Support\DiagnosticosCr::levarParaInternamentos($internamento->utente_id, $internamento->id);
            }
        });
    }

    protected function casts(): array
    {
        return ['data_de_inicio' => 'date'];
    }

    public function internamento(): BelongsTo
    {
        return $this->belongsTo(Internamento::class)->withTrashed();
    }

    public function intervencoes(): BelongsToMany
    {
        return $this->belongsToMany(Intervencao::class, 'bloco_operatorio_intervencao', 'bloco_operatorio_id', 'intervencao_id');
    }
}
