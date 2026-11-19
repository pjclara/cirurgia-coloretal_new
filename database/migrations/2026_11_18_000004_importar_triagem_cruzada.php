<?php

use App\Models\Utente;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Referencia ao centro de referência os doentes da triagem (`excels/triagem_proxima_consulta.csv`: Nome,
 * NUM_PROCESSO_consultas, Medico, Data_referenciacao e, da consulta seguinte, Data_consulta_seguinte, Tipo_consulta,
 * Valencia, Clinico, Hospital, Dias_ate_consulta e Nota). Aceita também o formato antigo `triagem_cruzada.csv`, com `Data`.
 *
 * Como se faz:
 *  - o doente identifica-se pelo número de processo; se não existir nenhum utente com esse processo, cria-se um com o
 *    nome do ficheiro e sem data de nascimento, sexo e concelho (a completar na ficha; ver a migração anterior); com mais
 *    de um utente com esse processo, fica por importar;
 *  - quem já tem uma referenciação (ativa ou não) não é referenciado de novo: só se lhe preenche a data da consulta
 *    seguinte, se estiver vazia (a referenciação mais recente);
 *  - cria uma referenciação com a data de referenciação do ficheiro (a mais antiga, se o processo aparece mais vezes); datas vazias,
 *    inválidas, futuras ou anteriores à data mínima da aplicação deixam o doente por importar;
 *  - origem «Outro», responsável pelo médico do ficheiro (abreviatura, iniciais ou nome; senão o clínico da consulta
 *    seguinte; se não houver correspondência única, o superAdmin) e um comentário a dizer de onde veio, com a consulta
 *    seguinte (data, tipo, valência, clínico, hospital) e a nota, quando existem;
 *  - o que ficou por importar e porquê vai para `storage/app/private/importacoes/` (sem nomes, só processos).
 *
 * Com várias contas a corresponder ao mesmo médico, conta só a ativa.
 *
 * O ficheiro tem dados de doentes e NÃO está no git: copie-o para `excels/` (ou indique o caminho em
 * CLINICO_TRIAGEM_CSV) antes de migrar. Sem ficheiro, a migração não faz nada. Pode correr-se mais do que uma vez.
 *
 * Reversível: guarda em `triagem_importada` as referenciações criadas e as que só receberam a data da consulta seguinte,
 * e em `triagem_utentes_criados` os utentes criados; o `down` apaga as primeiras, limpa a data das segundas e apaga os
 * utentes criados que ainda não tenham registos próprios.
 * Faça um backup da base de dados antes de correr.
 */
