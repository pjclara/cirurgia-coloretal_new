<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ImportadorReferenciacoes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Importa a lista de referenciação (triagem das consultas, .xlsx) para a aplicação. Por omissão só simula; grava com --aplicar
 * (e guarda um CSV com as referenciações criadas). As regras estão em ImportadorReferenciacoes.
 */
class ImportarReferenciacoes extends Command
{
    protected $signature = 'referenciacoes:importar
        {ficheiro : Folha da triagem (.xlsx com as folhas «Existe» e «Não existe nas consultas»)}
        {--autor= : Id do utilizador que fica como autor dos registos (por omissão o primeiro administrador)}
        {--aplicar : Grava as alterações (sem isto só mostra o que faria)}';

    protected $description = 'Importa a lista de referenciação ao centro de referência a partir da triagem das consultas';

    private const MOTIVOS = [
        'futura' => 'data futura', 'sem_medico' => 'médico desconhecido', 'sem_data' => 'sem data', 'sem_nome' => 'sem nome',
        'ja_sinalizado' => 'já sinalizado no centro', 'ja_existe' => 'já tem referenciação nessa data', 'repetido' => 'repetido na folha',
    ];

    public function handle(ImportadorReferenciacoes $importador): int
    {
        $analise = $importador->analisar((string) $this->argument('ficheiro'));
        if ($analise['linhas'] === 0) {
            $this->error('Não encontrei linhas: confirme o ficheiro e os cabeçalhos (Processo, Nome, Localização, Médico, Data).');

            return self::FAILURE;
        }
        $plano = $analise['plano'];

        $this->line(sprintf('Linhas lidas: %d', $analise['linhas']));
        $this->info(sprintf('Referenciações a criar: %d (utentes novos: %d)', count($plano), $analise['novos_utentes']));
        $this->line(sprintf('Com diagnóstico CR mapeado: %d', count(array_filter($plano, fn ($p) => $p['diagnostico']))));
        foreach ($analise['saltados'] as $motivo => $n) {
            if ($n > 0) {
                $this->line(sprintf('  Saltadas (%s): %d', self::MOTIVOS[$motivo], $n));
            }
        }
        foreach (array_filter($analise['lista_saltados'], fn ($s) => in_array($s['motivo'], ['sem_nome', 'sem_medico', 'futura', 'sem_data'], true)) as $s) {
            $this->line(sprintf('    · %s · %s · %s (%s)', $s['nome'], $s['processo'] ?: 's/ processo', $s['data'], self::MOTIVOS[$s['motivo']]));
        }
        if ($analise['sem_diagnostico'] !== []) {
            $this->newLine();
            $this->line('Localizações sem diagnóstico CR (ficam só nos comentários):');
            foreach ($analise['sem_diagnostico'] as $nome => $n) {
                $this->line(sprintf('  %3d × %s', $n, $nome));
            }
        }

        if ($plano === []) {
            return self::SUCCESS;
        }
        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->comment('Só simulação: nada foi gravado. Faça um backup e volte a correr com --aplicar.');

            return self::SUCCESS;
        }

        $autor = (int) ($this->option('autor') ?: User::role(['superAdmin', 'admin'])->orderBy('id')->value('id'));
        if (! User::whereKey($autor)->exists()) {
            $this->error('Não encontrei o utilizador autor: indique-o com --autor=ID.');

            return self::FAILURE;
        }
        $csv = $importador->aplicar($plano, $autor);
        $ficheiroLog = 'importacao_referenciacoes_'.now()->format('Ymd_His').'.csv';
        Storage::disk('local')->put($ficheiroLog, $csv);
        $this->info(sprintf('Gravado: %d referenciações.', count($plano)));
        $this->line('Registo do que foi criado: '.storage_path('app/private/'.$ficheiroLog));

        return self::SUCCESS;
    }
}
