import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { formatarData } from '../../../clinico/formato';
import { Botao, BotaoLigacao, estiloEntrada } from '../../../components/clinico/campos';
import ClinicoLayout from '../../../layouts/clinico-layout';
import { FormularioConta } from './formulario';

type Linha = {
    id: number;
    name: string;
    username: string;
    proprio: boolean;
    email: string;
    abrev: string | null;
    equipa: string | null;
    activo: boolean;
    roles: string[];
    ultimo_acesso: string | null;
};

type Props = {
    utilizadores: { data: Linha[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    filtros: { q: string; estado: 'todos' | 'ativos' | 'inativos' };
    perfis: string[];
    perfisReservados: string[];
    temMudarPassword: boolean;
};

export default function ListaUtilizadores({ utilizadores, filtros, perfis, perfisReservados, temMudarPassword }: Props) {
    const [q, setQ] = useState(filtros.q);
    const [aEditar, setAEditar] = useState<Linha | null>(null);

    const filtrar = (novos: Partial<Props['filtros']>) =>
        router.get('/admin/utilizadores', { ...filtros, ...novos }, { preserveState: true, replace: true });

    return (
        <ClinicoLayout
            titulo="Administração: utilizadores"
            subtitulo={`${utilizadores.total} contas`}
            acoes={<BotaoLigacao href="/admin/utilizadores/novo">Nova conta</BotaoLigacao>}
            largura="larga"
        >
            <form
                className="mb-4 flex flex-wrap items-center gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    filtrar({ q });
                }}
            >
                <input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Procurar por nome, email ou utilizador"
                    className={`${estiloEntrada} max-w-sm`}
                    aria-label="Procurar"
                />
                <Botao type="submit" variante="secundario">
                    Procurar
                </Botao>
                {(['todos', 'ativos', 'inativos'] as const).map((e) => (
                    <Botao key={e} variante={filtros.estado === e ? 'primario' : 'secundario'} onClick={() => filtrar({ estado: e })}>
                        {e === 'todos' ? 'Todas' : e === 'ativos' ? 'Ativas' : 'Desativadas'}
                    </Botao>
                ))}
            </form>

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-600">
                        <tr>
                            <th className="px-4 py-2 font-medium">Nome</th>
                            <th className="px-4 py-2 font-medium">Email</th>
                            <th className="px-4 py-2 font-medium">Equipa</th>
                            <th className="px-4 py-2 font-medium">Perfis</th>
                            <th className="px-4 py-2 font-medium">Último acesso</th>
                            <th className="px-4 py-2 font-medium">Estado</th>
                            <th className="px-4 py-2 font-medium">
                                <span className="sr-only">Ações</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {utilizadores.data.map((u) => (
                            <tr key={u.id} className={u.activo ? '' : 'text-slate-500'}>
                                <td className="px-4 py-2">
                                    <button type="button" onClick={() => setAEditar(u)} className="font-medium text-[#7d1830] hover:underline">
                                        {u.name}
                                    </button>
                                    {u.abrev && <span className="ml-2 text-xs text-slate-500">{u.abrev}</span>}
                                </td>
                                <td className="px-4 py-2">{u.email}</td>
                                <td className="px-4 py-2">{u.equipa ?? '—'}</td>
                                <td className="px-4 py-2">{u.roles.length > 0 ? u.roles.join(', ') : '—'}</td>
                                <td className="px-4 py-2 tabular-nums">{u.ultimo_acesso ? formatarData(u.ultimo_acesso.slice(0, 10)) : '—'}</td>
                                <td className="px-4 py-2">
                                    <span
                                        className={`rounded px-1.5 py-0.5 text-xs font-medium ${u.activo ? 'bg-teal-100 text-teal-900' : 'bg-slate-200 text-slate-700'}`}
                                    >
                                        {u.activo ? 'Ativa' : 'Desativada'}
                                    </span>
                                </td>
                                <td className="space-x-2 px-4 py-2 text-right whitespace-nowrap">
                                    <button
                                        type="button"
                                        disabled={u.proprio && u.activo}
                                        title={u.proprio && u.activo ? 'Não pode desativar a sua própria conta' : undefined}
                                        onClick={() => {
                                            if (u.activo && !window.confirm(`Desativar a conta de ${u.name}? A pessoa deixa de poder entrar.`))
                                                return;
                                            router.put(`/admin/utilizadores/${u.id}/estado`, { activo: !u.activo }, { preserveScroll: true });
                                        }}
                                        className={`rounded-md border bg-white px-2.5 py-1 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-50 ${
                                            u.activo
                                                ? 'border-rose-300 text-rose-700 hover:bg-rose-50'
                                                : 'border-teal-600 text-teal-800 hover:bg-teal-50'
                                        }`}
                                        aria-label={`${u.activo ? 'Desativar' : 'Ativar'} ${u.name}`}
                                    >
                                        {u.activo ? 'Desativar' : 'Ativar'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setAEditar(u)}
                                        className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-800 hover:bg-slate-50"
                                        aria-label={`Editar ${u.name}`}
                                    >
                                        Editar
                                    </button>
                                </td>
                            </tr>
                        ))}
                        {utilizadores.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-6 text-center text-slate-600">
                                    Nenhuma conta encontrada.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {utilizadores.links.length > 3 && (
                <nav className="mt-4 flex flex-wrap gap-1" aria-label="Páginas">
                    {utilizadores.links.map((l, n) => (
                        <Link
                            key={n}
                            href={l.url ?? '#'}
                            preserveScroll
                            className={`rounded px-3 py-1 text-sm ${l.active ? 'bg-[#a3213e] text-white' : 'border border-slate-300 bg-white'} ${!l.url ? 'pointer-events-none opacity-40' : ''}`}
                            dangerouslySetInnerHTML={{ __html: l.label.replace('Previous', 'Anterior').replace('Next', 'Seguinte') }}
                        />
                    ))}
                </nav>
            )}

            {aEditar && (
                <div
                    className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4"
                    onMouseDown={(e) => e.target === e.currentTarget && setAEditar(null)}
                    onKeyDown={(e) => e.key === 'Escape' && setAEditar(null)}
                >
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-label={`Editar ${aEditar.name}`}
                        className="my-8 w-full max-w-3xl rounded-lg bg-slate-50 p-5 shadow-xl"
                    >
                        <h2 className="mb-4 text-lg font-semibold text-slate-900">Editar conta</h2>
                        <FormularioConta
                            key={aEditar.id}
                            utilizador={{ ...aEditar, abrev: aEditar.abrev ?? '', equipa: aEditar.equipa ?? '' }}
                            perfis={perfis}
                            perfisReservados={perfisReservados}
                            temMudarPassword={temMudarPassword}
                            aoGuardar={() => setAEditar(null)}
                            aoCancelar={() => setAEditar(null)}
                        />
                    </div>
                </div>
            )}
        </ClinicoLayout>
    );
}

ListaUtilizadores.layout = (pagina: React.ReactNode) => pagina;
