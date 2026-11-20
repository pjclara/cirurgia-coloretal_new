<?php

namespace App\Console\Commands;

use App\Support\LeitorDeFolha;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Carrega para a tabela de decisão terapêutica as consultas «DT» (oncologia, sem presença do utente)
 * de um ficheiro do hospital: uma decisão por doente e dia, ligada à referenciação do utente.
 *
 * Por omissão só mostra o que faria; grava com --aplicar (e guarda um CSV com os ids criados, para se poder repor).
 * Chave: n.º de processo -> utente (processos repetidos em mais do que uma ficha ficam de fora).
 * A referenciação é a que estava ativa nesse dia; senão a mais recente anterior; senão a primeira do utente.
 * Decisões que já existam para a mesma referenciação e dia não se repetem. O ficheiro só traz a data, o tipo
 * (primeira/subsequente) e o médico: o resumo e o timming ficam por preencher.
 */
class ImportarDecisoesTerapeuticas extends Command
{
    private const ESTADO_REALIZADA = 3;

    protected $signature = 'decisoes:importar-dt
        {ficheiro : Folha do hospital (.xlsx ou .csv) com NUM_PROCESSO e DTA_REALIZACAO}
        {--aplicar : Grava as decisões (sem isto só mostra o que faria)}';

    protected $description = 'Importa as consultas de decisão terapêutica (DT) de uma folha do hospital';

    public function handle(): int
    {
        if (! DB::table('estado_consultas')->where('id', self::ESTADO_REALIZADA)->exists()) {
            $this->error('Falta o estado de consulta «Realizada» (id '.self::ESTADO_REALIZADA.') na tabela estado_consultas.');

            return self::FAILURE;
        }

        $hoje = now()->format('Y-m-d');
        $consultas = []; // "processo|dia" => [processo, dia, tipo, médico]
        $lidas = 0;
        foreach (LeitorDeFolha::linhas((string) $this->argument('ficheiro')) as $linha) {
            $processo = isset($linha['NUM_PROCESSO']) ? (int) preg_replace('/\D/', '', $linha['NUM_PROCESSO']) : 0;
            $dia = LeitorDeFolha::data($linha['DTA_REALIZACAO'] ?? null);
            if ($processo === 0 || $dia === null || $dia > $hoje) {
                continue;
            }
            $lidas++;
            $consultas["$processo|$dia"] ??= [$processo, $dia, trim((string) ($linha['TIP_CONSULTA'] ?? '')), trim((string) ($linha['NOME_CLINICO'] ?? ''))];
        }
        if ($lidas === 0) {
            $this->error('Não encontrei linhas com NUM_PROCESSO e DTA_REALIZACAO: confirme o ficheiro e os cabeçalhos.');

            return self::FAILURE;
        }

        $processos = array_values(array_unique(array_column($consultas, 0)));
        $utentesPorProcesso = DB::table('utentes')->whereNull('deleted_at')->whereIn('numero_processo', $processos)
            ->get(['id', 'numero_processo'])->groupBy('numero_processo');

        $referenciacoes = DB::table('referenciacao_centro_de_referencias')->whereNull('deleted_at')
            ->whereIn('utente_id', $utentesPorProcesso->flatten(1)->pluck('id'))
            ->orderBy('data_de_referenciacao')->orderBy('id')
            ->get(['id', 'utente_id', 'data_de_referenciacao', 'data_de_saida'])->groupBy('utente_id');

        $existentes = DB::table('reuniao_decisao_terapeuticas')->whereNull('deleted_at')
            ->get(['referenciacao_id', 'data_de_decisao_terapeutica'])
            ->mapWithKeys(fn ($r) => [$r->referenciacao_id.'|'.substr((string) $r->data_de_decisao_terapeutica, 0, 10) => true]);

        $criar = [];
        $semUtente = $ambiguos = $semReferenciacao = $jaExistem = $anterioresAReferenciacao = 0;
        foreach ($consultas as [$processo, $dia, $tipo, $medico]) {
            $utentes = $utentesPorProcesso->get($processo);
            if ($utentes === null) {
                $semUtente++;

                continue;
            }
            if ($utentes->count() > 1) {
                $ambiguos++;

                continue;
            }
            $lista = $referenciacoes->get($utentes->first()->id);
            if ($lista === null || $lista->isEmpty()) {
                $semReferenciacao++;

                continue;
            }
            $ativa = $lista->first(fn ($r) => substr((string) $r->data_de_referenciacao, 0, 10) <= $dia && ($r->data_de_saida === null || substr((string) $r->data_de_saida, 0, 10) >= $dia));
            $anterior = $lista->last(fn ($r) => substr((string) $r->data_de_referenciacao, 0, 10) <= $dia);
            $escolhida = $ativa ?? $anterior ?? $lista->first();
            if ($ativa === null && $anterior === null) {
                $anterioresAReferenciacao++;
            }
            if (isset($existentes[$escolhida->id.'|'.$dia])) {
                $jaExistem++;

                continue;
            }
            $criar[] = ['referenciacao_id' => $escolhida->id, 'dia' => $dia, 'processo' => $processo, 'tipo' => $tipo, 'medico' => $medico];
        }

        $this->line(sprintf('Consultas DT no ficheiro (processo + dia): %d', count($consultas)));
        $this->line(sprintf('  sem utente na aplicação: %d · processo em várias fichas: %d · utente sem referenciação: %d · já registadas: %d', $semUtente, $ambiguos, $semReferenciacao, $jaExistem));
        $this->info(sprintf('Decisões a criar: %d (das quais %d anteriores à referenciação do utente)', count($criar), $anterioresAReferenciacao));

        if ($criar === []) {
            return self::SUCCESS;
        }

        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->line('Exemplos (processo · dia · referenciação · tipo):');
            foreach (array_slice($criar, 0, 8) as $c) {
                $this->line(sprintf('  %s · %s · %s · %s', $c['processo'], $c['dia'], $c['referenciacao_id'], $c['tipo']));
            }
            $this->newLine();
            $this->comment('Só simulação: nada foi gravado. Faça um backup e volte a correr com --aplicar.');

            return self::SUCCESS;
        }

        $agora = now();
        $ids = [];
        DB::transaction(function () use ($criar, $agora, &$ids) {
            foreach ($criar as $c) {
                $nota = 'Importado do ficheiro do hospital (consulta DT'.($c['tipo'] !== '' ? ', '.mb_strtolower($c['tipo']) : '').($c['medico'] !== '' ? ' · '.$c['medico'] : '').').';
                $ids[] = DB::table('reuniao_decisao_terapeuticas')->insertGetId([
                    'referenciacao_id' => $c['referenciacao_id'],
                    'data_de_decisao_terapeutica' => $c['dia'],
                    'estado_consulta_id' => self::ESTADO_REALIZADA,
                    'comentarios' => $nota,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
            }
        });

        $ficheiroLog = 'decisoes_dt_'.now()->format('Ymd_His').'.csv';
        Storage::disk('local')->put($ficheiroLog, "id_criado;referenciacao_id;dia;processo\n".implode("\n", array_map(
            fn ($c, $id) => implode(';', [$id, $c['referenciacao_id'], $c['dia'], $c['processo']]),
            $criar,
            $ids
        ))."\n");

        $this->info(sprintf('Gravado: %d decisões terapêuticas.', count($ids)));
        $this->line('Registo dos ids criados (para repor, apagar estes ids): '.storage_path('app/private/'.$ficheiroLog));

        return self::SUCCESS;
    }
}
