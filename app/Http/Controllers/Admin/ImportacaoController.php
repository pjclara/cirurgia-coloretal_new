<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ImportadorListaDeEspera;
use App\Services\ImportadorReferenciacoes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importação por Excel para a administração (lista de espera e referenciações): carrega-se a folha, vê-se o que seria
 * criado (simulação) e só depois se confirma. As regras são as dos comandos `lista-espera:importar` e
 * `referenciacoes:importar` (ImportadorListaDeEspera e ImportadorReferenciacoes).
 */
class ImportacaoController extends Controller
{
    private const PASTA = 'importacoes';

    private const MOTIVOS_LISTA = [
        'ativa' => 'já com inscrição por operar', 'repetido' => 'processo repetido na folha', 'sem_medico' => 'sem médico reconhecido', 'sem_data' => 'sem data',
    ];

    private const MOTIVOS_REFERENCIACAO = [
        'futura' => 'data futura', 'sem_medico' => 'médico desconhecido', 'sem_data' => 'sem data', 'sem_nome' => 'sem nome',
        'ja_sinalizado' => 'já sinalizado no centro', 'ja_existe' => 'já tem referenciação nessa data', 'repetido' => 'repetido na folha',
    ];

    public function mostrar(string $tipo): Response
    {
        return Inertia::render('admin/importar', ['tipo' => $this->configuracao($tipo), 'previa' => session('previa')]);
    }

