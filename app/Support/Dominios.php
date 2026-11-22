<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Leitura das tabelas de domínio (id, nome) usadas nos formulários.
 * Cada tabela é lida uma vez por pedido.
 */
class Dominios
{
    private const TABELAS = [
        'sexos',
        'estado_civils',
        'etnias',
        'concelhos',
        'classe_socials',
        'localizacaos',
        'origem_do_internamentos',
        'estado_da_altas',
        'destinos',
        'caso_socials',
        'clavien_dindos',
        'resolucao_complicacaos',
        'grupo_diagnosticos',
        'grupo_complicacaos',
        'origem_da_referenciacaos',
        'diagnostico_referenciacao_modos',
        'histologias',
        'estado_consultas',
        'timmings',
    ];

    /** @var array<string, array<int, array{id:int, nome:string}>> */
    private static array $cache = [];

    /** @return array<int, array{id:int, nome:string}> */
    public static function lista(string $tabela): array
    {
        if (! in_array($tabela, self::TABELAS, true)) {
            throw new InvalidArgumentException("Tabela de domínio desconhecida: $tabela");
        }

        return self::$cache[$tabela] ??= DB::table($tabela)
            ->orderBy('id')
            ->get(['id', 'nome'])
            ->map(fn ($linha) => ['id' => (int) $linha->id, 'nome' => trim($linha->nome)])
            ->all();
    }

    public static function nome(string $tabela, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        foreach (self::lista($tabela) as $linha) {
            if ($linha['id'] === $id) {
                return $linha['nome'];
            }
        }

        return null;
    }

    /** Nome de uma tabela (id, nome) fora da lista fixa, ex.: tipos de cirurgia. */
    public static function tabela(string $tabela, ?int $id): ?string
    {
        foreach (self::tabelaLista($tabela) as $linha) {
            if ($linha['id'] === $id) {
                return $linha['nome'];
            }
        }

        return null;
    }

    /** @return array<int, array{id:int, nome:string}> */
    public static function tabelaLista(string $tabela): array
    {
        return self::$cache[$tabela] ??= DB::table($tabela)
            ->orderBy('id')
            ->get(['id', 'nome'])
            ->map(fn ($l) => ['id' => (int) $l->id, 'nome' => trim($l->nome)])
            ->all();
    }

    /** Diagnósticos com o nome do grupo, para a escolha múltipla. */
    public static function diagnosticos(): array
    {
        return self::$cache['__diagnosticos'] ??= DB::table('diagnosticos')
            ->orderBy('nome')
            ->get(['id', 'nome', 'grupo_diagnostico_id'])
            ->map(fn ($d) => [
                'id' => (int) $d->id,
                'nome' => trim($d->nome),
                'grupo' => self::nome('grupo_diagnosticos', (int) $d->grupo_diagnostico_id) ?? 'Outro',
            ])
            ->all();
    }

    /** Complicações com o nome do grupo. */
    /** Resoluções das complicações, com o grau de Clavien-Dindo que cada uma implica. */
    public static function resolucoesComGrau(): array
    {
        return DB::table('resolucao_complicacaos')->orderBy('id')->get(['id', 'nome', 'clavien_dindo_id'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'nome' => trim($r->nome), 'clavien_dindo_id' => $r->clavien_dindo_id ? (int) $r->clavien_dindo_id : null])
            ->all();
    }

    public static function complicacoes(): array
    {
        return self::$cache['__complicacoes'] ??= DB::table('complicacaos')
            ->orderBy('nome')
            ->get(['id', 'nome', 'grupo_complicacao_id'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'nome' => trim($c->nome),
                'grupo' => self::nome('grupo_complicacaos', (int) $c->grupo_complicacao_id) ?? 'Outras',
            ])
            ->all();
    }

    /** Dados para a pergunta "sinalizar para o centro de referência?"; null sem permissão para sinalizar. */
    public static function sinalizacao(\App\Models\User $utilizador): ?array
    {
        return $utilizador->can('create referenciacao') ? [
            'origens' => self::lista('origem_da_referenciacaos'),
            'responsaveis' => self::responsaveis(),
        ] : null;
    }

    /** Utilizadores que podem ser responsáveis: ativos, mais o responsável atual do registo. */
    public static function responsaveis(?int $incluirId = null): array
    {
        // Primeiro perfil (role) de cada utilizador, para agrupar quem não tem equipa.
        $perfis = DB::table('model_has_roles as m')
            ->join('roles as r', 'r.id', '=', 'm.role_id')
            ->where('m.model_type', \App\Models\User::class)
            ->orderBy('r.id')
            ->get(['m.model_id', 'r.name'])
            ->unique('model_id')
            ->pluck('name', 'model_id');

        return DB::table('users')
            ->where('activo', true)
            ->when($incluirId, fn ($q) => $q->orWhere('id', $incluirId))
            ->orderBy('name')
            ->get(['id', 'name', 'abrev', 'equipa'])
            ->map(fn ($u) => [
                'id' => (int) $u->id,
                'nome' => $u->name,
                'abrev' => $u->abrev,
                'equipa' => $u->equipa,
                'perfil' => $perfis[$u->id] ?? null,
            ])
            ->all();
    }
}
