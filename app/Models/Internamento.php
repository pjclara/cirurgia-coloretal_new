<?php

namespace App\Models;

use App\Models\Concerns\RegistaAuditoria;
use App\Models\Concerns\RegistaAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Internamento extends Model
{
    use RegistaAuditoria, RegistaAutor, SoftDeletes;

    public const ALTA_PENDENTE = 1;

    public const ALTA_CONCLUIDA = 2;

    public const DESTINO_FALECIDO = 6;

    public const CLAVIEN_GRAU_5 = 7;

    protected $table = 'internamentos';

    protected $fillable = [
        'utente_id',
        'cama',
        'localizacao_id',
        'data_de_entrada',
        'origem_do_internamento_id',
        'data_de_alta',
        'data_de_saida',
        'estado_da_alta_id',
        'responsavel_id',
        'motivo_internamento',
        'destino_id',
        'caso_social_id',
        'bloquear_tabela',
        'comentarios',
    ];

    protected function casts(): array
    {
        return [
            'data_de_entrada' => 'date',
            'data_de_alta' => 'date',
            'data_de_saida' => 'date',
            'bloquear_tabela' => 'boolean',
        ];
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(Utente::class)->withTrashed();
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    /** Só o responsável pelo internamento e os administradores o podem alterar; um registo bloqueado só os administradores. */
    public function bloqueadoPara(User $utilizador): bool
    {
        if ($utilizador->eAdministrador()) {
            return false;
        }

        return $this->bloquear_tabela || $this->responsavel_id !== $utilizador->id;
    }

    public function diagnosticos(): BelongsToMany
    {
        return $this->belongsToMany(Diagnostico::class, 'diagnostico_internamento', 'internamento_id', 'diagnostico_id');
    }

    public function morbilidades(): HasMany
    {
        return $this->hasMany(Morbilidade::class);
    }

    /**
     * Grau de Clavien-Dindo definitivo: só existe depois do seguimento aos 30 dias (que completa o registo da alta).
     * Quem faleceu com complicação não tem seguimento: vale o grau 5 da alta. Antes disso, null.
     */
    public function claviemMaisGrave(): ?int
    {
        $momento = (int) $this->destino_id === self::DESTINO_FALECIDO ? Morbilidade::NA_ALTA : Morbilidade::AOS_30_DIAS;
        $grau = $this->morbilidades->firstWhere('momento', $momento)?->clavien_dindo_id;

        return $grau && $grau !== Morbilidade::CLAVIEN_A_AGUARDAR ? (int) $grau : null;
    }

    /** Tem cirurgias registadas (não apagadas). */
    public function temCirurgias(): bool
    {
        return $this->exists && $this->getConnection()->table('bloco_operatorios')
            ->where('internamento_id', $this->getKey())->whereNull('deleted_at')->exists();
    }

    public function scopeInternados(Builder $query): Builder
    {
        return $query->whereNull('data_de_alta');
    }

    public function scopeComAlta(Builder $query): Builder
    {
        return $query->whereNotNull('data_de_alta');
    }
}
