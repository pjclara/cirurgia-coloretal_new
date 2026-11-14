<?php

namespace App\Models;

use App\Models\Concerns\RegistaAuditoria;
use App\Models\Concerns\RegistaAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Inscrição de um utente em lista de espera cirúrgica. */
class ListaDeEspera extends Model
{
    use RegistaAuditoria, RegistaAutor, SoftDeletes;

    protected $table = 'lista_de_esperas';

    /** Mesmos códigos de estado_de_agendamentos. */
    public const ESTADO_PROPOSTO = 1;

    public const ESTADO_PENDENTE = 2;

    public const ESTADO_AGENDADO = 3;

    public const ESTADO_CANCELADO = 4;

    public const PRIORIDADES = [1 => 'Normal', 2 => 'Prioritária', 3 => 'Prioridade 3', 99 => 'Não definida'];

    protected $fillable = ['prioridade_id', 'utente_id', 'data_de_lista', 'estado_lista_espera', 'comentarios', 'responsavel_id'];

    protected function casts(): array
    {
        return ['data_de_lista' => 'date'];
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(Utente::class)->withTrashed();
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function intervencoes(): BelongsToMany
    {
        return $this->belongsToMany(Intervencao::class, 'intervencao_lista_de_espera', 'lista_de_espera_id', 'intervencao_id');
    }

    public function diagnosticos(): BelongsToMany
    {
        return $this->belongsToMany(Diagnostico::class, 'diagnostico_lista_de_espera', 'lista_de_espera_id', 'diagnostico_id');
    }

    public function agendamentos(): HasMany
    {
        return $this->hasMany(Agendamento::class, 'lista_de_espera_id');
    }
}
