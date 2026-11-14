import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { formatarData } from '../../../clinico/formato';
import { Botao, BotaoLigacao, estiloEntrada } from '../../../components/clinico/campos';
import ClinicoLayout from '../../../layouts/clinico-layout';

type Linha = {
    id: number;
    name: string;
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
};

export default function ListaUtilizadores({ utilizadores, filtros }: Props) {
    const [q, setQ] = useState(filtros.q);

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
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {utilizadores.data.map((u) => (
                            <tr key={u.id} className={u.activo ? '' : 'text-slate-500'}>
                                <td className="px-4 py-2">
                                    <Link href={`/admin/utilizadores/${u.id}`} className="font-medium text-[#7d1830] hover:underline">
                                        {u.name}
                                    </Link>
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
                            </tr>
                        ))}
                        {utilizadores.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-6 text-center text-slate-600">
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
        </ClinicoLayout>
    );
}

ListaUtilizadores.layout = (pagina: React.ReactNode) => pagina;
