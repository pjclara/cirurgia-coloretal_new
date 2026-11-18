<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Passa para as referenciações ao centro de referência os diagnósticos «centro de referência» que já estão
 * registados nos internamentos do utente (o que, daqui para a frente, se faz ao gravar o internamento).
 *
 * Como se faz:
 *  - só a referenciação ativa mais recente de cada utente (sem saída e não apagada);
 *  - os diagnósticos dos internamentos não apagados do utente com `centro_de_referencia = 1`;
 *  - o que a referenciação já tem não se toca nem se duplica;
 *  - o modo fica «Outro» (99) e a histologia e a data por preencher, para completar na referenciação.
 *
 * Reversível: guarda os registos criados em `diagnostico_referenciacao_associados` e o `down` apaga só esses.
 * Pode correr-se mais do que uma vez: só acrescenta o que falta. Faça um backup da base de dados antes de correr.
 */
return new class extends Migration
{
    private const MODO_OUTRO = 99;

    public function up(): void
    {
        // Base de dados sem as tabelas ou a coluna que isto cruza: nada a fazer.
        if (! Schema::hasTable('diagnostico_referenciacao') || ! Schema::hasTable('referenciacao_centro_de_referencias') || ! Schema::hasColumn('diagnosticos', 'centro_de_referencia')) {
            return;
        }

        if (! Schema::hasTable('diagnostico_referenciacao_associados')) {
            Schema::create('diagnostico_referenciacao_associados', function (Blueprint $tabela) {
                $tabela->unsignedBigInteger('diagnostico_referenciacao_id')->primary();
                $tabela->timestamp('criado_em')->useCurrent();
            });
        }

        $antes = (int) DB::table('diagnostico_referenciacao')->max('id');

        DB::insert('
            insert into diagnostico_referenciacao (diagnostico_id, referenciacao_id, modo_id, histologia_id, data_diagnostico)
            select distinct di.diagnostico_id, r.id, ?, null, null
            from referenciacao_centro_de_referencias r
            join internamentos i on i.utente_id = r.utente_id and i.deleted_at is null
            join diagnostico_internamento di on di.internamento_id = i.id
            join diagnosticos d on d.id = di.diagnostico_id and d.centro_de_referencia = 1
            where r.deleted_at is null and r.data_de_saida is null
              and r.id = (select r2.id from referenciacao_centro_de_referencias r2
                          where r2.utente_id = r.utente_id and r2.deleted_at is null and r2.data_de_saida is null
                          order by r2.data_de_referenciacao desc, r2.id desc limit 1)
              and not exists (select 1 from diagnostico_referenciacao x
                              where x.referenciacao_id = r.id and x.diagnostico_id = di.diagnostico_id)
        ', [self::MODO_OUTRO]);

        DB::insert('insert into diagnostico_referenciacao_associados (diagnostico_referenciacao_id)
            select id from diagnostico_referenciacao where id > ?', [$antes]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('diagnostico_referenciacao_associados')) {
            return;
        }

        DB::delete('delete from diagnostico_referenciacao where id in (select diagnostico_referenciacao_id from diagnostico_referenciacao_associados)');
        Schema::drop('diagnostico_referenciacao_associados');
    }
};
