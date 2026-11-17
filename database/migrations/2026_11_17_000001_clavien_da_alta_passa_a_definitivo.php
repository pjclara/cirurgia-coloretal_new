<?php

use App\Models\Internamento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O grau de Clavien-Dindo definitivo passou a ser o do seguimento aos 30 dias.
 *
 * Para os internamentos com alta (e não falecidos) que já tinham grau gravado no registo da alta, cria-se o
 * registo aos 30 dias com esse grau e as mesmas complicações e resoluções, para não ser preciso voltar a
 * preenchê-los. Quem tinha a alta "Pendente" passa a "Concluída", como acontece quando se regista o seguimento.
 * O registo da alta não é alterado.
 *
 * Reversível: `seguimento_migrado` guarda o que foi criado e o estado da alta anterior.
 * Faça um backup da base de dados antes de correr.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seguimento_migrado', function (Blueprint $tabela) {
            $tabela->unsignedBigInteger('morbilidade_id')->primary();
            $tabela->unsignedBigInteger('internamento_id');
            $tabela->unsignedBigInteger('estado_da_alta_anterior')->nullable();
        });

        $alvos = DB::table('morbilidades as m')
            ->join('internamentos as i', 'i.id', '=', 'm.internamento_id')
            ->whereNull('i.deleted_at')->whereNotNull('i.data_de_alta')
            ->where(fn ($q) => $q->whereNull('i.destino_id')->orWhere('i.destino_id', '<>', Internamento::DESTINO_FALECIDO))
            ->where('m.momento', 'alta')->whereNotNull('m.clavien_dindo_id')->where('m.clavien_dindo_id', '<>', 8) // 8 = "A aguardar ..."
            ->whereNotExists(fn ($q) => $q->from('morbilidades as x')->whereColumn('x.internamento_id', 'm.internamento_id')->where('x.momento', '30_dias'))
            ->get(['m.id as alta_id', 'm.internamento_id', 'm.data_registo', 'm.clavien_dindo_id', 'm.created_by_id', 'i.data_de_alta', 'i.estado_da_alta_id']);

        foreach ($alvos->chunk(200) as $grupo) {
            DB::transaction(function () use ($grupo) {
                foreach ($grupo as $a) {
                    $novoId = DB::table('morbilidades')->insertGetId([
                        'internamento_id' => $a->internamento_id,
                        'momento' => '30_dias',
                        'data_registo' => $a->data_registo ?? $a->data_de_alta,
                        'clavien_dindo_id' => $a->clavien_dindo_id,
                        'created_by_id' => $a->created_by_id,
                        'updated_by_id' => $a->created_by_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::statement(
                        'insert into complicacao_morbilidade (morbilidade_id, complicacao_id, resolucao) select ?, complicacao_id, resolucao from complicacao_morbilidade where morbilidade_id = ?',
                        [$novoId, $a->alta_id],
                    );

                    DB::table('seguimento_migrado')->insert([
                        'morbilidade_id' => $novoId,
                        'internamento_id' => $a->internamento_id,
                        'estado_da_alta_anterior' => $a->estado_da_alta_id,
                    ]);

                    if ((int) $a->estado_da_alta_id === Internamento::ALTA_PENDENTE) {
                        DB::table('internamentos')->where('id', $a->internamento_id)->update(['estado_da_alta_id' => Internamento::ALTA_CONCLUIDA]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('seguimento_migrado')) {
            return;
        }

        foreach (DB::table('seguimento_migrado')->get() as $s) {
            DB::table('complicacao_morbilidade')->where('morbilidade_id', $s->morbilidade_id)->delete();
            DB::table('morbilidades')->where('id', $s->morbilidade_id)->delete();
            if ($s->estado_da_alta_anterior !== null) {
                DB::table('internamentos')->where('id', $s->internamento_id)->update(['estado_da_alta_id' => $s->estado_da_alta_anterior]);
            }
        }

        Schema::dropIfExists('seguimento_migrado');
    }
};
