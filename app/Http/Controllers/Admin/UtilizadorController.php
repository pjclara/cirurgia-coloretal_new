<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/** Administração de contas: criar, editar, ativar/desativar e atribuir perfis. */
class UtilizadorController extends Controller
{
    /** Perfis que só o superAdmin pode atribuir ou retirar. */
    private const PERFIS_RESERVADOS = ['superAdmin', 'admin'];

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $estado = in_array($request->query('estado'), ['ativos', 'inativos'], true) ? $request->query('estado') : 'todos';

        $utilizadores = User::query()
            ->with('roles:id,name')
            ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $sub->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('username', 'like', $like);
            }))
            ->when($estado === 'ativos', fn ($query) => $query->where('activo', true))
            ->when($estado === 'inativos', fn ($query) => $query->where('activo', false))
            ->orderByDesc('activo')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username ?? '',
                'proprio' => $u->is($request->user()),
                'email' => $u->email,
                'abrev' => $u->abrev,
                'equipa' => $u->equipa,
                'activo' => (bool) $u->activo,
                'roles' => $u->roles->pluck('name')->values(),
                'ultimo_acesso' => $u->last_login_time?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('admin/utilizadores/index', [
            'utilizadores' => $utilizadores,
            'filtros' => ['q' => $q, 'estado' => $estado],
            'perfis' => Role::orderBy('id')->pluck('name')->values(),
            'perfisReservados' => $request->user()->hasRole('superAdmin') ? [] : self::PERFIS_RESERVADOS,
            'temMudarPassword' => Schema::hasColumn('users', 'must_change_password'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->formulario($request, null);
    }

    public function edit(Request $request, User $utilizador): Response
    {
        return $this->formulario($request, $utilizador);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validar($request, null);

        $utilizador = new User(collect($dados)->except(['roles', 'password'])->all());
        $utilizador->password = $dados['password'];
        $this->marcarMudancaDePassword($utilizador, $request);
        $utilizador->save();
        $this->atribuirPerfis($request, $utilizador, $dados['roles']);

        return redirect()->route('admin.utilizadores.index')->with('sucesso', 'Conta criada.');
    }

    public function update(Request $request, User $utilizador): RedirectResponse
    {
        $dados = $this->validar($request, $utilizador);

        abort_if(
            $utilizador->is($request->user()) && ! $dados['activo'],
            422,
            'Não pode desativar a sua própria conta.',
        );

        $utilizador->fill(collect($dados)->except(['roles', 'password'])->all());
        if (! empty($dados['password'])) {
            $utilizador->password = $dados['password'];
            $this->marcarMudancaDePassword($utilizador, $request);
        }
        $utilizador->save();
        $this->atribuirPerfis($request, $utilizador, $dados['roles']);

        return redirect()->route('admin.utilizadores.index')->with('sucesso', 'Conta atualizada.');
    }

    /** Ativa ou desativa a conta a partir da lista. */
    public function alterarEstado(Request $request, User $utilizador): RedirectResponse
    {
        $ativar = $request->validate(['activo' => ['required', 'boolean']])['activo'];

        abort_if($utilizador->is($request->user()) && ! $ativar, 422, 'Não pode desativar a sua própria conta.');

        $utilizador->forceFill(['activo' => $ativar])->save();

        return back()->with('sucesso', $ativar ? 'Conta ativada.' : 'Conta desativada.');
    }

    private function formulario(Request $request, ?User $utilizador): Response
    {
        return Inertia::render('admin/utilizadores/formulario', [
            'utilizador' => $utilizador ? [
                'id' => $utilizador->id,
                'name' => $utilizador->name,
                'username' => $utilizador->username ?? '',
                'abrev' => $utilizador->abrev ?? '',
                'email' => $utilizador->email,
                'equipa' => $utilizador->equipa ?? '',
                'activo' => (bool) $utilizador->activo,
                'roles' => $utilizador->roles->pluck('name')->values(),
                'proprio' => $utilizador->is($request->user()),
            ] : null,
            'perfis' => Role::orderBy('id')->pluck('name')->values(),
            'perfisReservados' => $request->user()->hasRole('superAdmin') ? [] : self::PERFIS_RESERVADOS,
            'temMudarPassword' => Schema::hasColumn('users', 'must_change_password'),
        ]);
    }

    private function validar(Request $request, ?User $utilizador): array
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($utilizador?->id)],
            'abrev' => ['nullable', 'string', 'max:10'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($utilizador?->id)],
            'equipa' => ['nullable', Rule::in(['A', 'B'])],
            'activo' => ['required', 'boolean'],
            'password' => [$utilizador ? 'nullable' : 'required', 'string', Password::min(8)],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ], [
            'required' => 'Preencha este campo.',
            'unique' => 'Este valor já está a ser usado por outra conta.',
            'email' => 'Indique um email válido.',
        ]);

        $dados['equipa'] = $dados['equipa'] ?: null;
        $dados['roles'] ??= [];

        return $dados;
    }

    private function marcarMudancaDePassword(User $utilizador, Request $request): void
    {
        if (Schema::hasColumn('users', 'must_change_password')) {
            $utilizador->forceFill(['must_change_password' => $request->boolean('mudar_password', true)]);
        }
    }

    /** Sincroniza perfis; perfis reservados só mudam se quem edita for superAdmin. */
    private function atribuirPerfis(Request $request, User $utilizador, array $pedidos): void
    {
        if (! $request->user()->hasRole('superAdmin')) {
            $pedidos = array_values(array_diff($pedidos, self::PERFIS_RESERVADOS));
            $mantidos = $utilizador->roles->pluck('name')->intersect(self::PERFIS_RESERVADOS)->all();
            $pedidos = array_values(array_unique(array_merge($pedidos, $mantidos)));
        }

        abort_if(
            $utilizador->is($request->user()) && $request->user()->hasRole('superAdmin') && ! in_array('superAdmin', $pedidos, true),
            422,
            'Não pode retirar a si próprio o perfil superAdmin.',
        );

        $utilizador->syncRoles($pedidos);
    }
}
