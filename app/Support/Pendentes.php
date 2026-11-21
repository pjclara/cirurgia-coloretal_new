<?php

namespace App\Support;

use App\Models\Internamento;
use App\Models\Morbilidade;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** O trabalho em aberto de um utilizador (internamentos de que é responsável), para a página «O que me falta fazer» e o menu. */
class Pendentes
{
    /** Altas de doentes operados e vivos sem o seguimento aos 30 dias registado. */
    public static function seguimentos(int $responsavelId)
    {
        return Internamento::query()
            ->comAlta()
            ->where('responsavel_id', $responsavelId)
            ->where(fn ($q) => $q->whereNull('destino_id')->orWhere('destino_id', '<>', Internamento::DESTINO_FALECIDO))
            ->whereDoesntHave('morbilidades', fn ($q) => $q->where('momento', Morbilidade::AOS_30_DIAS))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('bloco_operatorios as b')
                ->whereColumn('b.internamento_id', 'internamentos.id')->whereNull('b.deleted_at'))
            ->whereDate('data_de_alta', '>=', config('clinico.data_minima'));
    }

    /** Cirurgias do CR com dados por preencher, mais recentes primeiro (cada uma com `internamento_id`). */
    public static function cr(int $responsavelId): Collection
    {
        return Cirurgias::porInternamento(Internamento::query()->where('responsavel_id', $responsavelId)->select('id'))
            ->flatMap(fn ($cirurgias, $internamentoId) => $cirurgias->filter(fn ($c) => $c['cr_em_falta'] !== [])->map(fn ($c) => $c + ['internamento_id' => $internamentoId]))
            ->sortByDesc('data')->values();
    }

    /**
     * Contagens para o menu (guardadas um minuto por utilizador; `esquecer` apaga-as ao gravar).
     *
     * @return array{meus: int, atraso: int}
     */
    public static function contagens(User $utilizador): array
    {
        return Cache::remember(self::chave($utilizador->id), 60, function () use ($utilizador) {
            $limite = now()->startOfDay()->subDays(30);
            $meusSeguimentos = self::seguimentos($utilizador->id)->count();

            return [
                'meus' => $meusSeguimentos + ($utilizador->can('show referenciacao') ? self::cr($utilizador->id)->count() : 0),
                // Em atraso na lista de seguimentos (de todos os responsáveis), no ano em curso, que é o que ela mostra por omissão.
                'atraso' => Internamento::query()->comAlta()
                    ->where(fn ($q) => $q->whereNull('destino_id')->orWhere('destino_id', '<>', Internamento::DESTINO_FALECIDO))
                    ->whereDoesntHave('morbilidades', fn ($q) => $q->where('momento', Morbilidade::AOS_30_DIAS))
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('bloco_operatorios as b')->whereColumn('b.internamento_id', 'internamentos.id')->whereNull('b.deleted_at'))
                    ->whereDate('data_de_alta', '>=', now()->startOfYear())
                    ->whereDate('data_de_alta', '<', $limite)
                    ->count(),
            ];
        });
    }

    public static function esquecer(?int $utilizadorId): void
    {
        if ($utilizadorId) {
            Cache::forget(self::chave($utilizadorId));
        }
    }

    private static function chave(int $utilizadorId): string
    {
        return "pendentes.$utilizadorId";
    }
}
