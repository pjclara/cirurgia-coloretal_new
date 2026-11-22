<?php

namespace App\Console\Commands;

use App\Models\Diagnostico;
use App\Models\Intervencao;
use App\Models\ListaDeEspera;
use App\Models\User;
use App\Models\Utente;
use App\Support\LeitorDeFolha;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Importa a lista de espera cirúrgica do hospital (.xlsx ou .csv) para a aplicação.
 *
 * Colunas: DTA_INSCR, UTENTE (n.º de processo), Nome, Data de nascimento, NOME_CLINICO, DIAG PRI (CID-10), DES_DIAGNOSTICO,
 * PROC_PRINC (ICD-10-PCS), DESIGNACAO. Cria os utentes em falta e uma inscrição "Proposto" por doente.
 * Diagnóstico e intervenção são mapeados para o catálogo da aplicação; o que não tem correspondência
 * fica só nos comentários. Quem já tem inscrição ativa (proposto, pendente ou agendado) é saltado.
 *
 * Por omissão só simula; grava com --aplicar (e guarda um CSV com as inscrições criadas).
 */
class ImportarListaDeEspera extends Command
{
    protected $signature = 'lista-espera:importar
        {ficheiro : Folha da lista de espera (.xlsx ou .csv)}
        {--aplicar : Grava as alterações (sem isto só mostra o que faria)}';

    protected $description = 'Importa a lista de espera do hospital, mapeando diagnósticos e intervenções para o catálogo';

    /** Nome clínico do ficheiro (sem acentos, minúsculas) => nome do utilizador na aplicação. */
    private const MEDICOS = [
        'paulo jorge clara' => 'Paulo Clara',
        'ines ferrer sales' => 'Inês Sales',
        'sonia fortuna martins' => 'Sónia Martins',
        'maria ines coelho' => 'Maria Inês Coelho',
        'vera pedro' => 'Vera Pedro',
        'joao nobre' => 'João Nobre',
        'sandra amado' => 'Sandra Amado',
    ];

    /** Inês Filipa Sousa tem dois utilizadores com o mesmo nome: usa-se o que opera (id 34). */
    private const MEDICOS_POR_ID = ['ines filipa sousa' => 34];

    /** CID-10 => id de diagnosticos. */
    private const DIAGNOSTICOS = [
        'A630' => 165, 'K601' => 96,
        'K6030' => 11, 'K60311' => 11, 'K60312' => 11, 'K60313' => 11, 'K60321' => 11, 'K60322' => 11, 'K60323' => 11, 'K60512' => 11,
        'K620' => 5, 'K623' => 33,
        'K641' => 120, 'K642' => 120, 'K643' => 120, 'K648' => 120, 'K649' => 120, 'K644' => 154,
        'L0501' => 101, 'L0502' => 101, 'L0591' => 101, 'L0592' => 101,
        'N816' => 44, 'Z932' => 14, 'Z933' => 27,
    ];

    /** Procedimento (ICD-10-PCS) => intervenção fixa. */
    private const INTERVENCOES = [
        '06BY0ZC' => 25, '06BY3ZC' => 25,
        '0D5Q0Z3' => 79, '0D5Q4Z3' => 79,
        '0H89XZZ' => 64, '0K8M0ZZ' => 64,
        '0D9Q00Z' => 12, '0D9Q30Z' => 12, '0D9Q70Z' => 12, '0D9Q80Z' => 12, '0D9R00Z' => 12, '0D9R40Z' => 12, '0H99X0Z' => 12,
        '0DBP7ZZ' => 77, '0DBP8ZZ' => 77, '0DTP7ZZ' => 77,
        '0DSP4ZZ' => 28, '0DUP4JZ' => 28,
        '0DHQ3DZ' => 41, '0DHQ7DZ' => 41, '0DLQ3DZ' => 41,
        '0DTQ0ZZ' => 124, '05PY03Z' => 44,
    ];

    /** Procedimentos de excisão/destruição de ânus, pele e tecido subcutâneo: a intervenção depende do diagnóstico. */
    private const EXCISOES = [
        '0DBQ0ZX', '0DBQ0ZZ', '0DBQ3ZZ', '0DBQ7ZZ', '0DBQXZX', '0DBR0ZZ', '0H59XZZ', '0HB8XZX', '0HB8XZZ', '0HB9XZX', '0HB9XZZ',
        '0J590ZZ', '0J593ZZ', '0J5B0ZZ', '0JBB0ZX', '0JBB0ZZ', '0JC90ZZ', '0JD60ZZ',
    ];

