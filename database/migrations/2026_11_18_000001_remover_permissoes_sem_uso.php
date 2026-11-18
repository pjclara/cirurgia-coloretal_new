<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Apaga as permissões que a aplicação já não verifica em lado nenhum (herdadas da aplicação antiga):
 * as `edit …` (a aplicação usa `update …`) e as de diagnóstico, intervenção, complicação, resolução de
 * complicação e reunião de decisão terapêutica.
 *
 * Pelo nome, por isso serve para a base de dados original: o que não existir ignora-se. Remove primeiro as
 * ligações aos perfis e aos utilizadores e só depois a permissão. Pode correr-se mais do que uma vez.
 *
 * Reversível: guarda o que apagou em `permissoes_removidas` e o `down` repõe as permissões e as ligações.
 * Faça um backup da base de dados antes de correr.
 */
return new class extends Migration
{
    private const SEM_USO = [
        'edit agendamento', 'edit internamento', 'edit referenciacao',
        'create diagnostico', 'update diagnostico',
        'create intervencao', 'update intervencao',
        'create complicacao', 'update complicacao',
        'create resolucaoComplicacao', 'show resolucaoComplicacao', 'update resolucaoComplicacao',
        'create reuniaoDecisaoTerapeutica', 'show reuniaoDecisaoTerapeutica', 'update reuniaoDecisaoTerapeutica',
        'edit reuniaoDecisaoTerapeutica', 'delete reuniaoDecisaoTerapeutica',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        if (! Schema::hasTable('permissoes_removidas')) {
            Schema::create('permissoes_removidas', function (Blueprint $tabela) {
                $tabela->unsignedBigInteger('permissao_id')->primary();
                $tabela->string('nome');
                $tabela->string('guard_name');
                $tabela->json('perfis')->nullable();
                $tabela->json('utilizadores')->nullable();
                $tabela->timestamp('removida_em')->useCurrent();
            });
        }

        DB::transaction(function () {
            foreach (DB::table('permissions')->whereIn('name', self::SEM_USO)->get() as $permissao) {
                DB::table('permissoes_removidas')->insertOrIgnore([
                    'permissao_id' => $permissao->id,
                    'nome' => $permissao->name,
                    'guard_name' => $permissao->guard_name,
                    'perfis' => json_encode(DB::table('role_has_permissions')->where('permission_id', $permissao->id)->pluck('role_id')->all()),
                    'utilizadores' => json_encode(DB::table('model_has_permissions')->where('permission_id', $permissao->id)
                        ->get(['model_type', 'model_id'])->map(fn ($l) => [$l->model_type, $l->model_id])->all()),
                ]);
                DB::table('role_has_permissions')->where('permission_id', $permissao->id)->delete();
                DB::table('model_has_permissions')->where('permission_id', $permissao->id)->delete();
                DB::table('permissions')->where('id', $permissao->id)->delete();
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissoes_removidas')) {
            return;
        }

        DB::transaction(function () {
            foreach (DB::table('permissoes_removidas')->get() as $p) {
                DB::table('permissions')->insertOrIgnore([
                    'id' => $p->permissao_id, 'name' => $p->nome, 'guard_name' => $p->guard_name, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (json_decode($p->perfis, true) ?: [] as $perfil) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $p->permissao_id, 'role_id' => $perfil]);
                }
                foreach (json_decode($p->utilizadores, true) ?: [] as [$tipo, $id]) {
                    DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $p->permissao_id, 'model_type' => $tipo, 'model_id' => $id]);
                }
            }
        });

        Schema::drop('permissoes_removidas');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
