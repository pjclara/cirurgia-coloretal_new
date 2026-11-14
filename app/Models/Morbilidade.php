<?php

namespace App\Models;

use App\Models\Concerns\RegistaAuditoria;
use App\Models\Concerns\RegistaAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Morbilidade de um internamento num dos três momentos de registo. */
class Morbilidade extends Model
{
    use RegistaAuditoria, RegistaAutor;

    public const DURANTE_INTERNAMENTO = 'internamento';

    public const NA_ALTA = 'alta';

    public const AOS_30_DIAS = '30_dias';

    public const MOMENTOS = [self::DURANTE_INTERNAMENTO, self::NA_ALTA, self::AOS_30_DIAS];

    /** "A aguardar ..." na tabela clavien_dindos não é um grau. */
    public const CLAVIEN_A_AGUARDAR = 8;

    protected $table = 'morbilidades';

    protected $fillable = ['internamento_id', 'momento', 'data_registo', 'clavien_dindo_id'];

    protected function casts(): array
    {
        return ['data_registo' => 'date'];
    }

    public function internamento(): BelongsTo
    {
        return $this->belongsTo(Internamento::class);
    }

    public function complicacoes(): BelongsToMany
    {
        return $this->belongsToMany(Complicacao::class, 'complicacao_morbilidade', 'morbilidade_id', 'complicacao_id')
            ->withPivot('resolucao');
    }
}
