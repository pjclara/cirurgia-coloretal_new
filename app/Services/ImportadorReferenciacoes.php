<?php

namespace App\Services;

use App\Models\Referenciacao;
use App\Models\User;
use App\Models\Utente;
use App\Support\DiagnosticosCr;
use App\Support\LeitorDeFolha;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa a lista de referenciação ao centro de referência (triagem das consultas, .xlsx com duas folhas: «Existe nas
 * consultas» e «Não existe nas consultas»). Colunas: Processo, Nome, Localização, Médico, Data (e, na primeira folha,
 * PROCESSO (consultas), UTENTE (consultas) e DTA_NASCIMENTO).
 *
 * Cada linha vira uma sinalização (referenciação rápida, origem «Outro») com a data da folha, o médico e o diagnóstico CR
 * que a localização indica (modo «Outro»); a localização original fica nos comentários. O utente acha-se pelo n.º de processo
 * (ou de utente) e, em último caso, pelo nome exato (só se for um único); se não existe, cria-se a ficha, mesmo sem n.º de
 * processo (completa-se depois na ficha). Saltam-se: datas futuras, médicos desconhecidos, quem já está sinalizado (sem saída
 * do centro), quem já tem referenciação nessa data e repetidos.
 */
class ImportadorReferenciacoes
{
    private const ORIGEM_OUTRO = 6;

    /** Médico da folha (sem acentos, minúsculas) => nome do utilizador. */
    private const MEDICOS = [
        'sa' => 'Sandra Amado', 'pc' => 'Paulo Clara', 'vera' => 'Vera Pedro', 'mic' => 'Maria Inês Coelho', 'jn' => 'João Nobre',
        'sales' => 'Inês Sales', 'sonia' => 'Sónia Martins', 'alexandra' => 'Alexandra Rocha', 'banza' => 'Rita Banza',
        'patricia' => 'Patrícia Bárbara', 'sofia dias' => 'Ana Sofia Dias',
    ];

    /** Há duas «Inês Sousa»: usa-se a que opera (id 34). */
    private const MEDICOS_POR_ID = ['sousa' => 34];

    /** Localização da folha (sem acentos, minúsculas) => diagnóstico CR (id de `diagnosticos`). */
    private const LOCALIZACOES = [
        'reto' => 1, 'recto' => 1,
        'sigmoide' => 16, 'sigmoide/esq' => 16,
        'ascendente' => 7, 'direito' => 7, 'direto' => 7, 'dt' => 7,
        'transverso' => 3,
        'descendente' => 12, 'esquerdo' => 12,
        'cego' => 37, 'apendice' => 69,
        'esplenico' => 76, 'angulo hepatico' => 34,
        'juncao retosigmoide' => 74,
        'canal anal' => 93, 'anal' => 93,
    ];

