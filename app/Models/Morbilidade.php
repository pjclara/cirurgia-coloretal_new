<?php

namespace App\Models;

use App\Models\Concerns\RegistaAuditoria;
use App\Models\Concerns\RegistaAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/** Morbilidade de um internamento, registada na alta e aos 30 dias. */
class Morbilidade extends Model
{
    use RegistaAuditoria, RegistaAutor;

    public const NA_ALTA = 'alta';

    public const AOS_30_DIAS = '30_dias';

    /** Registos antigos "durante o internamento" mantêm-se na base de dados mas já não contam nem se editam. */
    public const MOMENTOS = [self::NA_ALTA, self::AOS_30_DIAS];

    /** "A aguardar ..." na tabela clavien_dindos não é um grau. */
    public const CLAVIEN_A_AGUARDAR = 8;

    protected $table = 'morbilidades';

    protected $fillable = ['internamento_id', 'momento', 'data_registo', 'clavien_dindo_id'];

    protected function casts(): array
    {
        return ['data_registo' => 'date'];
    }

    /**
     * Grau de Clavien-Dindo calculado pelas resoluções escolhidas: o mais alto entre os graus
     * associados a cada resolução. `null` se nenhuma resolução tem grau associado.
     *
     * @param  array<int, array{id:int|string, resolucoes?:array}>  $complicacoes
     */
    public static function grauPelasResolucoes(array $complicacoes): ?int
    {
        $resolucoes = collect($complicacoes)
            ->reject(fn ($c) => (int) ($c['id'] ?? 0) === Complicacao::SEM_COMPLICACOES)
            ->flatMap(fn ($c) => $c['resolucoes'] ?? [])
            ->map(fn ($r) => (int) $r)->unique()->values();

        if ($resolucoes->isEmpty()) {
            return null;
        }

        $grau = DB::table('resolucao_complicacaos')->whereIn('id', $resolucoes)->whereNotNull('clavien_dindo_id')->max('clavien_dindo_id');

        return $grau === null ? null : (int) $grau;
    }

    /**
     * Grau definitivo do internamento: o mais alto entre as resoluções da alta e dos 30 dias.
     * Chama-se depois de gravar os dois momentos; só atualiza o registo dos 30 dias.
     */
    public static function recalcularGrauDefinitivo(Internamento $internamento): void
    {
        $registo = $internamento->morbilidades()->where('momento', self::AOS_30_DIAS)->first();
        if (! $registo) {
            return;
        }

        $complicacoes = DB::table('complicacao_morbilidade as cm')
            ->join('morbilidades as m', 'm.id', '=', 'cm.morbilidade_id')
            ->where('m.internamento_id', $internamento->getKey())
            ->whereIn('m.momento', self::MOMENTOS)
            ->get(['cm.complicacao_id', 'cm.resolucao'])
            ->map(fn ($c) => ['id' => $c->complicacao_id, 'resolucoes' => json_decode($c->resolucao ?? '[]', true) ?: []])
            ->all();

        $registo->update(['clavien_dindo_id' => self::grauPelasResolucoes($complicacoes)]);
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
