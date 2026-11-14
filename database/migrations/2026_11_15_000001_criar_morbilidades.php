<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Morbilidade registada em três momentos por internamento: durante o internamento, na alta e aos 30 dias.
 *
 * Aditiva: as tabelas antigas (`complicacao_internamento` e `internamentos.clavien_dindo_id`) não são alteradas.
 * Os registos existentes passam a ser o momento "alta" (internamentos com alta) ou "internamento" (ainda internados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('morbilidades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('internamento_id');
            $table->string('momento', 20); // internamento | alta | 30_dias
            $table->date('data_registo')->nullable();
            $table->unsignedBigInteger('clavien_dindo_id')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->timestamps();

            $table->unique(['internamento_id', 'momento']);
            $table->foreign('internamento_id')->references('id')->on('internamentos')->cascadeOnDelete();
            $table->foreign('clavien_dindo_id')->references('id')->on('clavien_dindos');
        });

        Schema::create('complicacao_morbilidade', function (Blueprint $table) {
            $table->unsignedBigInteger('morbilidade_id');
            $table->unsignedBigInteger('complicacao_id');
            $table->json('resolucao')->nullable();

            $table->primary(['morbilidade_id', 'complicacao_id']);
            $table->foreign('morbilidade_id')->references('id')->on('morbilidades')->cascadeOnDelete();
            $table->foreign('complicacao_id')->references('id')->on('complicacaos');
        });

        DB::table('internamentos')
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNotNull('clavien_dindo_id')
                ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('complicacao_internamento as c')->whereColumn('c.internamento_id', 'internamentos.id')))
            ->orderBy('id')
            ->select(['id', 'data_de_alta', 'clavien_dindo_id'])
            ->chunkById(500, function ($internamentos) {
                foreach ($internamentos as $i) {
                    $morbilidadeId = DB::table('morbilidades')->insertGetId([
                        'internamento_id' => $i->id,
                        'momento' => $i->data_de_alta ? 'alta' : 'internamento',
                        'data_registo' => $i->data_de_alta,
                        'clavien_dindo_id' => $i->clavien_dindo_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $ligacoes = DB::table('complicacao_internamento')->where('internamento_id', $i->id)->get(['complicacao_id', 'resolucao']);
                    foreach ($ligacoes as $l) {
                        DB::table('complicacao_morbilidade')->insert([
                            'morbilidade_id' => $morbilidadeId,
                            'complicacao_id' => $l->complicacao_id,
                            'resolucao' => $l->resolucao,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('complicacao_morbilidade');
        Schema::dropIfExists('morbilidades');
    }
};
