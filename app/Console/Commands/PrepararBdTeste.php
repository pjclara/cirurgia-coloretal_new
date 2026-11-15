<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cria (ou recria) a base de dados de testes a partir da estrutura da base atual.
 *
 * Copia só as tabelas de referência (domínios, permissões); nunca copia dados de utentes.
 * Os testes correm nesta base, dentro de transações, e nunca na base de trabalho.
 */
class PrepararBdTeste extends Command
{
    protected $signature = 'testes:preparar';

    protected $description = 'Cria a base de testes (estrutura + tabelas de referência) a partir da base atual';

    /** Tabelas cujo conteúdo é referência e não dados clínicos. */
    private const REFERENCIA = [
        'migrations', 'permissions', 'roles', 'role_has_permissions',
        'sexos', 'estado_civils', 'etnias', 'concelhos', 'classe_socials',
        'localizacaos', 'origem_do_internamentos', 'estado_da_altas', 'destinos', 'caso_socials',
        'clavien_dindos', 'resolucao_complicacaos', 'grupo_complicacaos', 'complicacaos',
        'grupo_diagnosticos', 'diagnosticos', 'grupo_intervencaos', 'intervencaos',
        'periodo_de_agendamentos', 'local_de_agendamentos', 'sala_de_agendamentos',
        'tipo_de_agendamentos', 'estado_de_agendamentos', 'tipo_de_cirurgias', 'tipo_de_abordagems', 're_intervencao_nao_programadas',
        'origem_da_referenciacaos', 'diagnostico_referenciacao_modos', 'histologias',
    ];

    public function handle(): int
    {
        $origem = DB::connection()->getDatabaseName();
        $destino = $origem.'_teste';

        if (str_ends_with($origem, '_teste')) {
            $this->error("A base atual ($origem) já é uma base de testes. Corra este comando com a base de trabalho.");

            return self::FAILURE;
        }

        DB::statement("CREATE DATABASE IF NOT EXISTS `$destino` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $tabelas = collect(DB::select(
            "select table_name as nome from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE' order by table_name",
            [$origem]
        ))->pluck('nome');

        // Estruturas lidas com a base de origem ativa; criadas com a de testes ativa (as chaves estrangeiras apontam para ela).
        $estruturas = $tabelas->mapWithKeys(fn ($t) => [$t => ((array) DB::selectOne("SHOW CREATE TABLE `$t`"))['Create Table']]);

        DB::statement("USE `$destino`");
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tabelas as $tabela) {
            DB::statement("DROP TABLE IF EXISTS `$tabela`");
            DB::statement($estruturas[$tabela]);
        }

        $copiadas = 0;
        foreach (self::REFERENCIA as $tabela) {
            if ($tabelas->contains($tabela)) {
                DB::statement("INSERT INTO `$destino`.`$tabela` SELECT * FROM `$origem`.`$tabela`");
                $copiadas++;
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // A base de testes fica com as migrações ainda por correr na base de trabalho: liga-se mesmo à de testes para as correr.
        $ligacao = DB::getDefaultConnection();
        config(['database.connections.'.$ligacao.'.database' => $destino]);
        DB::purge($ligacao);
        $this->call('migrate', ['--force' => true]);

        $this->info("Base de testes `$destino` pronta: {$tabelas->count()} tabelas, {$copiadas} com dados de referência.");

        return self::SUCCESS;
    }
}