    /**
     * @return array{linhas: int, plano: list<array<string, mixed>>, saltados: array<string, int>, lista_saltados: list<array<string, mixed>>, novos_utentes: int, sem_diagnostico: array<string, int>}
     */
    public function analisar(string $ficheiro): array
    {
        $medicos = $this->medicos();
        $nomes = User::query()->whereIn('id', array_values($medicos))->pluck('name', 'id')->all();
        $diagnosticos = DB::table('diagnosticos')->where('centro_de_referencia', 1)->pluck('nome', 'id')->all();
        $porProcesso = Utente::withTrashed()->pluck('id', 'numero_processo')->all();
        $porUtente = Utente::withTrashed()->whereNotNull('numero_utente')->pluck('id', 'numero_utente')->all();
        // Por nome exato (sem acentos nem maiúsculas): só serve quando há um único utente com esse nome.
        $porNome = Utente::withTrashed()->get(['id', 'nome'])->groupBy(fn (Utente $u) => $this->chaveNome($u->nome))->map(fn ($g) => $g->pluck('id')->all())->all();
        $referenciacoes = DB::table('referenciacao_centro_de_referencias')->whereNull('deleted_at')->get(['utente_id', 'data_de_referenciacao', 'data_de_saida'])->groupBy('utente_id');

        $linhas = [];
        foreach ([0, 1] as $folha) {
            try {
                foreach (LeitorDeFolha::linhas($ficheiro, $folha) as $linha) {
                    $linhas[] = $linha;
                }
            } catch (\RuntimeException) {
                // Ficheiro .csv ou com uma só folha: lê-se o que houver.
                break;
            }
            if (strtolower(pathinfo($ficheiro, PATHINFO_EXTENSION)) === 'csv') {
                break;
            }
        }
        $linhas = array_values(array_filter($linhas, fn ($l) => $this->valor($l, 'nome') !== '' || $this->valor($l, 'processo') !== ''));

        $plano = [];
        $saltados = ['futura' => 0, 'sem_medico' => 0, 'sem_data' => 0, 'sem_nome' => 0, 'ja_sinalizado' => 0, 'ja_existe' => 0, 'repetido' => 0];
        $listaSaltados = [];
        $vistos = [];
        $semDiagnostico = [];
        $novosUtentes = 0;
        $hoje = now()->format('Y-m-d');
        $saltar = function (string $motivo, array $linha) use (&$saltados, &$listaSaltados) {
            $saltados[$motivo]++;
            $listaSaltados[] = ['motivo' => $motivo, 'nome' => $this->valor($linha, 'nome'), 'processo' => $this->valor($linha, 'processo'), 'data' => LeitorDeFolha::data($this->valor($linha, 'data') ?: null) ?? $this->valor($linha, 'data')];
        };

        foreach ($linhas as $linha) {
            $data = LeitorDeFolha::data($this->valor($linha, 'data') ?: null);
            if ($data === null) {
                $saltar('sem_data', $linha);

                continue;
            }
            if ($data > $hoje) {
                $saltar('futura', $linha);

                continue;
            }
            $chaveMedico = $this->chave($this->valor($linha, 'medico'));
            $medico = $medicos[$chaveMedico] ?? null;
            if ($medico === null) {
                $saltar('sem_medico', $linha);

                continue;
            }

            [$utenteId, $processoNovo, $numeroUtente] = $this->utente($linha, $porProcesso, $porUtente, $porNome);
            if ($utenteId === null && $this->valor($linha, 'nome') === '') {
                $saltar('sem_nome', $linha);

                continue;
            }
            $identidade = $utenteId ?? ($processoNovo ? 'p'.$processoNovo : ($numeroUtente ? 'u'.$numeroUtente : 'n'.$this->chaveNome($this->valor($linha, 'nome'))));
            if (isset($vistos[$identidade])) {
                $saltar('repetido', $linha);

                continue;
            }
            $vistos[$identidade] = true;

            if ($utenteId !== null) {
                $existentes = $referenciacoes->get($utenteId, collect());
                if ($existentes->contains(fn ($r) => substr((string) $r->data_de_referenciacao, 0, 10) === $data)) {
                    $saltar('ja_existe', $linha);

                    continue;
                }
                if ($existentes->contains(fn ($r) => $r->data_de_saida === null)) {
                    $saltar('ja_sinalizado', $linha);

                    continue;
                }
            }

            $localizacao = $this->valor($linha, 'localizacao');
            $diagnostico = self::LOCALIZACOES[$this->chave($localizacao)] ?? null;
            $diagnostico = isset($diagnosticos[$diagnostico]) ? $diagnostico : null;
            if ($diagnostico === null) {
                $chave = $localizacao === '' ? '(sem localização)' : $localizacao;
                $semDiagnostico[$chave] = ($semDiagnostico[$chave] ?? 0) + 1;
            }
            if ($utenteId === null) {
                $novosUtentes++;
            }

            $plano[] = [
                'utenteId' => $utenteId, 'processo' => $processoNovo ?? ($utenteId ? (array_search($utenteId, $porProcesso) ?: null) : null), 'numero_utente' => $numeroUtente,
                'sem_numero' => $utenteId === null && $processoNovo === null, 'numero_na_folha' => $this->valor($linha, 'processo_consultas') ?: $this->valor($linha, 'processo'),
                'nome' => $this->valor($linha, 'nome'), 'nascimento' => LeitorDeFolha::data($this->valor($linha, 'nascimento') ?: null, 1),
                'data' => $data, 'medico' => $medico, 'nome_medico' => $nomes[$medico] ?? '',
                'localizacao' => $localizacao, 'diagnostico' => $diagnostico, 'nome_diagnostico' => $diagnostico ? trim($diagnosticos[$diagnostico]) : null,
            ];
        }
        arsort($semDiagnostico);

        return [
            'linhas' => count($linhas), 'plano' => $plano, 'saltados' => $saltados, 'lista_saltados' => $listaSaltados,
            'novos_utentes' => $novosUtentes, 'sem_diagnostico' => $semDiagnostico,
        ];
    }

    /**
     * Grava o plano de `analisar`; devolve o CSV do que foi criado (para se poder desfazer).
     *
     * @param  list<array<string, mixed>>  $plano
     */
    public function aplicar(array $plano, int $autorId): string
    {
        $csv = "referenciacao_id;utente_id;processo;data_de_referenciacao;utente_criado\n";
        DB::transaction(function () use ($plano, $autorId, &$csv) {
            foreach ($plano as $p) {
                $criado = false;
                $utenteId = $p['utenteId'];
                if ($utenteId === null) {
                    $utente = Utente::create(array_filter([
                        'nome' => $p['nome'], 'numero_processo' => $p['processo'], 'numero_utente' => $p['numero_utente'], 'data_nascimento' => $p['nascimento'],
                    ], fn ($v) => $v !== null && $v !== ''));
                    $utenteId = $utente->id;
                    $criado = true;
                }

                $referenciacao = new Referenciacao([
                    'data_de_referenciacao' => $p['data'], 'origem_id' => self::ORIGEM_OUTRO, 'responsavel_id' => $p['medico'],
                    'comentarios' => "Importado da triagem das consultas.\nLocalização: ".($p['localizacao'] !== '' ? $p['localizacao'] : '—')
                        .($criado && $p['sem_numero'] ? "\nFicha criada sem n.º de processo (na folha: ".($p['numero_na_folha'] !== '' ? $p['numero_na_folha'] : 'nenhum').'): completar na ficha do utente.' : ''),
                ]);
                $referenciacao->utente_id = $utenteId;
                $referenciacao->created_by_id = $autorId;
                $referenciacao->save();
                if ($p['diagnostico']) {
                    DiagnosticosCr::associar($referenciacao, [$p['diagnostico']]);
                }
                $csv .= implode(';', [$referenciacao->id, $utenteId, $p['processo'], $p['data'], $criado ? 1 : 0]).";\n";
            }
        });

        return $csv;
    }

