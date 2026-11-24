<?php

namespace App\Console\Commands;

use App\Services\ImportadorListaDeEspera;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Importa a lista de espera cirúrgica do hospital (.xlsx ou .csv) para a aplicação; a mesma importação está na
 * administração (Sistema > Importar lista de espera). Por omissão só simula; grava com --aplicar
 * (e guarda um CSV com as inscrições criadas). As regras estão em ImportadorListaDeEspera.
 */
class ImportarListaDeEspera extends Command
{
    protected $signature = 'lista-espera:importar
        {ficheiro : Folha da lista de espera (.xlsx ou .csv)}
        {--aplicar : Grava as alterações (sem isto só mostra o que faria)}';

    protected $description = 'Importa a lista de espera do hospital, mapeando diagnósticos e intervenções para o catálogo';

    public function handle(ImportadorListaDeEspera $importador): int
    {
        $analise = $importador->analisar((string) $this->argument('ficheiro'));
        if ($analise['linhas'] === 0) {
            $this->error('Não encontrei linhas com a coluna UTENTE: confirme o ficheiro e os cabeçalhos.');

            return self::FAILURE;
        }
        $plano = $analise['plano'];
        $s = $analise['saltados'];

        $this->line(sprintf('Linhas lidas: %d', $analise['linhas']));
        $this->info(sprintf('Inscrições a criar: %d (utentes novos: %d)', count($plano), $analise['novos_utentes']));
        $this->line(sprintf(
            'Saltadas: %d já com inscrição ativa · %d processo repetido no ficheiro · %d sem médico · %d sem data',
            $s['ativa'], $s['repetido'], $s['sem_medico'], $s['sem_data'],
        ));
        $this->line(sprintf('Com diagnóstico mapeado: %d · com intervenção mapeada: %d', count(array_filter($plano, fn ($p) => $p['diagnostico'])), count(array_filter($plano, fn ($p) => $p['intervencao']))));
        $this->semMapeamento('Diagnósticos sem correspondência (ficam nos comentários)', $analise['sem_diagnostico']);
        $this->semMapeamento('Procedimentos sem correspondência (ficam nos comentários)', $analise['sem_intervencao']);

        if ($plano === []) {
            return self::SUCCESS;
        }
        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->comment('Só simulação: nada foi gravado. Faça um backup e volte a correr com --aplicar.');

            return self::SUCCESS;
        }

        $csv = $importador->aplicar($plano);
        $ficheiroLog = 'importacao_lista_espera_'.now()->format('Ymd_His').'.csv';
        Storage::disk('local')->put($ficheiroLog, $csv);
        $this->info(sprintf('Gravado: %d inscrições.', count($plano)));
        $this->line('Registo do que foi criado: '.storage_path('app/private/'.$ficheiroLog));

        return self::SUCCESS;
    }

    private function semMapeamento(string $titulo, array $itens): void
    {
        if ($itens === []) {
            return;
        }
        $this->newLine();
        $this->line($titulo.':');
        foreach ($itens as $nome => $n) {
            $this->line(sprintf('  %3d × %s', $n, $nome));
        }
    }
}
