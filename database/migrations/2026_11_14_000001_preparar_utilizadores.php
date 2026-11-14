<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Acrescenta as colunas que a autenticação atual (Fortify) espera.
 * 2. Marca para alteração obrigatória as contas que ainda usam a password por omissão do esquema antigo.
 * 3. Remove essa password por omissão da definição da coluna.
 */
return new class extends Migration
{
    private const HASH_POR_OMISSAO = '$2y$10$0aDASYf11oYoicCMKmpoNug3NhyvVvBFuh2A7LDJS0nJOKIa63tIO';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            }
            if (! Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->after('password');
            }
        });

        DB::table('users')
            ->where('password', self::HASH_POR_OMISSAO)
            ->update(['must_change_password' => true]);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `users` ALTER COLUMN `password` DROP DEFAULT');
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'must_change_password')) {
                $table->dropColumn('must_change_password');
            }
        });
    }
};
