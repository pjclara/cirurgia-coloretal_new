<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cruza os agendamentos com o registo do bloco operatório: se o utente foi operado (há uma cirurgia registada)
 * por volta do dia agendado, o agendamento passa a "Operado" (estado 5).
 *
 * Como se faz a correspondência:
 *  - o mesmo utente (agendamento -> lista de espera -> utente; cirurgia -> internamento -> utente);
 *  - cirurgia até TOLERANCIA_DIAS dias antes ou depois do dia agendado, e já realizada (não futura);
 *  - cada cirurgia fica associada a um só agendamento: o mais próximo da sua data (empate: o mais antigo);
 *  - agendamentos cancelados nunca passam a operados; os já operados ficam como estão.
 * Só se muda `estado_de_agendamento_id` (o campo antigo `estado_de_agendamento` só admite 1 a 4).
 *
 * Reversível: guarda o estado anterior em `agendamento_estado_anterior`. Corre antes de
 * 2026_11_16_000003, que depois alinha o estado da lista de espera com o dos agendamentos.
 * Faça um backup da base de dados antes de correr.
 */
return new class extends Migration
{
    public const ESTADO_OPERADO = 5;

    private const ESTADO_CANCELADO = 4;

    /** Diferença máxima, em dias, entre o dia agendado e a data da cirurgia. */
    private const TOLERANCIA_DIAS = 3;

    public function up(): void
    {
        if (! Schema::hasTable('agendamento_estado_anterior')) {
            Schema::create('agendamento_estado_anterior', function (Blueprint $tabela) {
                $tabela->unsignedBigInteger('agendamento_id')->primary();
                $tabela->unsignedBigInteger('anterior')->nullable();
                $tabela->timestamp('alterado_em')->useCurrent();
            });
        }

        $this->marcar(true);
    }

    public function down(): void
    {
        if (Schema::hasTable('agendamento_estado_anterior')) {
            DB::table('agendamento_estado_anterior')->orderBy('agendamento_id')->each(
                fn ($a) => DB::table('agendamentos')->where('id', $a->agendamento_id)->update(['estado_de_agendamento_id' => $a->anterior])
            );
        }

        Schema::dropIfExists('agendamento_estado_anterior');
    }

    /**
     * Faz a correspondência e devolve os ids dos agendamentos que passam a operados.
     * `$guardarAnterior` regista o estado antigo para o `down()`; `$simular` só calcula, sem gravar.
     *
     * @return int[]
     */
    public function marcar(bool $guardarAnterior, bool $simular = false): array
    {
        $hoje = now()->format('Y-m-d');

        // Agendamentos que podem ser operados, por utente.
        $porUtente = [];
        DB::table('agendamentos as a')->join('lista_de_esperas as l', 'l.id', '=', 'a.lista_de_espera_id')
            ->whereNull('a.deleted_at')->whereNull('l.deleted_at')
            ->orderBy('a.start')->orderBy('a.id')
            ->get(['a.id', 'l.utente_id', 'a.start', 'a.estado_de_agendamento', 'a.estado_de_agendamento_id'])
            ->each(function ($a) use (&$porUtente) {
                $estado = (int) ($a->estado_de_agendamento_id ?? $a->estado_de_agendamento);
                if ($estado !== self::ESTADO_CANCELADO) {
                    $porUtente[$a->utente_id][] = ['id' => (int) $a->id, 'dia' => substr((string) $a->start, 0, 10), 'estado' => $estado, 'anterior' => $a->estado_de_agendamento_id];
                }
            });

        $cirurgias = DB::table('bloco_operatorios as b')->join('internamentos as i', 'i.id', '=', 'b.internamento_id')
            ->whereNull('b.deleted_at')->whereNull('i.deleted_at')->where('b.data_de_inicio', '<=', $hoje)
            ->orderBy('b.data_de_inicio')->orderBy('b.id')
            ->get(['i.utente_id', 'b.data_de_inicio']);

        $usados = [];
        $novos = [];
        foreach ($cirurgias as $c) {
            $melhor = null;
            $melhorDistancia = PHP_INT_MAX;
            foreach ($porUtente[$c->utente_id] ?? [] as $a) {
                if (isset($usados[$a['id']])) {
                    continue;
                }
                $distancia = abs((int) round((strtotime($a['dia']) - strtotime(substr((string) $c->data_de_inicio, 0, 10))) / 86400));
                if ($distancia <= self::TOLERANCIA_DIAS && $distancia < $melhorDistancia) {
                    $melhor = $a;
                    $melhorDistancia = $distancia;
                }
            }
            if ($melhor === null) {
                continue;
            }
            $usados[$melhor['id']] = true;
            if ($melhor['estado'] !== self::ESTADO_OPERADO) {
                $novos[$melhor['id']] = $melhor['anterior'];
            }
        }

        if (! $simular) {
            foreach ($novos as $id => $anterior) {
                if ($guardarAnterior) {
                    DB::table('agendamento_estado_anterior')->insertOrIgnore(['agendamento_id' => $id, 'anterior' => $anterior]);
                }
                DB::table('agendamentos')->where('id', $id)->update(['estado_de_agendamento_id' => self::ESTADO_OPERADO]);
            }
        }

        return array_keys($novos);
    }
};
