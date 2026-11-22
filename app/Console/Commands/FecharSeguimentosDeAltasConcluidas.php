<?php

namespace App\Console\Commands;

use App\Models\Complicacao;
use App\Models\Internamento;
use App\Models\Morbilidade;
use App\Services\GuardarInternamento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Regista «sem complicações» no seguimento aos 30 dias dos internamentos que a aplicação antiga já dava como analisados:
 * doentes vivos, operados, com a alta concluída e sem grau de Clavien-Dindo (na aplicação antiga o grau só se registava
 * aos 30 dias e quando havia complicações) cuja morbilidade da alta é «sem complicações».
 *
 * Fica de fora quem tem seguimento aos 30 dias, alta há menos de 30 dias ou qualquer complicação na alta.
 * A data do seguimento é a do 30.º dia depois da alta. Por omissão só mostra o que faria; --aplicar grava (pelo mesmo
 * circuito do seguimento na aplicação) e guarda um CSV com os registos criados, para se poder repor.
 */
class FecharSeguimentosDeAltasConcluidas extends Command
{
    protected $signature = 'seguimentos:fechar-altas-concluidas
        {--aplicar : Grava os seguimentos (sem isto só mostra o que faria)}';

    protected $description = 'Regista «sem complicações» aos 30 dias nos internamentos operados com alta concluída, sem grau e sem complicações na alta';

    public function handle(GuardarInternamento $guardar): int
    {
        $limite = now()->startOfDay()->subDays(30)->format('Y-m-d');
        $semComplicacoes = Complicacao::SEM_COMPLICACOES;

        $candidatos = Internamento::query()
            ->where('estado_da_alta_id', Internamento::ALTA_CONCLUIDA)
            ->whereNull('clavien_dindo_id')
            ->where(fn ($q) => $q->whereNull('destino_id')->orWhere('destino_id', '<>', Internamento::DESTINO_FALECIDO))
            ->whereNotNull('data_de_alta')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('bloco_operatorios as b')->whereColumn('b.internamento_id', 'internamentos.id')->whereNull('b.deleted_at'))
            ->whereDoesntHave('morbilidades', fn ($q) => $q->where('momento', Morbilidade::AOS_30_DIAS))
            ->get();

        $criar = [];
        $descartes = ['alta há menos de 30 dias' => 0, 'sem registo da alta' => 0, 'complicações na alta (rever)' => 0];
        foreach ($candidatos as $i) {
            $alta = $i->morbilidades()->where('momento', Morbilidade::NA_ALTA)->first();
            $ids = $alta?->complicacoes()->pluck('complicacaos.id')->map(fn ($id) => (int) $id)->all() ?? [];

            if ($i->data_de_alta->format('Y-m-d') > $limite) {
                $descartes['alta há menos de 30 dias']++;
            } elseif (! $alta || $ids === []) {
                $descartes['sem registo da alta']++;
            } elseif (array_diff($ids, [$semComplicacoes]) !== []) {
                $descartes['complicações na alta (rever)']++;
            } else {
                $criar[] = $i;
            }
        }

        $this->info('Internamentos operados, concluídos, sem grau e sem seguimento aos 30 dias: '.$candidatos->count());
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
                'complicacoes' => [['id' => $semComplicacoes, 'resolucoes' => []]],
            ]);
            $log .= "{$i->id},{$m->id},{$m->data_registo?->format('Y-m-d')}\n";
        }
        $caminho = 'seguimentos_altas_concluidas_'.now()->format('Ymd_His').'.csv';
        Storage::put($caminho, $log);
        $this->info('Gravados: '.count($criar).'. Para repor, apague os registos listados em '.storage_path('app/private/'.$caminho).'.');

        return self::SUCCESS;
    }
}
