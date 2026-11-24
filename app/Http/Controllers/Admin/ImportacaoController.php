<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ImportadorListaDeEspera;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importação da lista de espera por Excel, para a administração: carrega-se a folha, vê-se o que seria criado
 * (simulação) e só depois se confirma. As regras são as do comando `lista-espera:importar` (ImportadorListaDeEspera).
 */
class ImportacaoController extends Controller
{
    private const PASTA = 'importacoes';

    public function listaDeEspera(): Response
    {
        return Inertia::render('admin/importar-lista-de-espera', ['previa' => session('previa')]);
    }

    /** Guarda a folha (só até se confirmar) e mostra o que seria importado. */
    public function previsualizar(Request $request, ImportadorListaDeEspera $importador): RedirectResponse
    {
        $request->validate(
            ['ficheiro' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120']],
            ['required' => 'Escolha o ficheiro.', 'mimes' => 'O ficheiro tem de ser .xlsx ou .csv.', 'max' => 'O ficheiro não pode passar de 5 MB.'],
        );

        $ficheiro = $request->file('ficheiro');
        $extensao = strtolower($ficheiro->getClientOriginalExtension()) === 'csv' ? 'csv' : (strtolower($ficheiro->getClientOriginalExtension()) === 'txt' ? 'csv' : 'xlsx');
        $token = (string) Str::uuid();
        $caminho = Storage::disk('local')->putFileAs(self::PASTA, $ficheiro, "$token.$extensao");

        try {
            $analise = $importador->analisar(Storage::disk('local')->path($caminho));
        } catch (\Throwable) {
            Storage::disk('local')->delete($caminho);

            return back()->withErrors(['ficheiro' => 'Não consegui ler o ficheiro: confirme que é a folha da lista de espera.']);
        }
        if ($analise['linhas'] === 0) {
            Storage::disk('local')->delete($caminho);

            return back()->withErrors(['ficheiro' => 'Não encontrei linhas com a coluna UTENTE: confirme o ficheiro e os cabeçalhos.']);
        }

        return redirect()->route('admin.importar.lista-de-espera')->with('previa', [
            'token' => $token,
            'ficheiro' => $ficheiro->getClientOriginalName(),
            'linhas' => $analise['linhas'],
            'a_criar' => count($analise['plano']),
            'novos_utentes' => $analise['novos_utentes'],
            'saltados' => $analise['saltados'],
            'sem_diagnostico' => $analise['sem_diagnostico'],
            'sem_intervencao' => $analise['sem_intervencao'],
            'amostra' => collect($analise['plano'])->take(30)->map(fn ($p) => [
                'processo' => $p['processo'],
                'nome' => $p['nome'],
                'data' => $p['data'],
                'responsavel' => $p['nome_medico'],
                'diagnostico' => $p['nome_diagnostico'],
                'intervencao' => $p['nome_intervencao'],
                'utente_novo' => $p['utenteId'] === null,
            ])->values(),
        ]);
    }

    /** Confirma: volta a analisar a folha guardada (a base pode ter mudado) e grava. */
    public function aplicar(Request $request, ImportadorListaDeEspera $importador): RedirectResponse
    {
        $caminho = $this->ficheiroGuardado($request);
        if ($caminho === null) {
            return redirect()->route('admin.importar.lista-de-espera')->withErrors(['ficheiro' => 'A folha já não está disponível: carregue-a de novo.']);
        }

        $analise = $importador->analisar(Storage::disk('local')->path($caminho));
        $csv = $importador->aplicar($analise['plano']);
        Storage::disk('local')->put('importacao_lista_espera_'.now()->format('Ymd_His').'.csv', $csv);
        Storage::disk('local')->delete($caminho);

        $n = count($analise['plano']);

        return redirect()->route('admin.importar.lista-de-espera')->with('sucesso', $n === 0
            ? 'Nada a importar: todas as linhas já estavam inscritas ou foram saltadas.'
            : ($n === 1 ? '1 inscrição importada' : "$n inscrições importadas").($analise['novos_utentes'] > 0 ? " ({$analise['novos_utentes']} utentes novos)." : '.'));
    }

    public function cancelar(Request $request): RedirectResponse
    {
        if ($caminho = $this->ficheiroGuardado($request)) {
            Storage::disk('local')->delete($caminho);
        }

        return redirect()->route('admin.importar.lista-de-espera');
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
