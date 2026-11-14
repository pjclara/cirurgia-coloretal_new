<?php

namespace App\Models;

use App\Models\Concerns\RegistaAuditoria;
use App\Models\Concerns\RegistaAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Data marcada para uma cirurgia de um utente em lista de espera. */
class Agendamento extends Model
{
    use RegistaAuditoria, RegistaAutor, SoftDeletes;

    protected $table = 'agendamentos';

    protected $fillable = [
        'lista_de_espera_id',
        'start',
        'end',
        'responsavel_id',
        'tipo_de_agendamento_id',
        'local_de_agendamento_id',
        'sala_de_agendamento_id',
        'periodo_de_agendamento_id',
        'estado_de_agendamento',
        'estado_de_agendamento_id',
        'reserva_sangue',
        'comentarios',
    ];

    protected function casts(): array
    {
        return ['start' => 'datetime', 'end' => 'datetime'];
    }

    public function espera(): BelongsTo
    {
        return $this->belongsTo(ListaDeEspera::class, 'lista_de_espera_id')->withTrashed();
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    /** Os registos antigos guardam o estado em `estado_de_agendamento`, os novos em `estado_de_agendamento_id`. */
    public function estado(): ?int
    {
        $valor = $this->estado_de_agendamento_id ?? $this->estado_de_agendamento;

        return $valor === null || $valor === '' ? null : (int) $valor;
    }
}
