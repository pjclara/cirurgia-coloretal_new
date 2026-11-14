<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lista os registos que precisam de correção manual.
 * Não altera nada: decidir o ano certo ou qual das fichas duplicadas fica é uma decisão clínica.
 *
 *   php artisan dados:verificar
 *   php artisan dados:verificar --csv=storage/app/verificacao.csv
 */
class VerificarDados extends Command
{
    protected $signature = 'dados:verificar {--csv= : Caminho de um ficheiro CSV para gravar a lista completa}';

    protected $description = 'Lista datas impossíveis, fichas duplicadas e incoerências nos registos clínicos';

    public function handle(): int
    {
        $minima = config('clinico.data_minima');
        $hoje = now()->format('Y-m-d');
        $achados = [];

        $registar = function (string $problema, string $tabela, iterable $linhas, string $coluna) use (&$achados) {
            foreach ($linhas as $linha) {
                $achados[] = [$problema, $tabela, $linha->id, (string) ($linha->{$coluna} ?? '')];
            }
        };

        $registar('Data de entrada fora do intervalo', 'internamentos',
            DB::table('internamentos')->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('data_de_entrada', '<', $minima)->orWhere('data_de_entrada', '>', $hoje))
                ->get(['id', 'data_de_entrada']), 'data_de_entrada');

        $registar('Data de alta anterior à entrada ou no futuro', 'internamentos',
            DB::table('internamentos')->whereNull('deleted_at')->whereNotNull('data_de_alta')
                ->where(fn ($q) => $q->whereColumn('data_de_alta', '<', 'data_de_entrada')->orWhere('data_de_alta', '>', $hoje))
                ->get(['id', 'data_de_alta']), 'data_de_alta');

        $registar('Data de cirurgia fora do intervalo', 'bloco_operatorios',
            DB::table('bloco_operatorios')->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('data_de_inicio', '<', $minima)->orWhere('data_de_inicio', '>', $hoje))
                ->get(['id', 'data_de_inicio']), 'data_de_inicio');

        $registar('Cirurgia fora do período do internamento', 'bloco_operatorios',
            DB::table('bloco_operatorios as b')
                ->join('internamentos as i', 'i.id', '=', 'b.internamento_id')
                ->whereNull('b.deleted_at')
                ->where('i.data_de_entrada', '>=', $minima)
                ->where(fn ($q) => $q->whereColumn('b.data_de_inicio', '<', 'i.data_de_entrada')
                    ->orWhere(fn ($s) => $s->whereNotNull('i.data_de_alta')->whereColumn('b.data_de_inicio', '>', 'i.data_de_alta')))
                ->get(['b.id', 'b.data_de_inicio']), 'data_de_inicio');

        $registar('Data de inscrição em lista de espera fora do intervalo', 'lista_de_esperas',
            DB::table('lista_de_esperas')->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('data_de_lista', '<', '2015-01-01')->orWhere('data_de_lista', '>', $hoje))
                ->get(['id', 'data_de_lista']), 'data_de_lista');

        $registar('Data de nascimento impossível', 'utentes',
            DB::table('utentes')->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('data_nascimento', '<', '1900-01-01')->orWhere('data_nascimento', '>', $hoje))
                ->get(['id', 'data_nascimento']), 'data_nascimento');

        $registar('Entrada anterior ao nascimento', 'internamentos',
            DB::table('internamentos as i')->join('utentes as u', 'u.id', '=', 'i.utente_id')
                ->whereNull('i.deleted_at')->whereColumn('i.data_de_entrada', '<', 'u.data_nascimento')
                ->get(['i.id', 'i.data_de_entrada']), 'data_de_entrada');

        foreach (['numero_processo', 'numero_utente'] as $coluna) {
            $repetidos = DB::table('utentes')->whereNull('deleted_at')->whereNotNull($coluna)
                ->groupBy($coluna)->havingRaw('COUNT(*) > 1')->pluck($coluna);

            $registar("Fichas com o mesmo $coluna", 'utentes',
                DB::table('utentes')->whereNull('deleted_at')->whereIn($coluna, $repetidos)->orderBy($coluna)->get(['id', $coluna]), $coluna);
        }

        $registar('Número de utente sem 9 dígitos', 'utentes',
            DB::table('utentes')->whereNull('deleted_at')->whereNotNull('numero_utente')
                ->whereRaw('CHAR_LENGTH(numero_utente) <> 9')->get(['id', 'numero_utente']), 'numero_utente');

        $registar('Complicações registadas sem grau de Clavien-Dindo', 'internamentos',
            DB::table('internamentos as i')->whereNull('i.deleted_at')->whereNull('i.clavien_dindo_id')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('complicacao_internamento as c')
                    ->whereColumn('c.internamento_id', 'i.id')->where('c.complicacao_id', '<>', 1))
                ->get(['i.id', 'i.data_de_entrada']), 'data_de_entrada');

        $registar('Alta com data mas estado "Pendente"', 'internamentos',
            DB::table('internamentos')->whereNull('deleted_at')->whereNotNull('data_de_alta')->where('estado_da_alta_id', 1)
                ->get(['id', 'data_de_alta']), 'data_de_alta');

        // Resumo no ecrã.
        $resumo = collect($achados)->groupBy(0)->map(fn ($grupo, $problema) => [$problema, $grupo->first()[1], $grupo->count()])->values();
        $this->table(['Problema', 'Tabela', 'Registos'], $resumo->all());

        if ($caminho = $this->option('csv')) {
            $ficheiro = fopen($caminho, 'w');
            fputcsv($ficheiro, ['problema', 'tabela', 'id', 'valor']);
            foreach ($achados as $linha) {
                fputcsv($ficheiro, $linha);
            }
            fclose($ficheiro);
            $this->info("Lista completa gravada em $caminho (só identificadores, sem nomes).");
        } else {
            $this->line('Use --csv=caminho para gravar a lista completa com os identificadores.');
        }

        return self::SUCCESS;
    }
}
