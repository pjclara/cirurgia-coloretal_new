<?php

namespace App\Support;

use App\Models\Referenciacao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Diagnósticos «centro de referência» já registados nos internamentos de um utente e a sua passagem para a referenciação. */
class DiagnosticosCr
{
    /**
     * Os diagnósticos CR dos internamentos não apagados do utente.
     *
     * @return Collection<int, object{id: int, nome: string}>
     */
    public static function doUtente(int $utenteId): Collection
    {
        return DB::table('diagnosticos as d')
            ->join('diagnostico_internamento as di', 'di.diagnostico_id', '=', 'd.id')
            ->join('internamentos as i', 'i.id', '=', 'di.internamento_id')
            ->where('i.utente_id', $utenteId)->whereNull('i.deleted_at')->where('d.centro_de_referencia', 1)
            ->select('d.id', 'd.nome')->distinct()->orderBy('d.nome')->get()
            ->map(fn ($d) => (object) ['id' => (int) $d->id, 'nome' => trim($d->nome)]);
    }

    /**
     * Acrescenta à referenciação os diagnósticos CR (dos ids dados) que ainda não tem. O modo fica «Outro» e a histologia
     * e a data por preencher; nada se remove. Devolve os ids acrescentados.
     *
     * @param  iterable<int>  $ids
     * @return list<int>
     */
    public static function associar(Referenciacao $referenciacao, iterable $ids): array
    {
        $jaAssociados = $referenciacao->diagnosticos()->pluck('diagnosticos.id')->all();
        $novos = DB::table('diagnosticos')->whereIn('id', collect($ids)->all())->where('centro_de_referencia', 1)
            ->whereNotIn('id', $jaAssociados)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($novos === []) {
            return [];
        }

        $referenciacao->diagnosticos()->attach(collect($novos)->mapWithKeys(fn ($id) => [
            $id => ['modo_id' => Referenciacao::MODO_OUTRO, 'histologia_id' => null, 'data_diagnostico' => null],
        ])->all());
        $referenciacao->auditar('updated', ['diagnosticos' => $jaAssociados], ['diagnosticos' => array_merge($jaAssociados, $novos)]);

        return $novos;
    }
}
