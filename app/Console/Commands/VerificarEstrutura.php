<?php

namespace App\Console\Commands;

use App\Http\Controllers\CancroRetalAnaliseController;
use App\Models\Complicacao;
use App\Models\Internamento;
use App\Models\Morbilidade;
use App\Models\Referenciacao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Confere se a base de dados tem o que a aplicação assume (tabelas, colunas e registos de referência com id fixo
 * no código). Para correr numa cópia da base de dados original antes de a pôr em uso. Não altera nada.
 *
 *   php artisan bd:verificar-estrutura
 */
class VerificarEstrutura extends Command
{
    protected $signature = 'bd:verificar-estrutura';

    protected $description = 'Confere tabelas, colunas e registos de referência de que a aplicação depende (só leitura)';

    /** @var array<string, list<string>> */
    private const COLUNAS = [
        'users' => ['activo', 'abrev', 'equipa', 'must_change_password'],
        'internamentos' => ['utente_id', 'responsavel_id', 'data_de_entrada', 'data_de_alta', 'destino_id', 'estado_da_alta_id'],
        'bloco_operatorios' => ['internamento_id', 'data_de_inicio'],
        'diagnosticos' => ['centro_de_referencia'],
        'intervencaos' => ['centro_de_referencia', 'cirurgia_de_ressecao'],
        'referenciacao_centro_de_referencias' => ['utente_id', 'data_de_referenciacao', 'data_de_saida', 'deleted_at'],
        'diagnostico_referenciacao' => ['referenciacao_id', 'diagnostico_id', 'modo_id', 'histologia_id', 'data_diagnostico'],
        'diagnostico_internamento' => ['internamento_id', 'diagnostico_id'],
        'morbilidades' => ['internamento_id', 'momento', 'clavien_dindo_id'],
        'complicacao_morbilidade' => ['morbilidade_id', 'complicacao_id', 'resolucao'],
        'audits' => ['event', 'auditable_type', 'auditable_id', 'old_values', 'new_values'],
    ];

    public function handle(): int
    {
        $falhas = 0;
        $linhas = [];
        $conferir = function (string $o_que, bool $ok, string $detalhe = '') use (&$linhas, &$falhas) {
            $linhas[] = [$ok ? 'OK' : 'FALTA', $o_que, $detalhe];
            $falhas += $ok ? 0 : 1;
        };

        foreach (self::COLUNAS as $tabela => $colunas) {
            if (! Schema::hasTable($tabela)) {
                $conferir("tabela $tabela", false);

                continue;
            }
            $faltam = array_values(array_filter($colunas, fn ($c) => ! Schema::hasColumn($tabela, $c)));
            $conferir("tabela $tabela", $faltam === [], $faltam ? 'colunas em falta: '.implode(', ', $faltam) : '');
        }

        // Registos com id fixo no código.
        $registo = fn (string $tabela, int $id, string $padrao) => Schema::hasTable($tabela)
            && (bool) DB::table($tabela)->where('id', $id)->where('nome', 'like', $padrao)->exists();
        $conferir('«Sem complicações» (id '.Complicacao::SEM_COMPLICACOES.')', $registo('complicacaos', Complicacao::SEM_COMPLICACOES, 'Sem complica%'));
        $conferir('«Deiscência da anastomose» (id '.CancroRetalAnaliseController::DEISCENCIA.')', $registo('complicacaos', CancroRetalAnaliseController::DEISCENCIA, 'Deisc%anastomose%'));
        $conferir('Ressecção anterior do reto (id '.CancroRetalAnaliseController::RESSECCAO_ANTERIOR.')', $registo('intervencaos', CancroRetalAnaliseController::RESSECCAO_ANTERIOR, 'Ressec%anterior do rec%'));
        $conferir('Amputação abdomino-perineal (id '.CancroRetalAnaliseController::AMPUTACAO_ABDOMINO_PERINEAL.')', $registo('intervencaos', CancroRetalAnaliseController::AMPUTACAO_ABDOMINO_PERINEAL, 'Amputa%abdomino%'));
        $conferir('Resseção reto-sigmoideia (id '.CancroRetalAnaliseController::RESSECCAO_RETO_SIGMOIDEIA.')', $registo('intervencaos', CancroRetalAnaliseController::RESSECCAO_RETO_SIGMOIDEIA, 'Resse%reto-sigm%'));
        $conferir('Destino «falecido» (id '.Internamento::DESTINO_FALECIDO.')', Schema::hasTable('destinos') && DB::table('destinos')->where('id', Internamento::DESTINO_FALECIDO)->exists());
        $conferir('Clavien-Dindo «a aguardar» (id '.Morbilidade::CLAVIEN_A_AGUARDAR.')', $registo('clavien_dindos', Morbilidade::CLAVIEN_A_AGUARDAR, '%aguardar%'));
        $conferir('Modo de diagnóstico «Outro» (id '.Referenciacao::MODO_OUTRO.')', $registo('diagnostico_referenciacao_modos', Referenciacao::MODO_OUTRO, 'Outro%'));

        foreach ((array) config('clinico.roles_administracao') as $perfil) {
            $conferir("perfil $perfil", Schema::hasTable('roles') && DB::table('roles')->where('name', $perfil)->exists());
        }

        $pendentes = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'))
            ->diff(Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration') : collect());
        $conferir('migrações por correr', true, $pendentes->isEmpty() ? 'nenhuma' : $pendentes->count().': '.$pendentes->implode(', '));

        $this->table(['', 'Verificação', 'Detalhe'], $linhas);
        $this->line($falhas === 0 ? 'Estrutura compatível. Faça um backup e corra «php artisan migrate».' : "$falhas verificação(ões) a falhar: resolver antes de pôr esta base de dados em uso.");

        return $falhas === 0 ? self::SUCCESS : self::FAILURE;
    }
}
