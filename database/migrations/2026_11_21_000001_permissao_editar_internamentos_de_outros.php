<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cria a permissão «update internamento de outros»: quem a tem pode editar internamentos e cirurgias de que não é o
 * responsável (os administradores já podem). Atribui-a ao perfil «interno do geral». Pode correr-se mais do que uma vez.
 */
return new class extends Migration
{
    private const PERMISSAO = 'update internamento de outros';

    private const PERFIS = ['interno do geral'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->insertOrIgnore(['name' => self::PERMISSAO, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $permissao = DB::table('permissions')->where('name', self::PERMISSAO)->where('guard_name', 'web')->value('id');

        foreach (DB::table('roles')->whereIn('name', self::PERFIS)->pluck('id') as $perfil) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissao, 'role_id' => $perfil]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissao = DB::table('permissions')->where('name', self::PERMISSAO)->value('id');
        if ($permissao) {
            DB::table('role_has_permissions')->where('permission_id', $permissao)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissao)->delete();
            DB::table('permissions')->where('id', $permissao)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
