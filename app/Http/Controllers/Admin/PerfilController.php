<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Perfis e permissões: que permissões tem cada perfil. Reservado ao superAdmin.
 * Os perfis de administração ficam bloqueados (têm tudo) para ninguém se trancar fora da aplicação.
 */
class PerfilController extends Controller
{
    /** Perfis que não se editam aqui. */
    private const BLOQUEADOS = ['superAdmin', 'admin'];

    /**
     * Permissões que a aplicação verifica, por área. Quem pode fazer qualquer coisa numa área precisa também de «ver».
     *
     * @var array<string, array{rotulo: string, permissoes: array<string, string>}>
     */
    public const CATALOGO = [
        'internamento' => ['rotulo' => 'Internamentos e cirurgias', 'permissoes' => [
            'show internamento' => 'Ver o quadro, os internamentos, as cirurgias, os utentes, os seguimentos e a análise geral',
            'create internamento' => 'Registar internamentos, utentes e cirurgias',
            'update internamento' => 'Editar internamentos, utentes e cirurgias e registar seguimentos aos 30 dias',
            'delete internamento' => 'Apagar internamentos e cirurgias',
        ]],
        'referenciacao' => ['rotulo' => 'Centro de referência', 'permissoes' => [
            'show referenciacao' => 'Ver as referenciações, a análise do centro de referência, o cancro retal, a qualidade dos dados e quem é doente do CR',
            'create referenciacao' => 'Referenciar e sinalizar doentes para o centro de referência',
            'update referenciacao' => 'Editar e apagar referenciações; os diagnósticos CR dos internamentos passam para a referenciação',
        ]],
        'agendamento' => ['rotulo' => 'Agendamento cirúrgico', 'permissoes' => [
            'show agendamento' => 'Ver a agenda, o mapa operatório e a lista de espera',
            'create agendamento' => 'Inscrever na lista de espera e agendar cirurgias',
            'update agendamento' => 'Editar, mover e cancelar inscrições e agendamentos',
            'delete agendamento' => 'Apagar agendamentos',
        ]],
    ];

    public function index(): Response
    {
        $perfis = Role::with('permissions:id,name')->withCount('users')->orderBy('id')->get();
        $catalogadas = collect(self::CATALOGO)->flatMap(fn ($g) => array_keys($g['permissoes']));

        return Inertia::render('admin/perfis', [
            'grupos' => collect(self::CATALOGO)->map(fn ($g) => [
                'rotulo' => $g['rotulo'],
                'permissoes' => collect($g['permissoes'])->map(fn ($descricao, $nome) => ['nome' => $nome, 'descricao' => $descricao])->values(),
            ])->values(),
            'perfis' => $perfis->map(fn (Role $p) => [
                'nome' => $p->name,
                'utilizadores' => $p->users_count,
                'bloqueado' => in_array($p->name, self::BLOQUEADOS, true),
                'permissoes' => $p->permissions->pluck('name')->intersect($catalogadas)->values(),
            ])->values(),
            // Permissões que a aplicação já não verifica em lado nenhum.
            'semEfeito' => Permission::whereNotIn('name', $catalogadas)->orderBy('name')->pluck('name')->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $catalogadas = collect(self::CATALOGO)->flatMap(fn ($g) => array_keys($g['permissoes']))->all();
        $editaveis = Role::whereNotIn('name', self::BLOQUEADOS)->pluck('name')->all();

        $dados = $request->validate([
            'perfis' => ['required', 'array'],
            'perfis.*' => ['array'],
            'perfis.*.*' => ['string', 'in:'.implode(',', $catalogadas)],
        ]);

        $alterados = [];
        DB::transaction(function () use ($dados, $editaveis, &$alterados) {
            foreach ($dados['perfis'] as $nome => $pedidas) {
                if (! in_array($nome, $editaveis, true)) {
                    continue; // perfis bloqueados ou inexistentes não se tocam
                }

                $perfil = Role::findByName($nome, 'web');
                $antes = $perfil->permissions->pluck('name')->all();
                // O que não é catalogado (permissões sem efeito) mantém-se como está.
                $mantidas = array_values(array_diff($antes, array_keys($this->todasCatalogadas())));
                $novas = array_values(array_unique(array_merge($mantidas, $this->comVer($pedidas))));

                if (array_diff($antes, $novas) || array_diff($novas, $antes)) {
                    $perfil->syncPermissions($novas);
                    $this->auditar($perfil, $antes, $novas);
                    $alterados[] = $nome;
                }
            }
        });

        return back()->with('sucesso', $alterados === [] ? 'Sem alterações.' : 'Permissões atualizadas: '.implode(', ', $alterados).'.');
    }

    /** @return array<string, string> */
    private function todasCatalogadas(): array
    {
        return collect(self::CATALOGO)->flatMap(fn ($g) => $g['permissoes'])->all();
    }

    /** Quem tem uma permissão de uma área fica também com «ver» dessa área. */
    private function comVer(array $pedidas): array
    {
        $resultado = $pedidas;
        foreach (self::CATALOGO as $area => $grupo) {
            if (array_intersect(array_keys($grupo['permissoes']), $pedidas) !== []) {
                $resultado[] = "show $area";
            }
        }

        return $resultado;
    }

    private function auditar(Role $perfil, array $antes, array $depois): void
    {
        Auditoria::registar($perfil->getMorphClass(), $perfil->getKey(), 'updated',
            ['permissoes' => collect($antes)->sort()->values()->all()], ['permissoes' => collect($depois)->sort()->values()->all()]);
    }
}
