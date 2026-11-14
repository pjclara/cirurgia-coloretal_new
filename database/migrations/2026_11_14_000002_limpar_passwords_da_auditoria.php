<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A auditoria antiga guardou hashes de password em `old_values` / `new_values`.
 * Retira essas chaves, mantendo o resto do registo. Não é reversível.
 */
return new class extends Migration
{
    private const CHAVES = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function up(): void
    {
        DB::table('audits')
            ->where(function ($q) {
                foreach (self::CHAVES as $chave) {
                    $q->orWhere('old_values', 'like', '%"'.$chave.'"%')
                        ->orWhere('new_values', 'like', '%"'.$chave.'"%');
                }
            })
            ->orderBy('id')
            ->chunkById(500, function ($linhas) {
                foreach ($linhas as $linha) {
                    DB::table('audits')->where('id', $linha->id)->update([
                        'old_values' => $this->limpar($linha->old_values),
                        'new_values' => $this->limpar($linha->new_values),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Sem reversão: os hashes removidos não devem voltar.
    }

    private function limpar(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return $json;
        }

        $dados = json_decode($json, true);
        if (! is_array($dados)) {
            return $json;
        }

        foreach (self::CHAVES as $chave) {
            if (array_key_exists($chave, $dados)) {
                $dados[$chave] = '[removido]';
            }
        }

        return json_encode($dados, JSON_UNESCAPED_UNICODE);
    }
};