    /**
     * Acha o utente. Devolve [id existente | null, n.º de processo a criar | null, n.º de utente | null].
     * O n.º da coluna «Processo» pode ser de processo (até 8 dígitos) ou de utente (9 dígitos). Sem nenhum dos dois na base
     * procura-se pelo nome exato; se também não há, a ficha cria-se com o que a folha trouxer (pode ficar sem n.º de processo).
     *
     * @return array{0: int|null, 1: int|null, 2: int|null}
     */
    private function utente(array $linha, array $porProcesso, array $porUtente, array $porNome): array
    {
        $candidatos = array_values(array_filter([$this->valor($linha, 'processo_consultas'), $this->valor($linha, 'processo')]));
        $numeroUtente = $this->numeroDeUtente([...$candidatos, $this->valor($linha, 'utente_consultas')]);

        foreach ($candidatos as $c) {
            $n = (int) preg_replace('/\D/', '', $c);
            if ($n > 0 && isset($porProcesso[$n]) && strlen((string) $n) <= 8) {
                return [(int) $porProcesso[$n], null, null];
            }
        }
        if ($numeroUtente !== null && isset($porUtente[$numeroUtente])) {
            return [(int) $porUtente[$numeroUtente], null, null];
        }

        $porNomeExato = $porNome[$this->chaveNome($this->valor($linha, 'nome'))] ?? [];
        if (count($porNomeExato) === 1) {
            return [(int) $porNomeExato[0], null, null];
        }

        $processo = (int) preg_replace('/\D/', '', $this->valor($linha, 'processo_consultas') ?: $this->valor($linha, 'processo'));
        $processoValido = $processo > 0 && strlen((string) $processo) <= 8;

        return [null, $processoValido ? $processo : null, $numeroUtente];
    }

    /** O n.º de utente tem 9 dígitos (e cabe na coluna); outros comprimentos não são n.º de utente. */
    private function numeroDeUtente(array $candidatos): ?int
    {
        foreach ($candidatos as $c) {
            $n = preg_replace('/\D/', '', (string) $c);
            if (strlen($n) === 9) {
                return (int) $n;
            }
        }

        return null;
    }

    private function chaveNome(string $nome): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower(Str::ascii($nome))));
    }

    /** O valor de uma coluna pelo seu nome sem acentos nem maiúsculas (os cabeçalhos da folha variam). */
    private function valor(array $linha, string $coluna): string
    {
        static $alias = [
            'processo' => ['processo'], 'nome' => ['nome'], 'localizacao' => ['localizacao'], 'medico' => ['medico'], 'data' => ['data'],
            'processo_consultas' => ['processo (consultas)'], 'utente_consultas' => ['utente (consultas)'], 'nascimento' => ['dta_nascimento'],
        ];
        foreach ($linha as $cabecalho => $valor) {
            if (in_array($this->chaveCabecalho((string) $cabecalho), $alias[$coluna], true)) {
                return trim((string) $valor);
            }
        }

        return '';
    }

    private function chaveCabecalho(string $texto): string
    {
        return strtolower(trim(Str::ascii($texto)));
    }

    /** @return array<string, int> médico da folha (normalizado) => id do utilizador */
    private function medicos(): array
    {
        $porNome = User::query()->get(['id', 'name'])->groupBy(fn (User $u) => $this->chave($u->name))->map(fn ($g) => $g->first()->id);
        $mapa = [];
        foreach (self::MEDICOS as $abreviatura => $nome) {
            if ($porNome->has($this->chave($nome))) {
                $mapa[$abreviatura] = $porNome[$this->chave($nome)];
            }
        }
        foreach (self::MEDICOS_POR_ID as $abreviatura => $id) {
            $mapa[$abreviatura] = $id;
        }

        return $mapa;
    }

    /** Sem acentos, minúsculas e espaços simples; mantém «/» e dígitos para «sigmoide/esq». */
    private function chave(string $texto): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower(preg_replace('/[^A-Za-z0-9\/ ]/', '', Str::ascii($texto)))));
    }
}
