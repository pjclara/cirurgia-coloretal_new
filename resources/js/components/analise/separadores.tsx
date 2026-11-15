import { Link, usePage } from '@inertiajs/react';
import type { PropsPartilhadas } from '../../clinico/tipos';

/** Separadores da secção Análise: painel geral e centro de referência (este só para quem pode ver referenciações). */
export function SeparadoresAnalise({ atual }: { atual: 'geral' | 'cr' }) {
    const { permissoes } = usePage<PropsPartilhadas>().props.clinico;
    const separadores = [
        { id: 'geral', rotulo: 'Geral', url: '/dashboard', visivel: true },
        { id: 'cr', rotulo: 'Centro de referência', url: '/referenciacao/analise', visivel: permissoes.includes('show referenciacao') },
    ].filter((s) => s.visivel);

    if (separadores.length < 2) return null;

    return (
        <nav aria-label="Secções da análise" className="mb-5 flex gap-1 border-b border-slate-200 dark:border-slate-700">
            {separadores.map((s) => (
                <Link
                    key={s.id}
                    href={s.url}
                    aria-current={atual === s.id ? 'page' : undefined}
                    className={`-mb-px border-b-2 px-4 py-2 text-sm focus-visible:outline-2 focus-visible:outline-teal-700 ${
                        atual === s.id
                            ? 'border-[#a3213e] font-semibold text-slate-900 dark:text-slate-100'
                            : 'border-transparent text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100'
                    }`}
                >
                    {s.rotulo}
                </Link>
            ))}
        </nav>
    );
}
