<?php

namespace App\Console\Commands;

use App\Models\Complicacao;
use App\Models\Internamento;
use App\Models\Morbilidade;
use App\Services\GuardarInternamento;
use App\Support\LeitorDeFolha;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Regista «sem complicações» no seguimento aos 30 dias dos internamentos que a base de morbilidade do serviço
 * (morbi) dá como revistos, sem complicações e vivos. O ficheiro só traz a chave (numero_processo, data_de_entrada);
 * cada internamento é revalidado aqui e só se grava quando:
 *  - existe um único utente com esse processo e um internamento com essa data de entrada;
 *  - foi operado, teve alta há mais de 30 dias e não faleceu;
 *  - ainda não tem seguimento aos 30 dias e não teve complicações na alta (ficam para revisão clínica).
 * A data do seguimento é a do 30.º dia depois da alta. Por omissão só mostra o que faria; --aplicar grava
 * (pelo mesmo circuito do seguimento na aplicação) e guarda um CSV com os registos criados, para se poder repor.
 */
class ImportarSemComplicacoes extends Command
{
    protected $signature = 'seguimentos:importar-sem-complicacoes
        {ficheiro : CSV com numero_processo e data_de_entrada}
        {--aplicar : Grava os seguimentos (sem isto só mostra o que faria)}';

    protected $description = 'Regista «sem complicações» aos 30 dias nos internamentos que o morbi dá como revistos e sem complicações';

    public function handle(GuardarInternamento $guardar): int
    {
        $chaves = [];
        foreach (LeitorDeFolha::linhas((string) $this->argument('ficheiro')) as $linha) {
            $processo = isset($linha['numero_processo']) ? (int) preg_replace('/\D/', '', $linha['numero_processo']) : 0;
            $entrada = LeitorDeFolha::data($linha['data_de_entrada'] ?? null);
            if ($processo > 0 && $entrada !== null) {
                $chaves["$processo|$entrada"] = [$processo, $entrada];
            }
        }
        if ($chaves === []) {
            $this->error('Não encontrei linhas com numero_processo e data_de_entrada: confirme o ficheiro.');

            return self::FAILURE;
        }

        $limite = now()->startOfDay()->subDays(30)->format('Y-m-d');
        $utentes = DB::table('utentes')->whereNull('deleted_at')->whereIn('numero_processo', array_unique(array_column($chaves, 0)))
            ->get(['id', 'numero_processo'])->groupBy('numero_processo');

        $criar = [];
        $descartes = ['sem utente' => 0, 'processo em mais de uma ficha' => 0, 'sem internamento nessa data' => 0, 'não operado' => 0,
            'sem alta ou alta há menos de 30 dias' => 0, 'falecido' => 0, 'já tem seguimento aos 30 dias' => 0, 'complicações na alta (rever)' => 0];
        foreach ($chaves as [$processo, $entrada]) {
            $fichas = $utentes->get($processo);
            if ($fichas === null) {
                $descartes['sem utente']++;

                continue;
            }
            if ($fichas->count() > 1) {
                $descartes['processo em mais de uma ficha']++;

                continue;
            }
            $internamentos = Internamento::query()->where('utente_id', $fichas->first()->id)->whereDate('data_de_entrada', $entrada)->get();
            if ($internamentos->count() !== 1) {
                $descartes['sem internamento nessa data']++;

                continue;
            }
            $i = $internamentos->first();
            if (! $i->temCirurgias()) {
                $descartes['não operado']++;
            } elseif ($i->data_de_alta === null || $i->data_de_alta->format('Y-m-d') > $limite) {
                $descartes['sem alta ou alta há menos de 30 dias']++;
            } elseif ((int) $i->destino_id === Internamento::DESTINO_FALECIDO) {
                $descartes['falecido']++;
            } elseif ($i->morbilidades()->where('momento', Morbilidade::AOS_30_DIAS)->exists()) {
                $descartes['já tem seguimento aos 30 dias']++;
            } elseif ($i->morbilidades()->where('momento', Morbilidade::NA_ALTA)->whereHas('complicacoes', fn ($q) => $q->where('complicacaos.id', '<>', Complicacao::SEM_COMPLICACOES))->exists()) {
                $descartes['complicações na alta (rever)']++;
            } else {
                $criar[] = $i;
            }
        }

        $this->info('Internamentos no ficheiro: '.count($chaves));
        foreach ($descartes as $motivo => $n) {
            $this->line("  ficam de fora — $motivo: $n");
        }
        $this->info('Seguimentos «sem complicações» a registar: '.count($criar));

        if (! $this->option('aplicar')) {
            $this->warn('Simulação: nada foi gravado. Use --aplicar para gravar.');

            return self::SUCCESS;
        }

        $log = "internamento_id,morbilidade_id,data_registo\n";
        foreach ($criar as $i) {
            $m = $guardar->registarSeguimento($i, [
                'data_registo' => $i->data_de_alta->copy()->addDays(30)->format('Y-m-d'),
                'complicacoes' => [['id' => Complicacao::SEM_COMPLICACOES, 'resolucoes' => []]],
            ]);
            $log .= "{$i->id},{$m->id},{$m->data_registo?->format('Y-m-d')}\n";
        }
        $caminho = 'seguimentos_sem_complicacoes_'.now()->format('Ymd_His').'.csv';
        Storage::put($caminho, $log);
        $this->info('Gravados: '.count($criar).'. Para repor, apague os registos listados em '.storage_path('app/private/'.$caminho).'.');

        return self::SUCCESS;
    }
}
