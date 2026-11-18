<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Referencia ao centro de referência os doentes da triagem cruzada (`excels/triagem_cruzada.csv`:
 * Nome, NUM_PROCESSO_consultas, Medico, Data).
 *
 * Como se faz:
 *  - o doente identifica-se pelo número de processo (o nome não conta); sem utente, ou com mais de um utente com esse
 *    processo, fica por importar;
 *  - quem já tem uma referenciação (ativa ou não) não se toca;
 *  - cria uma referenciação com a data do ficheiro (a mais antiga, se o processo aparece mais vezes); datas vazias,
 *    inválidas, futuras ou anteriores à data mínima da aplicação deixam o doente por importar;
 *  - origem «Outro», responsável pelo médico do ficheiro (abreviatura, iniciais ou nome; se não houver correspondência
 *    única, o superAdmin) e um comentário a dizer de onde veio;
 *  - o que ficou por importar e porquê vai para `storage/app/private/importacoes/` (sem nomes, só processos).
 *
 * O ficheiro tem dados de doentes e NÃO está no git: copie-o para `excels/` (ou indique o caminho em
 * CLINICO_TRIAGEM_CSV) antes de migrar. Sem ficheiro, a migração não faz nada. Pode correr-se mais do que uma vez.
 *
 * Reversível: guarda as referenciações criadas em `triagem_importada` e o `down` apaga só essas.
 * Faça um backup da base de dados antes de correr.
 */
