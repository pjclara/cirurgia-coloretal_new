import { Link } from '@inertiajs/react';
import type { Paginado } from '../../clinico/tipos';

export function Paginacao<T>({ pagina, nome }: { pagina: Paginado<T>; nome: string }) {
    if (pagina.total === 0) return null;

    const estilo = 'rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 hover:bg-slate-50';
    const inativo = 'rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm text-slate-400';

    return (
        <nav className="flex items-center justify-between gap-4 px-1 py-3" aria-label="Paginação">
            <p className="text-sm text-slate-600 tabular-nums">
                {pagina.from}–{pagina.to} de {pagina.total} {nome}
            </p>
            <div className="flex gap-2">
                {pagina.prev_page_url ? (
                    <Link href={pagina.prev_page_url} preserveScroll className={estilo}>
                        Anterior
                    </Link>
                ) : (
                    <span className={inativo}>Anterior</span>
                )}
                {pagina.next_page_url ? (
                    <Link href={pagina.next_page_url} preserveScroll className={estilo}>
                        Seguinte
                    </Link>
                ) : (
                    <span className={inativo}>Seguinte</span>
                )}
            </div>
        </nav>
    );
}
