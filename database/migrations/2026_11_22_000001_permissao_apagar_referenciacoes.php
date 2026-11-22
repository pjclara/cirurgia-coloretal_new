<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cria a permissão «delete referenciacao», que passa a ser a exigida para apagar uma referenciação (até agora bastava
 * «update referenciacao»). Para ninguém perder o acesso, atribui-a aos perfis e utilizadores que já tinham
 * «update referenciacao». Pode correr-se mais do que uma vez.
 */
return new class extends Migration
{
    private const PERMISSAO = 'delete referenciacao';

    private const BASE = 'update referenciacao';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->insertOrIgnore(['name' => self::PERMISSAO, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $nova = DB::table('permissions')->where('name', self::PERMISSAO)->where('guard_name', 'web')->value('id');
        $base = DB::table('permissions')->where('name', self::BASE)->where('guard_name', 'web')->value('id');

        if ($base) {
            foreach (DB::table('role_has_permissions')->where('permission_id', $base)->pluck('role_id') as $perfil) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $nova, 'role_id' => $perfil]);
            }
            foreach (DB::table('model_has_permissions')->where('permission_id', $base)->get(['model_type', 'model_id']) as $m) {
                DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $nova, 'model_type' => $m->model_type, 'model_id' => $m->model_id]);
            }
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