return new class extends Migration
{
    private const ORIGEM_OUTRO = 6;

    /** Caminho do CSV; por omissão o do ambiente ou `excels/triagem_cruzada.csv`. */
    public ?string $caminho = null;

    public function up(): void
    {
        $ficheiro = $this->caminho ?? env('CLINICO_TRIAGEM_CSV') ?? collect(['triagem_proxima_consulta.csv', 'triagem_cruzada.csv'])
            ->map(fn ($nome) => base_path("excels/$nome"))->first(fn ($c) => is_file($c), base_path('excels/triagem_proxima_consulta.csv'));

        if (! is_file($ficheiro)) {
            echo "  Triagem: ficheiro não encontrado ($ficheiro); nada a importar.\n";

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
                $tabela->string('acao', 10); // criada | data
                $tabela->timestamp('importada_em')->useCurrent();
            });
        }

        if (! Schema::hasTable('triagem_utentes_criados')) {
            Schema::create('triagem_utentes_criados', function (Blueprint $tabela) {
                $tabela->unsignedBigInteger('utente_id')->primary();
                $tabela->timestamp('criado_em')->useCurrent();
            });
        }

        [$linhas, $descartadas, $nomes] = $this->ler($ficheiro);
        $utentesCriados = $this->criarUtentesEmFalta($nomes, $descartadas);
        $porImportar = [];
        $origem = DB::table('origem_da_referenciacaos')->where('id', self::ORIGEM_OUTRO)->value('id') ?? DB::table('origem_da_referenciacaos')->min('id');
        $substituto = $this->responsavelPorOmissao();
        $utilizadores = DB::table('users')->get(['id', 'name', 'abrev', 'username', 'activo']);

        $comData = Schema::hasColumn('referenciacao_centro_de_referencias', 'data_consulta_seguinte');
        $criadas = 0;
        $atualizadas = 0;
        foreach ($linhas as $processo => $linha) {
            $utentes = DB::table('utentes')->whereNull('deleted_at')->where('numero_processo', $processo)->pluck('id');
            if ($utentes->count() !== 1) {
                $descartadas[] = [$processo, $utentes->isEmpty() ? 'sem utente com este processo' : 'vários utentes com este processo'];

                continue;
            }
            $utente = $utentes->first();
            $existente = DB::table('referenciacao_centro_de_referencias')->whereNull('deleted_at')->where('utente_id', $utente)
                ->orderByDesc('data_de_referenciacao')->orderByDesc('id')->first();
            if ($existente) {
                // Já referenciado: só se preenche a data da consulta seguinte, se estiver vazia.
                if ($comData && $linha['proxima'] && $existente->data_consulta_seguinte === null) {
                    DB::table('referenciacao_centro_de_referencias')->where('id', $existente->id)->update(['data_consulta_seguinte' => $linha['proxima'], 'updated_at' => now()]);
                    DB::table('triagem_importada')->insertOrIgnore(['referenciacao_id' => $existente->id, 'acao' => 'data']);
                    $atualizadas++;
                }

                continue;
            }

            $responsavel = $this->responsavel($linha['medico'], $utilizadores) ?? $this->responsavelPorNomeCompleto($linha['clinico'], $utilizadores) ?? $substituto;
            if (! $responsavel) {
                $descartadas[] = [$processo, 'sem utilizador a quem atribuir a referenciação'];

                continue;
            }
            $id = DB::table('referenciacao_centro_de_referencias')->insertGetId(($comData ? ['data_consulta_seguinte' => $linha['proxima']] : []) + [
                'uuid' => (string) Str::uuid(),
                'utente_id' => $utente,
                'data_de_referenciacao' => $linha['data'],
                'origem_id' => $origem,
                'responsavel_id' => $responsavel,
                'comentarios' => $this->comentario($linha),
                'created_by_id' => $responsavel,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('triagem_importada')->insert(['referenciacao_id' => $id, 'acao' => 'criada']);
            $criadas++;
        }

        $relatorio = $this->gravarRelatorio($descartadas);
        echo "  Triagem: $utentesCriados utentes criados, $criadas referenciações criadas, $atualizadas com a data da consulta seguinte preenchida; ".count($descartadas)." linhas por importar".($relatorio ? " (ver $relatorio)" : '').".\n";
    }

    public function down(): void
    {
        if (! Schema::hasTable('triagem_importada')) {
            return;
        }

        $criadas = DB::table('triagem_importada')->where('acao', 'criada')->pluck('referenciacao_id');
        if (Schema::hasColumn('referenciacao_centro_de_referencias', 'data_consulta_seguinte')) {
            DB::table('referenciacao_centro_de_referencias')->whereIn('id', DB::table('triagem_importada')->where('acao', 'data')->pluck('referenciacao_id'))
                ->update(['data_consulta_seguinte' => null]);
        }
        DB::table('diagnostico_referenciacao')->whereIn('referenciacao_id', $criadas)->delete();
        DB::table('referenciacao_centro_de_referencias')->whereIn('id', $criadas)->delete();
        Schema::drop('triagem_importada');

        if (Schema::hasTable('triagem_utentes_criados')) {
            foreach (DB::table('triagem_utentes_criados')->pluck('utente_id') as $utente) {
                try {
                    DB::table('utentes')->where('id', $utente)->delete();
                } catch (\Throwable) {
                    // já tem registos próprios (internamentos, lista de espera…): fica.
                }
            }
            Schema::drop('triagem_utentes_criados');
        }
    }

    /**
     * Uma entrada por processo, com a data mais antiga e o respetivo médico.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: list<array{0: mixed, 1: string}>, 2: array<int, string>}
     */
    private function ler(string $ficheiro): array
    {
        $minima = config('clinico.data_minima', '2021-01-01');
        $hoje = now()->format('Y-m-d');
        $linhas = [];
        $descartadas = [];
        $nomes = [];

        $f = fopen($ficheiro, 'r');
        $cabecalho = array_map(fn ($c) => trim($c, "\xEF\xBB\xBF \t"), fgetcsv($f) ?: []);
        while (($r = fgetcsv($f)) !== false) {
            if ($r === [null] || count($r) < count($cabecalho)) {
                continue;
            }
            $l = array_combine($cabecalho, array_slice($r, 0, count($cabecalho)));
            $processo = trim($l['NUM_PROCESSO_consultas'] ?? '');
            $data = trim($l['Data_referenciacao'] ?? $l['Data'] ?? '');
            if (! ctype_digit($processo)) {
                $descartadas[] = [$processo, 'processo inválido'];

                continue;
            }
            $nome = Utente::normalizarNome($l['Nome'] ?? '');
            if (strlen($nome) > strlen($nomes[(int) $processo] ?? '')) {
                $nomes[(int) $processo] = $nome; // o nome mais completo, se o processo aparece com vários
            }
            $valida = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) && checkdate((int) substr($data, 5, 2), (int) substr($data, 8, 2), (int) substr($data, 0, 4));
            if (! $valida || $data < $minima || $data > $hoje) {
                $descartadas[] = [$processo, 'data vazia, inválida ou fora do intervalo'];

                continue;
            }
            $processo = (int) $processo;
            if (! isset($linhas[$processo]) || $data < $linhas[$processo]['data']) {
                $linhas[$processo] = [
                    'data' => $data,
                    'medico' => trim($l['Medico'] ?? ''),
                    'clinico' => trim($l['Clinico'] ?? ''),
                    'consulta' => array_filter([
                        'data' => trim($l['Data_consulta_seguinte'] ?? ''),
                        'tipo' => trim($l['Tipo_consulta'] ?? ''),
                        'valencia' => Str::title(Str::lower(trim($l['Valencia'] ?? ''))),
                        'clinico' => Str::title(Str::lower(trim($l['Clinico'] ?? ''))),
                        'hospital' => trim($l['Hospital'] ?? ''),
                    ]),
                    'nota' => trim($l['Nota'] ?? ''),
                    'proxima' => $this->dataValida(trim($l['Data_consulta_seguinte'] ?? ''), $minima) ? trim($l['Data_consulta_seguinte']) : null,
                ];
            }
        }
        fclose($f);

        return [$linhas, $descartadas, $nomes];
    }

    /**
     * Cria os utentes cujo processo ainda não existe (nome do ficheiro; data de nascimento, sexo e concelho por preencher).
     * Só se a base permitir esses campos vazios; senão fica por importar.
     *
     * @param  array<int, string>  $nomes
     * @param  list<array{0: mixed, 1: string}>  $descartadas
     */
    private function criarUtentesEmFalta(array $nomes, array &$descartadas): int
    {
        $aceitaVazios = collect(['data_nascimento', 'sexo_id', 'concelho_id'])->every(fn ($c) => $this->aceitaNulo('utentes', $c));
        $criados = 0;

        foreach ($nomes as $processo => $nome) {
            if ($nome === '' || DB::table('utentes')->whereNull('deleted_at')->where('numero_processo', $processo)->exists()) {
                continue;
            }
            if (! $aceitaVazios) {
                $descartadas[] = [$processo, 'utente por criar: a base exige data de nascimento, sexo e concelho'];

                continue;
            }
            $id = DB::table('utentes')->insertGetId([
                'nome' => $nome,
                'slug' => Utente::nomeCurto($nome),
                'numero_processo' => $processo,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('triagem_utentes_criados')->insert(['utente_id' => $id]);
            $criados++;
        }

        return $criados;
    }

    private function aceitaNulo(string $tabela, string $coluna): bool
    {
        return Schema::hasColumn($tabela, $coluna) && collect(Schema::getColumns($tabela))->firstWhere('name', $coluna)['nullable'] === true;
    }

    /** Data Y-m-d real e não anterior à data mínima da aplicação (a consulta seguinte pode ser futura). */
    private function dataValida(string $data, string $minima): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) === 1 && checkdate((int) substr($data, 5, 2), (int) substr($data, 8, 2), (int) substr($data, 0, 4)) && $data >= $minima;
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
            $achados = $this->unicoOuAtivo($utilizadores->filter($regra));
            if ($achados->count() === 1) {
                return (int) $achados->first()->id;
            }
        }

        return null;
    }

    /** O clínico da consulta (nome completo em maiúsculas, ex.: «INES FERRER SALES») cujo nome todo está contido nele; só se for único. */
    private function responsavelPorNomeCompleto(string $clinico, $utilizadores): ?int
    {
        $palavras = fn ($t) => collect(preg_split('/\s+/', Str::lower(Str::ascii(trim((string) $t)))))->filter()->all();
        $do_ficheiro = $palavras($clinico);
        if (count($do_ficheiro) < 2) {
            return null;
        }
        $achados = $utilizadores->filter(function ($u) use ($palavras, $do_ficheiro) {
            $nome = $palavras($u->name);

            return count($nome) >= 2 && array_diff($nome, $do_ficheiro) === [];
        });

        $achados = $this->unicoOuAtivo($achados);

        return $achados->count() === 1 ? (int) $achados->first()->id : null;
    }

    /** Com várias contas a corresponder (ex.: uma antiga e uma ativa com o mesmo nome), ficam só as ativas. */
    private function unicoOuAtivo($achados)
    {
        return $achados->count() > 1 ? $achados->filter(fn ($u) => (bool) $u->activo)->values() : $achados;
    }

    /** @param array<string, mixed> $linha */
    private function comentario(array $linha): string
    {
        $texto = 'Importado da triagem (referenciação em '.$linha['data'].($linha['medico'] !== '' ? ', médico: '.$linha['medico'] : '').').';
        $c = $linha['consulta'];
        if ($c !== []) {
            $texto .= ' Consulta seguinte: '.collect([$c['data'] ?? null, $c['tipo'] ?? null, $c['valencia'] ?? null, $c['clinico'] ?? null, $c['hospital'] ?? null])->filter()->implode(', ').'.';
        }
        if ($linha['nota'] !== '') {
            $texto .= ' Nota: '.$linha['nota'].'.';
        }

        return $texto;
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
