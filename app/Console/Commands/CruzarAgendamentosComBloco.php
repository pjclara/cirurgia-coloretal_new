<?php

namespace App\Console\Commands;

use App\Models\Agendamento;
use App\Models\ListaDeEspera;
use App\Services\SincronizarOperados;
use App\Support\LeitorDeFolha;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Cruza as cirurgias realizadas (folha do bloco operatório do hospital) com os agendamentos:
 * quem foi operado passa a "Operado" no agendamento e na lista de espera.
 *
 * Por omissão só mostra o que mudaria; grava com --aplicar (e guarda um CSV do que mudou, para se poder repor).
 * Chave: n.º de processo + data da intervenção, até --tolerancia dias de diferença (por omissão 1).
 * Cada cirurgia conta para um só agendamento (o mais próximo). Cancelados não mudam; os que o ficheiro
 * não confirma ficam como estão.
 */
class CruzarAgendamentosComBloco extends Command
{
    protected $signature = 'agendamentos:cruzar-bloco
        {ficheiro : Folha do bloco operatório (.xlsx ou .csv) com as colunas NUM_PROCESSO e DTA_INTERVENCAO}
        {--tolerancia=1 : Dias de diferença aceites entre o dia agendado e a cirurgia}
        {--aplicar : Grava as alterações (sem isto só mostra o que mudaria)}';

    protected $description = 'Marca como operados os agendamentos confirmados por uma folha do bloco operatório';

    public function handle(SincronizarOperados $operados): int
    {
        $tolerancia = max(0, (int) $this->option('tolerancia'));
        $hoje = now()->format('Y-m-d');

        // Cirurgias do ficheiro: processo => dias.
        $cirurgias = [];
        $lidas = 0;
        foreach (LeitorDeFolha::linhas((string) $this->argument('ficheiro')) as $linha) {
            $processo = isset($linha['NUM_PROCESSO']) ? preg_replace('/\D/', '', $linha['NUM_PROCESSO']) : '';
            $dia = LeitorDeFolha::data($linha['DTA_INTERVENCAO'] ?? null);
            if ($processo === '' || $dia === null) {
                continue;
            }
            $lidas++;
            if ($dia <= $hoje) {
                $cirurgias[(int) $processo][] = $dia;
            }
        }
        if ($lidas === 0) {
            $this->error('Não encontrei linhas com NUM_PROCESSO e DTA_INTERVENCAO: confirme o ficheiro e os cabeçalhos.');

            return self::FAILURE;
        }
        foreach ($cirurgias as &$dias) {
            sort($dias);
        }
        unset($dias);

        // Agendamentos que podem ser operados, por processo (cancelados nunca).
        $porProcesso = [];
        Agendamento::query()->with('espera.utente:id,numero_processo')
            ->whereIn('lista_de_espera_id', ListaDeEspera::query()->select('id'))
            ->orderBy('start')->orderBy('id')->get()
            ->each(function (Agendamento $a) use (&$porProcesso) {
                $processo = $a->espera?->utente?->numero_processo;
                if ($processo && $a->estado() !== ListaDeEspera::ESTADO_CANCELADO) {
                    $porProcesso[(int) $processo][] = $a;
                }
            });

        $usados = [];
        $marcar = [];
        $jaOperados = 0;
        foreach ($cirurgias as $processo => $dias) {
            foreach ($dias as $dia) {
                $melhor = null;
                $melhorDistancia = PHP_INT_MAX;
                foreach ($porProcesso[$processo] ?? [] as $a) {
                    if (isset($usados[$a->id])) {
                        continue;
                    }
                    $distancia = abs((int) round(($a->start->copy()->startOfDay()->timestamp - strtotime($dia)) / 86400));
                    if ($distancia <= $tolerancia && $distancia < $melhorDistancia) {
                        $melhor = $a;
                        $melhorDistancia = $distancia;
                    }
                }
                if (! $melhor) {
                    continue;
                }
                $usados[$melhor->id] = true;
                if ($melhor->estado() === ListaDeEspera::ESTADO_OPERADO) {
                    $jaOperados++;
                } else {
                    $marcar[] = ['agendamento' => $melhor, 'cirurgia' => $dia, 'processo' => $processo];
                }
            }
        }

        $total = collect($porProcesso)->flatten(1)->count();
        $porEstado = collect($marcar)->countBy(fn ($m) => $m['agendamento']->estado())->sortKeys();
        $nomes = [1 => 'Proposto', 2 => 'Pendente', 3 => 'Agendado'];

        $this->line(sprintf('Cirurgias lidas no ficheiro: %d (tolerância: %d dia%s)', $lidas, $tolerancia, $tolerancia === 1 ? '' : 's'));
        $this->line(sprintf('Agendamentos não cancelados: %d · já operados e confirmados: %d', $total, $jaOperados));
        $this->info(sprintf('Passam a operados: %d', count($marcar)));
        foreach ($porEstado as $estado => $n) {
            $this->line(sprintf('  %s: %d', $nomes[$estado] ?? "estado $estado", $n));
        }

        if ($marcar === []) {
            return self::SUCCESS;
        }

        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->line('Exemplos (processo · dia agendado · cirurgia · estado atual):');
            foreach (array_slice($marcar, 0, 8) as $m) {
                $this->line(sprintf('  %s · %s · %s · %s', $m['processo'], $m['agendamento']->start->format('Y-m-d'), $m['cirurgia'], $nomes[$m['agendamento']->estado()] ?? '?'));
            }
            $this->newLine();
            $this->comment('Só simulação: nada foi gravado. Faça um backup e volte a correr com --aplicar.');

            return self::SUCCESS;
        }

        // Registo do que vai mudar, para se poder repor o estado anterior.
        $ficheiroLog = 'cruzamento_bloco_'.now()->format('Ymd_His').'.csv';
        $csv = "agendamento_id;lista_de_espera_id;processo;dia_agendado;cirurgia;estado_anterior_agendamento;estado_anterior_lista\n";
        foreach ($marcar as $m) {
            $a = $m['agendamento'];
            $csv .= implode(';', [$a->id, $a->lista_de_espera_id, $m['processo'], $a->start->format('Y-m-d'), $m['cirurgia'], $a->estado_de_agendamento_id ?? '', $a->espera?->estado_lista_espera ?? '']).";\n";
        }
        Storage::disk('local')->put($ficheiroLog, $csv);

        $operados->marcarOperados(collect($marcar)->pluck('agendamento'));

        $this->info(sprintf('Gravado: %d agendamentos (e as inscrições respetivas) passaram a operados.', count($marcar)));
        $this->line('Registo do que mudou: '.storage_path('app/private/'.$ficheiroLog));

        return self::SUCCESS;
    }
}
