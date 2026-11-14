<?php

namespace App\Models;

use App\Models\Concerns\RegistaAuditoria;
use App\Models\Concerns\RegistaAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Utente extends Model
{
    use RegistaAuditoria, RegistaAutor, SoftDeletes;

    protected $table = 'utentes';

    protected $fillable = [
        'nome',
        'numero_utente',
        'numero_processo',
        'sexo_id',
        'estado_civil_id',
        'etnia_id',
        'concelho_id',
        'data_nascimento',
        'classe_social_id',
    ];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Utente $utente) {
            $utente->nome = self::normalizarNome($utente->nome);
            $utente->slug = self::nomeCurto($utente->nome);
        });
    }

    public function referenciacoes(): HasMany
    {
        return $this->hasMany(Referenciacao::class)->orderByDesc('data_de_referenciacao');
    }

    public function internamentos(): HasMany
    {
        return $this->hasMany(Internamento::class)->orderByDesc('data_de_entrada');
    }

    /** Pesquisa por nome, n.º de processo ou n.º de utente. */
    public function scopePesquisar(Builder $query, ?string $termo): Builder
    {
        $termo = trim((string) $termo);
        if ($termo === '') {
            return $query;
        }

        if (ctype_digit($termo)) {
            return $query->where(function (Builder $q) use ($termo) {
                $q->where('numero_processo', $termo)->orWhere('numero_utente', $termo);
            });
        }

        foreach (preg_split('/\s+/', $termo) as $palavra) {
            $query->where('nome', 'like', '%'.addcslashes($palavra, '%_\\').'%');
        }

        return $query;
    }

    public static function normalizarNome(string $nome): string
    {
        return trim(preg_replace('/\s+/u', ' ', $nome));
    }

    /** Primeiro e último nome, como na coluna `slug` existente. */
    public static function nomeCurto(string $nome): string
    {
        $partes = explode(' ', self::normalizarNome($nome));

        return count($partes) > 1 ? $partes[0].' '.end($partes) : $partes[0];
    }
}