    public function handle(): int
    {
        $medicos = $this->medicos();
        $diagnosticosValidos = Diagnostico::query()->whereIn('id', array_unique(array_values(self::DIAGNOSTICOS)))->pluck('id')->all();
        $intervencoesValidas = Intervencao::query()->pluck('id')->all();

        $linhas = [];
        foreach (LeitorDeFolha::linhas((string) $this->argument('ficheiro')) as $linha) {
            if (preg_replace('/\D/', '', (string) ($linha['UTENTE'] ?? '')) !== '') {
                $linhas[] = $linha;
            }
        }
        if ($linhas === []) {
            $this->error('Não encontrei linhas com a coluna UTENTE: confirme o ficheiro e os cabeçalhos.');

            return self::FAILURE;
        }

        $ativos = ListaDeEspera::query()->whereIn(DB::raw('CAST(estado_lista_espera AS UNSIGNED)'), [1, 2, 3])
            ->pluck('utente_id')->flip()->all();
        $utentes = Utente::withTrashed()->pluck('id', 'numero_processo')->all();

        $plano = [];
        $saltados = ['ativa' => 0, 'repetido' => 0, 'sem_medico' => 0, 'sem_data' => 0];
        $vistos = [];
        $semDiagnostico = [];
        $semIntervencao = [];
        $novosUtentes = 0;

        foreach ($linhas as $linha) {
            $processo = (int) preg_replace('/\D/', '', $linha['UTENTE']);
            $data = LeitorDeFolha::data($linha['DTA_INSCR'] ?? null);
            $medico = $medicos[$this->chave($linha['NOME_CLINICO'] ?? '')] ?? null;
            if ($data === null) {
                $saltados['sem_data']++;

                continue;
            }
            if ($medico === null) {
                $saltados['sem_medico']++;

                continue;
            }
            if (isset($vistos[$processo])) {
                $saltados['repetido']++;

                continue;
            }
            $utenteId = $utentes[$processo] ?? null;
            if ($utenteId !== null && isset($ativos[$utenteId])) {
                $saltados['ativa']++;
                $vistos[$processo] = true;

                continue;
            }
            $vistos[$processo] = true;

            $cid = strtoupper(trim((string) ($linha['DIAG PRI'] ?? '')));
            $pcs = strtoupper(trim((string) ($linha['PROC_PRINC'] ?? '')));
            $diagnostico = self::DIAGNOSTICOS[$cid] ?? null;
            $diagnostico = in_array($diagnostico, $diagnosticosValidos, true) ? $diagnostico : null;
            $intervencao = $this->intervencao($pcs, $cid);
            $intervencao = in_array($intervencao, $intervencoesValidas, true) ? $intervencao : null;
            if ($diagnostico === null) {
                $semDiagnostico[$cid.' '.($linha['DES_DIAGNOSTICO'] ?? '')] = ($semDiagnostico[$cid.' '.($linha['DES_DIAGNOSTICO'] ?? '')] ?? 0) + 1;
            }
            if ($intervencao === null) {
                $semIntervencao[$pcs.' '.($linha['DESIGNACAO'] ?? '')] = ($semIntervencao[$pcs.' '.($linha['DESIGNACAO'] ?? '')] ?? 0) + 1;
            }
            if ($utenteId === null) {
                $novosUtentes++;
            }

            $plano[] = compact('linha', 'processo', 'data', 'medico', 'utenteId', 'diagnostico', 'intervencao', 'cid', 'pcs');
        }

        $this->line(sprintf('Linhas lidas: %d', count($linhas)));
        $this->info(sprintf('Inscrições a criar: %d (utentes novos: %d)', count($plano), $novosUtentes));
        $this->line(sprintf(
            'Saltadas: %d já com inscrição ativa · %d processo repetido no ficheiro · %d sem médico · %d sem data',
            $saltados['ativa'], $saltados['repetido'], $saltados['sem_medico'], $saltados['sem_data'],
        ));
        $this->line(sprintf('Com diagnóstico mapeado: %d · com intervenção mapeada: %d', count(array_filter($plano, fn ($p) => $p['diagnostico'])), count(array_filter($plano, fn ($p) => $p['intervencao']))));
        $this->semMapeamento('Diagnósticos sem correspondência (ficam nos comentários)', $semDiagnostico);
        $this->semMapeamento('Procedimentos sem correspondência (ficam nos comentários)', $semIntervencao);

        if ($plano === []) {
            return self::SUCCESS;
        }
        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->comment('Só simulação: nada foi gravado. Faça um backup e volte a correr com --aplicar.');

            return self::SUCCESS;
        }

