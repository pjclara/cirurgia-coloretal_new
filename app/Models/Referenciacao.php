<?php

namespace App\Models;

use App\Models\Concerns\RegistaAuditoria;
use App\Models\Concerns\RegistaAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** Referenciação de um utente ao centro de referência. */
class Referenciacao extends Model
{
    use RegistaAuditoria, RegistaAutor, SoftDeletes;

    protected $table = 'referenciacao_centro_de_referencias';

    protected $fillable = [
        'data_de_diagnostico',
        'data_de_referenciacao',
        'origem_id',
        'data_de_entrada',
        'data_de_saida',
        'destino_id',
        'responsavel_id',
        'comentarios',
    ];

    protected function casts(): array
    {
        return [
            'data_de_diagnostico' => 'date',
            'data_de_referenciacao' => 'date',
            'data_de_entrada' => 'date',
            'data_de_saida' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Referenciacao $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(Utente::class)->withTrashed();
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    /** Diagnósticos da referenciação, com o modo de diagnóstico, a histologia e a data. */
    public function diagnosticos(): BelongsToMany
    {
        return $this->belongsToMany(Diagnostico::class, 'diagnostico_referenciacao', 'referenciacao_id', 'diagnostico_id')
            ->withPivot(['modo_id', 'histologia_id', 'data_diagnostico']);
    }

    /** Referenciado (ainda não entrou), No centro (entrou, sem saída) ou Saída registada. */
    public function situacao(): string
    {
        return $this->data_de_saida ? 'saida' : ($this->data_de_entrada ? 'no_centro' : 'referenciado');
    }
}