    /** Guarda a folha (só até se confirmar) e mostra o que seria importado. */
    public function previsualizar(Request $request, string $tipo): RedirectResponse
    {
        $this->configuracao($tipo);
        $request->validate(
            ['ficheiro' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120']],
            ['required' => 'Escolha o ficheiro.', 'mimes' => 'O ficheiro tem de ser .xlsx ou .csv.', 'max' => 'O ficheiro não pode passar de 5 MB.'],
        );

        $ficheiro = $request->file('ficheiro');
        $extensao = in_array(strtolower($ficheiro->getClientOriginalExtension()), ['csv', 'txt'], true) ? 'csv' : 'xlsx';
        $token = (string) Str::uuid();
        $caminho = Storage::disk('local')->putFileAs(self::PASTA, $ficheiro, "$token.$extensao");

        try {
            $previa = $this->previa($tipo, Storage::disk('local')->path($caminho));
        } catch (\Throwable) {
            Storage::disk('local')->delete($caminho);

            return back()->withErrors(['ficheiro' => 'Não consegui ler o ficheiro: confirme que é a folha certa.']);
        }
        if ($previa['linhas'] === 0) {
            Storage::disk('local')->delete($caminho);

            return back()->withErrors(['ficheiro' => 'Não encontrei linhas: confirme o ficheiro e os cabeçalhos.']);
        }

        return redirect()->route('admin.importar.mostrar', $tipo)->with('previa', $previa + ['token' => $token, 'ficheiro' => $ficheiro->getClientOriginalName()]);
    }

    /** Confirma: volta a analisar a folha guardada (a base pode ter mudado) e grava. */
    public function aplicar(Request $request, string $tipo): RedirectResponse
    {
        $this->configuracao($tipo);
        $caminho = $this->ficheiroGuardado($request);
        if ($caminho === null) {
            return redirect()->route('admin.importar.mostrar', $tipo)->withErrors(['ficheiro' => 'A folha já não está disponível: carregue-a de novo.']);
        }
        $ficheiro = Storage::disk('local')->path($caminho);

        if ($tipo === 'lista-de-espera') {
            $importador = app(ImportadorListaDeEspera::class);
            $analise = $importador->analisar($ficheiro);
            $csv = $importador->aplicar($analise['plano']);
            $nome = 'importacao_lista_espera_';
            $palavra = ['inscrição importada', 'inscrições importadas'];
        } else {
            $importador = app(ImportadorReferenciacoes::class);
            $analise = $importador->analisar($ficheiro);
            $csv = $importador->aplicar($analise['plano'], (int) $request->user()->id);
            $nome = 'importacao_referenciacoes_';
            $palavra = ['referenciação importada', 'referenciações importadas'];
        }
        Storage::disk('local')->put($nome.now()->format('Ymd_His').'.csv', $csv);
        Storage::disk('local')->delete($caminho);

        $n = count($analise['plano']);

        return redirect()->route('admin.importar.mostrar', $tipo)->with('sucesso', $n === 0
            ? 'Nada a importar: todas as linhas já existiam ou foram saltadas.'
            : "$n ".($n === 1 ? $palavra[0] : $palavra[1]).($analise['novos_utentes'] > 0 ? " ({$analise['novos_utentes']} utentes novos)." : '.'));
    }

    public function cancelar(Request $request, string $tipo): RedirectResponse
    {
        $this->configuracao($tipo);
        if ($caminho = $this->ficheiroGuardado($request)) {
            Storage::disk('local')->delete($caminho);
        }

        return redirect()->route('admin.importar.mostrar', $tipo);
    }

    /** O que se mostra na página de cada importação. */
    private function configuracao(string $tipo): array
    {
        return match ($tipo) {
            'lista-de-espera' => [
                'chave' => $tipo, 'titulo' => 'Importar lista de espera',
                'subtitulo' => 'Carregue a folha do hospital, confira o que seria criado e só depois confirme.',
                'colunas' => 'DTA_INSCR, UTENTE (n.º de processo), Nome, Data de nascimento, NOME_CLINICO, DIAG PRI, DES_DIAGNOSTICO, PROC_PRINC e DESIGNACAO.',
                'resultado' => 'inscrições a criar em estado Proposto (prioridade normal)',
            ],
            'referenciacoes' => [
                'chave' => $tipo, 'titulo' => 'Importar referenciações',
                'subtitulo' => 'Carregue a triagem das consultas (.xlsx com as duas folhas), confira o que seria criado e só depois confirme.',
                'colunas' => 'Processo, Nome, Localização, Médico e Data (na primeira folha também PROCESSO (consultas), UTENTE (consultas) e DTA_NASCIMENTO).',
                'resultado' => 'referenciações a criar (sinalização ao centro de referência)',
            ],
            default => abort(404),
        };
    }

    /** A simulação em forma comum às duas importações. */
    private function previa(string $tipo, string $ficheiro): array
    {
        if ($tipo === 'lista-de-espera') {
            $a = app(ImportadorListaDeEspera::class)->analisar($ficheiro);

            return [
                'linhas' => $a['linhas'], 'a_criar' => count($a['plano']), 'novos_utentes' => $a['novos_utentes'],
                'saltados' => $this->resumoSaltados($a['saltados'], self::MOTIVOS_LISTA),
                'sem_mapeamento' => [
                    ['titulo' => 'Diagnósticos sem correspondência (ficam nos comentários)', 'itens' => $a['sem_diagnostico']],
                    ['titulo' => 'Procedimentos sem correspondência (ficam nos comentários)', 'itens' => $a['sem_intervencao']],
                ],
                'saltados_detalhe' => [],
                'colunas' => [['chave' => 'data', 'rotulo' => 'Inscrição'], ['chave' => 'responsavel', 'rotulo' => 'Responsável'], ['chave' => 'diagnostico', 'rotulo' => 'Diagnóstico'], ['chave' => 'intervencao', 'rotulo' => 'Intervenção']],
                'amostra' => collect($a['plano'])->take(30)->map(fn ($p) => [
                    'processo' => $p['processo'], 'nome' => $p['nome'], 'utente_novo' => $p['utenteId'] === null,
                    'data' => $p['data'], 'responsavel' => $p['nome_medico'], 'diagnostico' => $p['nome_diagnostico'], 'intervencao' => $p['nome_intervencao'],
                ])->values()->all(),
            ];
        }

        $a = app(ImportadorReferenciacoes::class)->analisar($ficheiro);

        return [
            'linhas' => $a['linhas'], 'a_criar' => count($a['plano']), 'novos_utentes' => $a['novos_utentes'],
            'saltados' => $this->resumoSaltados($a['saltados'], self::MOTIVOS_REFERENCIACAO),
            'sem_mapeamento' => [['titulo' => 'Localizações sem diagnóstico CR (ficam só nos comentários)', 'itens' => $a['sem_diagnostico']]],
            // Os que se saltam por falta de dados (não por já existirem): a pessoa pode querer tratá-los à mão.
            'saltados_detalhe' => collect($a['lista_saltados'])->filter(fn ($s) => in_array($s['motivo'], ['sem_nome', 'sem_medico', 'futura', 'sem_data'], true))
                ->map(fn ($s) => ['nome' => $s['nome'], 'processo' => $s['processo'], 'data' => $s['data'], 'motivo' => self::MOTIVOS_REFERENCIACAO[$s['motivo']]])->values()->all(),
            'colunas' => [['chave' => 'data', 'rotulo' => 'Data'], ['chave' => 'responsavel', 'rotulo' => 'Responsável'], ['chave' => 'diagnostico', 'rotulo' => 'Diagnóstico CR'], ['chave' => 'localizacao', 'rotulo' => 'Localização na folha']],
            'amostra' => collect($a['plano'])->take(30)->map(fn ($p) => [
                'processo' => $p['processo'], 'nome' => $p['nome'], 'utente_novo' => $p['utenteId'] === null,
                'data' => $p['data'], 'responsavel' => $p['nome_medico'], 'diagnostico' => $p['nome_diagnostico'], 'localizacao' => $p['localizacao'],
            ])->values()->all(),
        ];
    }

    /** @return list<array{rotulo: string, n: int}> */
    private function resumoSaltados(array $saltados, array $motivos): array
    {
        return collect($saltados)->filter(fn ($n) => $n > 0)->map(fn ($n, $chave) => ['rotulo' => $motivos[$chave] ?? $chave, 'n' => $n])->values()->all();
    }

    /** A folha guardada para este token (só uuid válido, para não se sair da pasta). */
    private function ficheiroGuardado(Request $request): ?string
    {
        $token = (string) $request->input('token');
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $token)) {
            return null;
        }
        foreach (['xlsx', 'csv'] as $extensao) {
            $caminho = self::PASTA."/$token.$extensao";
            if (Storage::disk('local')->exists($caminho)) {
                return $caminho;
            }
        }

        return null;
    }
}