        $csv = "lista_de_espera_id;utente_id;processo;data_de_lista;utente_criado\n";
        DB::transaction(function () use ($plano, &$csv) {
            foreach ($plano as $p) {
                $criado = false;
                $utenteId = $p['utenteId'];
                if ($utenteId === null) {
                    $utente = Utente::create([
                        'nome' => trim((string) $p['linha']['Nome']),
                        'numero_processo' => $p['processo'],
                        'data_nascimento' => LeitorDeFolha::data($p['linha']['Data de nascimento'] ?? null),
                    ]);
                    $utenteId = $utente->id;
                    $criado = true;
                }

                $espera = new ListaDeEspera([
                    'prioridade_id' => 1,
                    'utente_id' => $utenteId,
                    'data_de_lista' => $p['data'],
                    'estado_lista_espera' => ListaDeEspera::ESTADO_PROPOSTO,
                    'comentarios' => $this->comentario($p),
                    'responsavel_id' => $p['medico'],
                ]);
                $espera->save();
                if ($p['diagnostico']) {
                    $espera->diagnosticos()->attach($p['diagnostico']);
                }
                if ($p['intervencao']) {
                    $espera->intervencoes()->attach($p['intervencao']);
                }
                $csv .= implode(';', [$espera->id, $utenteId, $p['processo'], $p['data'], $criado ? 1 : 0]).";\n";
            }
        });

        $ficheiroLog = 'importacao_lista_espera_'.now()->format('Ymd_His').'.csv';
        Storage::disk('local')->put($ficheiroLog, $csv);
        $this->info(sprintf('Gravado: %d inscrições.', count($plano)));
        $this->line('Registo do que foi criado: '.storage_path('app/private/'.$ficheiroLog));

        return self::SUCCESS;
    }

    /** @return array<string, int> nome clínico normalizado => id do utilizador */
    private function medicos(): array
    {
        $porNome = User::query()->get(['id', 'name'])->groupBy(fn (User $u) => $this->chave($u->name))->map(fn ($g) => $g->first()->id);
        $mapa = [];
        foreach (self::MEDICOS as $clinico => $nome) {
            if ($porNome->has($this->chave($nome))) {
                $mapa[$clinico] = $porNome[$this->chave($nome)];
            }
        }
        foreach (self::MEDICOS_POR_ID as $clinico => $id) {
            $mapa[$clinico] = $id;
        }

        return $mapa;
    }

    private function intervencao(string $pcs, string $cid): ?int
    {
        if (isset(self::INTERVENCOES[$pcs])) {
            return self::INTERVENCOES[$pcs];
        }
        if ($pcs === '0D8R0ZZ') {
            return $cid === 'K601' ? 65 : 64;
        }
        if (in_array($pcs, ['0DSB0ZZ', '0DMB0ZZ'], true)) {
            return $cid === 'Z932' ? 8 : null;
        }
        if (in_array($pcs, ['0DSM4ZZ', '0DMM4ZZ'], true)) {
            return $cid === 'Z933' ? 9 : null;
        }
        if (! in_array($pcs, self::EXCISOES, true)) {
            return null;
        }

        return match (true) {
            $cid === 'A630' => 127,
            $cid === 'K620' => 13,
            $cid === 'K644' => 115,
            str_starts_with($cid, 'K60') => 7,
            str_starts_with($cid, 'L05') => 52,
            str_starts_with($cid, 'K64') => 25,
            default => 119,
        };
    }

    /** O diagnóstico e a intervenção originais ficam sempre registados, mesmo quando mapeados. */
    private function comentario(array $p): string
    {
        $l = $p['linha'];

        return sprintf(
            "Importado da lista de espera do hospital.\nDiagnóstico: %s %s\nProcedimento: %s %s",
            $p['cid'], trim((string) ($l['DES_DIAGNOSTICO'] ?? '')), $p['pcs'], trim((string) ($l['DESIGNACAO'] ?? '')),
        );
    }

    private function semMapeamento(string $titulo, array $itens): void
    {
        if ($itens === []) {
            return;
        }
        $this->newLine();
        $this->line($titulo.':');
        arsort($itens);
        foreach ($itens as $nome => $n) {
            $this->line(sprintf('  %3d × %s', $n, $nome));
        }
    }

    private function chave(string $texto): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower(preg_replace('/[^A-Za-z ]/', '', Str::ascii($texto)))));
    }
}