return new class extends Migration
{
    private const ORIGEM_OUTRO = 6;

    /** Caminho do CSV; por omissão o do ambiente ou `excels/triagem_cruzada.csv`. */
    public ?string $caminho = null;

    public function up(): void
    {
        $ficheiro = $this->caminho ?? env('CLINICO_TRIAGEM_CSV', base_path('excels/triagem_cruzada.csv'));

        if (! is_file($ficheiro)) {
            echo "  Triagem cruzada: ficheiro não encontrado ($ficheiro); nada a importar.\n";

            return;
        }
        foreach (['utentes', 'referenciacao_centro_de_referencias', 'origem_da_referenciacaos', 'users'] as $tabela) {
            if (! Schema::hasTable($tabela)) {
                return;
            }
        }

        if (! Schema::hasTable('triagem_importada')) {
            Schema::create('triagem_importada', function (Blueprint $tabela) {
                $tabela->unsignedBigInteger('referenciacao_id')->primary();
                $tabela->timestamp('importada_em')->useCurrent();
            });
        }

        [$linhas, $descartadas] = $this->ler($ficheiro);
        $porImportar = [];
        $origem = DB::table('origem_da_referenciacaos')->where('id', self::ORIGEM_OUTRO)->value('id') ?? DB::table('origem_da_referenciacaos')->min('id');
        $substituto = $this->responsavelPorOmissao();
        $utilizadores = DB::table('users')->get(['id', 'name', 'abrev', 'username']);

        $criadas = 0;
        foreach ($linhas as $processo => $linha) {
            $utentes = DB::table('utentes')->whereNull('deleted_at')->where('numero_processo', $processo)->pluck('id');
            if ($utentes->count() !== 1) {
                $descartadas[] = [$processo, $utentes->isEmpty() ? 'sem utente com este processo' : 'vários utentes com este processo'];

                continue;
            }
            $utente = $utentes->first();
            if (DB::table('referenciacao_centro_de_referencias')->whereNull('deleted_at')->where('utente_id', $utente)->exists()) {
                continue; // já referenciado
            }

            $responsavel = $this->responsavel($linha['medico'], $utilizadores) ?? $substituto;
            $id = DB::table('referenciacao_centro_de_referencias')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'utente_id' => $utente,
                'data_de_referenciacao' => $linha['data'],
                'origem_id' => $origem,
                'responsavel_id' => $responsavel,
                'comentarios' => 'Importado da triagem cruzada (consulta de '.$linha['data'].', médico: '.$linha['medico'].').',
                'created_by_id' => $responsavel,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('triagem_importada')->insert(['referenciacao_id' => $id]);
            $criadas++;
        }

        $relatorio = $this->gravarRelatorio($descartadas);
        echo "  Triagem cruzada: $criadas referenciações criadas; ".count($descartadas)." linhas por importar".($relatorio ? " (ver $relatorio)" : '').".\n";
    }

    public function down(): void
    {
        if (! Schema::hasTable('triagem_importada')) {
            return;
        }

        $ids = DB::table('triagem_importada')->pluck('referenciacao_id');
        DB::table('diagnostico_referenciacao')->whereIn('referenciacao_id', $ids)->delete();
        DB::table('referenciacao_centro_de_referencias')->whereIn('id', $ids)->delete();
        Schema::drop('triagem_importada');
    }

    /**
     * Uma entrada por processo, com a data mais antiga e o respetivo médico.
     *
     * @return array{0: array<int, array{data: string, medico: string}>, 1: list<array{0: mixed, 1: string}>}
     */
    private function ler(string $ficheiro): array
    {
        $minima = config('clinico.data_minima', '2021-01-01');
        $hoje = now()->format('Y-m-d');
        $linhas = [];
        $descartadas = [];

        $f = fopen($ficheiro, 'r');
        $cabecalho = array_map(fn ($c) => trim($c, "\xEF\xBB\xBF \t"), fgetcsv($f) ?: []);
        while (($r = fgetcsv($f)) !== false) {
            if ($r === [null] || count($r) < count($cabecalho)) {
                continue;
            }
            $l = array_combine($cabecalho, array_slice($r, 0, count($cabecalho)));
            $processo = trim($l['NUM_PROCESSO_consultas'] ?? '');
            $data = trim($l['Data'] ?? '');
            if (! ctype_digit($processo)) {
                $descartadas[] = [$processo, 'processo inválido'];

                continue;
            }
            $valida = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) && checkdate((int) substr($data, 5, 2), (int) substr($data, 8, 2), (int) substr($data, 0, 4));
            if (! $valida || $data < $minima || $data > $hoje) {
                $descartadas[] = [$processo, 'data vazia, inválida ou fora do intervalo'];

                continue;
            }
            $processo = (int) $processo;
            if (! isset($linhas[$processo]) || $data < $linhas[$processo]['data']) {
                $linhas[$processo] = ['data' => $data, 'medico' => trim($l['Medico'] ?? '')];
            }
        }
        fclose($f);

        return [$linhas, $descartadas];
    }

    /** O utilizador a que o médico do ficheiro corresponde: abreviatura, iniciais, nome próprio, apelido ou nome completo; só se for único. */
    private function responsavel(string $medico, $utilizadores): ?int
    {
        $m = Str::lower(Str::ascii($medico));
        if ($m === '') {
            return null;
        }
        $normal = fn ($t) => Str::lower(Str::ascii(trim((string) $t)));
        $iniciais = fn ($nome) => collect(preg_split('/\s+/', $normal($nome)))->filter()->pipe(fn ($p) => $p->count() > 1 ? $p->first()[0].$p->last()[0] : ($p->first()[0] ?? ''));

        $regras = [
            fn ($u) => $normal($u->abrev) === $m || $normal($u->username) === $m,
            fn ($u) => $iniciais($u->name) === $m,
            fn ($u) => $normal($u->name) === $m || Str::startsWith($normal($u->name), $m.' '),
            fn ($u) => Str::endsWith($normal($u->name), ' '.$m),
        ];
        foreach ($regras as $regra) {
            $achados = $utilizadores->filter($regra);
            if ($achados->count() === 1) {
                return (int) $achados->first()->id;
            }
        }

        return null;
    }

    /** O superAdmin (ou o primeiro utilizador) para quando o médico não corresponde a ninguém. */
    private function responsavelPorOmissao(): int
    {
        $super = Schema::hasTable('roles') ? DB::table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')
            ->where('r.name', 'superAdmin')->orderBy('m.model_id')->value('m.model_id') : null;

        return (int) ($super ?? DB::table('users')->min('id'));
    }

    /** @param list<array{0: mixed, 1: string}> $descartadas */
    private function gravarRelatorio(array $descartadas): ?string
    {
        if ($descartadas === []) {
            return null;
        }
        $pasta = storage_path('app/private/importacoes');
        if (! is_dir($pasta)) {
            mkdir($pasta, 0775, true);
        }
        $caminho = $pasta.'/triagem_cruzada_por_importar_'.Carbon::now()->format('Ymd_His').'.csv';
        $f = fopen($caminho, 'w');
        fputcsv($f, ['processo', 'motivo']);
        foreach ($descartadas as $linha) {
            fputcsv($f, $linha);
        }
        fclose($f);

        return $caminho;
    }
};
