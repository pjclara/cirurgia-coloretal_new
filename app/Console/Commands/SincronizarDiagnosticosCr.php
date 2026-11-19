<?php

namespace App\Console\Commands;

use App\Support\DiagnosticosCr;
use Illuminate\Console\Command;

/** Sincroniza à mão os diagnósticos CR entre internamentos e referenciações (a aplicação já o faz a cada alteração). */
class SincronizarDiagnosticosCr extends Command
{
    protected $signature = 'diagnosticos-cr:sincronizar';

    protected $description = 'Sincroniza os diagnósticos CR entre os internamentos, as referenciações e a lista de espera de todos os utentes referenciados';

    public function handle(): int
    {
        $r = DiagnosticosCr::sincronizarTodos();
        $this->info("{$r['utentes']} utentes verificados; {$r['referenciacoes']} diagnósticos acrescentados a referenciações; {$r['internamentos']} internamentos e {$r['esperas']} inscrições em lista de espera atualizados.");

        return self::SUCCESS;
    }
}
