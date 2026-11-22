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

    /** ids de tipo_de_agendamentos e local_de_agendamentos usados para classificar o agendamento. */
    public const TIPO_PROGRAMADA = 1;

    public const TIPO_URGENCIA = 3;

    public const TIPO_ADICIONAL = 4;

    public const LOCAL_AMBULATORIO = 2;

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
        'hora',
        'estado_de_agendamento',
        'estado_de_agendamento_id',
        'reserva_sangue',
        'motivo_cancelar_agendamento',
        'comentarios',
    ];

    /** Motivos de cancelamento (motivo_cancelar_agendamento). O 6 existe só em registos antigos, sem descrição conhecida. */
    public const MOTIVOS_CANCELAMENTO = [
        1 => 'Falta de tempo operatório',
        2 => 'Doente sem resposta ou operado noutra instituição',
        3 => 'Pedido do doente (prefere outra data ou tratar antes outro problema)',
        4 => 'Condição clínica do doente',
        5 => 'Falta de recursos (equipa, material, greve)',
        99 => 'Outro motivo',
    ];

    public const MOTIVO_ANTIGO = 6;

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

    /**
     * Categoria para o calendário: adicional (tipo) tem prioridade, depois ambulatório (local),
     * depois programada; urgência e outros ficam em "outra".
     */
    public function categoria(): string
    {
        return match (true) {
            (int) $this->tipo_de_agendamento_id === self::TIPO_ADICIONAL => 'adicional',
            (int) $this->local_de_agendamento_id === self::LOCAL_AMBULATORIO => 'ambulatorio',
            (int) $this->tipo_de_agendamento_id === self::TIPO_PROGRAMADA => 'programada',
            default => 'outra',
        };
    }

    /** Hora da cirurgia como HH:MM, ou null se não foi indicada. */
    public function horaCurta(): ?string
    {
        return $this->hora ? substr((string) $this->hora, 0, 5) : null;
    }

    /** Os registos antigos guardam o estado em `estado_de_agendamento`, os novos em `estado_de_agendamento_id`. */
    public function estado(): ?int
    {
        $valor = $this->estado_de_agendamento_id ?? $this->estado_de_agendamento;

        return $valor === null || $valor === '' ? null : (int) $valor;
    }
}
