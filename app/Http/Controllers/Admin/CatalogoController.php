<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CancroRetalAnaliseController;
use App\Http\Controllers\Controller;
use App\Models\Diagnostico;
use App\Models\Intervencao;
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
    ];

    public function index(Request $request, string $catalogo): Response
    {
        $c = $this->catalogo($catalogo);
        $q = trim((string) $request->query('q', ''));
        $grupo = ctype_digit((string) $request->query('grupo')) ? (int) $request->query('grupo') : null;
        $soCr = $request->boolean('cr');

        $usos = collect($c['usos'])->map(fn ($u) => "(select count(*) from {$u[0]} where {$u[0]}.{$u[1]} = t.id)")->implode(' + ');
        $itens = DB::table("{$c['tabela']} as t")
            ->leftJoin("{$c['grupos']} as g", 'g.id', '=', "t.{$c['coluna_grupo']}")
            ->when($q !== '', fn ($query) => $query->where(fn ($s) => $s->where('t.nome', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('t.abrev', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->when($grupo, fn ($query, $id) => $query->where("t.{$c['coluna_grupo']}", $id))
            ->when($soCr, fn ($query) => $query->where('t.centro_de_referencia', 1))
            ->orderBy('t.nome')
            ->select('t.*', 'g.nome as grupo')
            ->selectRaw("($usos) as usos")
            ->paginate(25)->withQueryString()
            ->through(fn ($i) => [
                'id' => (int) $i->id,
                'nome' => trim($i->nome),
                'abrev' => $i->abrev ? trim($i->abrev) : '',
                'grupo_id' => (int) $i->{$c['coluna_grupo']},
                'grupo' => $i->grupo,
                'tipo_id' => $c['tipos'] ? (int) $i->{$c['tipos']['coluna']} : null,
                'centro_de_referencia' => (bool) $i->centro_de_referencia,
                'cirurgia_de_ressecao' => (bool) ($i->cirurgia_de_ressecao ?? false),
                'usos' => (int) $i->usos,
                'protegido' => in_array((int) $i->id, $c['protegidos'], true),
            ]);

        return Inertia::render('admin/catalogo', [
            'catalogo' => ['chave' => $catalogo, 'titulo' => $c['titulo'], 'singular' => $c['singular'], 'temTipo' => $c['tipos'] !== null, 'temRessecao' => in_array('cirurgia_de_ressecao', $c['booleanos'], true)],
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
        $usos = collect($c['usos'])->sum(fn ($u) => DB::table($u[0])->where($u[1], $id)->count());
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
            'abrev' => ['nullable', 'string', 'max:255'],
            'grupo_id' => ['required', 'integer', Rule::exists($c['grupos'], 'id')],
        ];
        if ($c['tipos']) {
            $regras['tipo_id'] = ['required', 'integer', Rule::exists($c['tipos']['tabela'], 'id')];
        }
        foreach ($c['booleanos'] as $campo) {
            $regras[$campo] = ['boolean'];
        }
        $d = $request->validate($regras, ['required' => 'Preencha este campo.', 'nome.unique' => 'Já existe um registo com este nome.']);

        $dados = ['nome' => trim($d['nome']), 'abrev' => ($d['abrev'] ?? '') !== '' ? trim($d['abrev']) : null, $c['coluna_grupo'] => $d['grupo_id']];
        if ($c['tipos']) {
            $dados[$c['tipos']['coluna']] = $d['tipo_id'];
        }
        foreach ($c['booleanos'] as $campo) {
            $dados[$campo] = (int) ($d[$campo] ?? false);
        }

        return $dados;
    }
}
