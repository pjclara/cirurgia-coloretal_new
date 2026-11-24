<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CancroRetalAnaliseController;
use App\Http\Controllers\Controller;
use App\Models\Complicacao;
use App\Models\Diagnostico;
use App\Models\Intervencao;
use App\Models\ResolucaoComplicacao;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administração dos catálogos de diagnósticos e de intervenções: ver, criar, editar e apagar. Os dois partilham o
 * mesmo código, descrito em CATALOGOS. Um registo em uso (num internamento, inscrição, referenciação ou cirurgia) ou de
 * que o código depende não se apaga: edita-se.
 */
class CatalogoController extends Controller
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private const CATALOGOS = [
        'diagnosticos' => [
            'titulo' => 'Diagnósticos',
            'singular' => 'diagnóstico',
            'modelo' => Diagnostico::class,
            'tabela' => 'diagnosticos',
            'grupos' => 'grupo_diagnosticos',
            'coluna_grupo' => 'grupo_diagnostico_id',
            'tipos' => ['tabela' => 'tipo_de_diagnosticos', 'coluna' => 'tipo_de_diagnostico_id'],
            'booleanos' => ['centro_de_referencia'],
            'usos' => [['diagnostico_internamento', 'diagnostico_id'], ['diagnostico_lista_de_espera', 'diagnostico_id'], ['diagnostico_referenciacao', 'diagnostico_id']],
            'protegidos' => [Diagnostico::OUTRO],
        ],
        'intervencoes' => [
            'titulo' => 'Intervenções',
            'singular' => 'intervenção',
            'modelo' => Intervencao::class,
            'tabela' => 'intervencaos',
            'grupos' => 'grupo_intervencaos',
            'coluna_grupo' => 'grupo_intervencao_id',
            'tipos' => null,
            'booleanos' => ['centro_de_referencia', 'cirurgia_de_ressecao'],
            'usos' => [['bloco_operatorio_intervencao', 'intervencao_id'], ['intervencao_lista_de_espera', 'intervencao_id']],
            'protegidos' => [CancroRetalAnaliseController::RESSECCAO_ANTERIOR, CancroRetalAnaliseController::AMPUTACAO_ABDOMINO_PERINEAL, CancroRetalAnaliseController::RESSECCAO_RETO_SIGMOIDEIA],
        ],
        // Complicações e resoluções: sem centro de referência. A resolução não tem abreviatura e o seu «grupo» é o grau de Clavien-Dindo.
        'complicacoes' => [
            'titulo' => 'Complicações',
            'singular' => 'complicação',
            'modelo' => Complicacao::class,
            'tabela' => 'complicacaos',
            'grupos' => 'grupo_complicacaos',
            'coluna_grupo' => 'grupo_complicacao_id',
            'rotulo_grupo' => 'Grupo',
            'tipos' => null,
            'booleanos' => [],
            'cr' => false,
            'abrev' => true,
            'usos' => [['complicacao_internamento', 'complicacao_id'], ['complicacao_morbilidade', 'complicacao_id']],
            'usos_json' => [],
            'protegidos' => [Complicacao::SEM_COMPLICACOES],
        ],
        'resolucoes' => [
            'titulo' => 'Resoluções das complicações',
            'singular' => 'resolução',
            'modelo' => ResolucaoComplicacao::class,
            'tabela' => 'resolucao_complicacaos',
            'grupos' => 'clavien_dindos',
            'coluna_grupo' => 'clavien_dindo_id',
            'rotulo_grupo' => 'Grau de Clavien-Dindo',
            'grupo_opcional' => true,
            'tipos' => null,
            'booleanos' => [],
            'cr' => false,
            'abrev' => false,
            'usos' => [],
            // As resoluções escolhidas ficam em JSON (lista de ids, números ou texto nos registos antigos) em cada complicação registada.
            'usos_json' => [['complicacao_internamento', 'resolucao'], ['complicacao_morbilidade', 'resolucao']],
            'aviso' => 'O grau de Clavien-Dindo de uma resolução decide o grau definitivo de cada internamento: alterá-lo só se reflete nos registos quando forem gravados de novo.',
            'protegidos' => [],
        ],
    ];

    /** Contagem de usos de um registo: tabelas que o referenciam e, nas resoluções, listas JSON de ids. */
    private function sqlUsos(array $c): string
    {
        $partes = collect($c['usos'])->map(fn ($u) => "(select count(*) from {$u[0]} where {$u[0]}.{$u[1]} = t.id)");
        $partes = $partes->merge(collect($c['usos_json'] ?? [])->map(fn ($u) => "(select count(*) from {$u[0]} where JSON_CONTAINS({$u[0]}.{$u[1]}, CAST(t.id AS CHAR)) or JSON_CONTAINS({$u[0]}.{$u[1]}, JSON_QUOTE(CAST(t.id AS CHAR))))"));

        return $partes->isEmpty() ? '0' : $partes->implode(' + ');
    }

    private function contarUsos(array $c, int $id): int
    {
        return (int) DB::table("{$c['tabela']} as t")->where('t.id', $id)->selectRaw('('.$this->sqlUsos($c).') as n')->value('n');
    }

    public function index(Request $request, string $catalogo): Response
    {
        $c = $this->catalogo($catalogo);
        $q = trim((string) $request->query('q', ''));
        $grupo = ctype_digit((string) $request->query('grupo')) ? (int) $request->query('grupo') : null;
        $soCr = ($c['cr'] ?? true) && $request->boolean('cr');

        $usos = $this->sqlUsos($c);
        $temAbrev = $c['abrev'] ?? true;
        $temCr = $c['cr'] ?? true;
        $itens = DB::table("{$c['tabela']} as t")
            ->leftJoin("{$c['grupos']} as g", 'g.id', '=', "t.{$c['coluna_grupo']}")
            ->when($q !== '', fn ($query) => $query->where(fn ($s) => $temAbrev
                ? $s->where('t.nome', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('t.abrev', 'like', '%'.addcslashes($q, '%_\\').'%')
                : $s->where('t.nome', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->when($grupo, fn ($query, $id) => $query->where("t.{$c['coluna_grupo']}", $id))
            ->when($soCr, fn ($query) => $query->where('t.centro_de_referencia', 1))
            ->orderBy('t.nome')
            ->select('t.*', 'g.nome as grupo')
            ->selectRaw("($usos) as usos")
            ->paginate(25)->withQueryString()
            ->through(fn ($i) => [
                'id' => (int) $i->id,
                'nome' => trim($i->nome),
                'abrev' => ! empty($i->abrev) ? trim($i->abrev) : '',
                'grupo_id' => $i->{$c['coluna_grupo']} ? (int) $i->{$c['coluna_grupo']} : null,
                'grupo' => $i->grupo,
                'tipo_id' => $c['tipos'] ? (int) $i->{$c['tipos']['coluna']} : null,
                'centro_de_referencia' => (bool) ($i->centro_de_referencia ?? false),
                'cirurgia_de_ressecao' => (bool) ($i->cirurgia_de_ressecao ?? false),
                'usos' => (int) $i->usos,
                'protegido' => in_array((int) $i->id, $c['protegidos'], true),
            ]);

        return Inertia::render('admin/catalogo', [
            'catalogo' => ['chave' => $catalogo, 'titulo' => $c['titulo'], 'singular' => $c['singular'], 'temTipo' => $c['tipos'] !== null, 'temRessecao' => in_array('cirurgia_de_ressecao', $c['booleanos'], true), 'temAbrev' => $temAbrev, 'temCr' => $temCr, 'rotuloGrupo' => $c['rotulo_grupo'] ?? 'Grupo', 'grupoOpcional' => $c['grupo_opcional'] ?? false, 'aviso' => $c['aviso'] ?? null],
            'itens' => $itens,
            'filtros' => ['q' => $q, 'grupo' => $grupo, 'cr' => $soCr],
            'grupos' => DB::table($c['grupos'])->orderBy('nome')->get(['id', 'nome'])->map(fn ($g) => ['id' => (int) $g->id, 'nome' => trim($g->nome)])->values(),
            'tipos' => $c['tipos'] ? DB::table($c['tipos']['tabela'])->orderBy('id')->get(['id', 'nome'])->map(fn ($t) => ['id' => (int) $t->id, 'nome' => trim($t->nome)])->values() : [],
        ]);
    }

    public function store(Request $request, string $catalogo): RedirectResponse
    {
        $c = $this->catalogo($catalogo);
        $dados = $this->validar($request, $c, null);

        $id = DB::table($c['tabela'])->insertGetId($dados);
        Auditoria::registar($c['modelo'], $id, 'created', [], $dados);

        return back()->with('sucesso', ucfirst($c['singular']).' criado.');
    }

    public function update(Request $request, string $catalogo, int $id): RedirectResponse
    {
        $c = $this->catalogo($catalogo);
        $antes = DB::table($c['tabela'])->where('id', $id)->first();
        abort_if(! $antes, 404);
        $dados = $this->validar($request, $c, $id);

        DB::table($c['tabela'])->where('id', $id)->update($dados);
        Auditoria::registar($c['modelo'], $id, 'updated', array_intersect_key((array) $antes, $dados), $dados);

        return back()->with('sucesso', ucfirst($c['singular']).' atualizado.');
    }

    public function destroy(string $catalogo, int $id): RedirectResponse
    {
        $c = $this->catalogo($catalogo);
        $registo = DB::table($c['tabela'])->where('id', $id)->first();
        abort_if(! $registo, 404);

        if (in_array($id, $c['protegidos'], true)) {
            return back()->with('erro', 'Este registo é usado pela própria aplicação e não se pode apagar.');
        }
        $usos = $this->contarUsos($c, $id);
        if ($usos > 0) {
            return back()->with('erro', "Este {$c['singular']} está em uso ($usos registos) e não se pode apagar; edite-o.");
        }

        DB::table($c['tabela'])->where('id', $id)->delete();
        Auditoria::registar($c['modelo'], $id, 'deleted', (array) $registo, []);

        return back()->with('sucesso', ucfirst($c['singular']).' apagado.');
    }

    /** @return array<string, mixed> */
    private function catalogo(string $chave): array
    {
        return self::CATALOGOS[$chave] ?? abort(404);
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function validar(Request $request, array $c, ?int $id): array
    {
        $regras = [
            'nome' => ['required', 'string', 'min:2', 'max:255', Rule::unique($c['tabela'], 'nome')->ignore($id)],
            'grupo_id' => [($c['grupo_opcional'] ?? false) ? 'nullable' : 'required', 'integer', Rule::exists($c['grupos'], 'id')],
        ];
        if ($c['abrev'] ?? true) {
            $regras['abrev'] = ['nullable', 'string', 'max:255'];
        }
        if ($c['tipos']) {
            $regras['tipo_id'] = ['required', 'integer', Rule::exists($c['tipos']['tabela'], 'id')];
        }
        foreach ($c['booleanos'] as $campo) {
            $regras[$campo] = ['boolean'];
        }
        $d = $request->validate($regras, ['required' => 'Preencha este campo.', 'nome.unique' => 'Já existe um registo com este nome.']);

        $dados = ['nome' => trim($d['nome']), $c['coluna_grupo'] => $d['grupo_id'] ?? null];
        if ($c['abrev'] ?? true) {
            $dados['abrev'] = ($d['abrev'] ?? '') !== '' ? trim($d['abrev']) : null;
        }
        if ($c['tipos']) {
            $dados[$c['tipos']['coluna']] = $d['tipo_id'];
        }
        foreach ($c['booleanos'] as $campo) {
            $dados[$campo] = (int) ($d[$campo] ?? false);
        }

        return $dados;
    }
}
