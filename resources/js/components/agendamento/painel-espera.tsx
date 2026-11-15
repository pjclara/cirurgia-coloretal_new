import { Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { PRIORIDADE } from '../../clinico/agendamento';
import { diasEntre, formatarData } from '../../clinico/formato';
import { estiloEntrada } from '../clinico/campos';

export type ItemEspera = {
    id: number;
    utente: { id: number | null; nome: string | null; numero_processo: number | null };
    data: string | null;
    prioridade: number;
    intervencoes: string[];
    responsavel: string | null;
};

export type Espera = { itens: ItemEspera[]; filtro: 'meus' | 'todos'; q: string; total: number };

const COR_PRIORIDADE: Record<number, string> = { 2: 'bg-rose-100 text-rose-900', 3: 'bg-amber-100 text-amber-900' };

/**
 * Lista de espera ao lado do calendário: cada doente pode ser arrastado para um dia (abre o agendamento nesse dia)
 * ou agendado pelo botão, que serve também em ecrãs sem rato.
 */
export function PainelEspera({
    espera,
    hoje,
    podeAgendar,
    aoArrastar,
    aoSoltar,
    parametros,
}: {
    espera: Espera;
    hoje: string;
    podeAgendar: boolean;
    aoArrastar: (id: number | null) => void;
    aoSoltar: () => void;
    /** Parâmetros actuais do calendário (vista, semana, cancelados), para os manter ao filtrar. */
    parametros: Record<string, string | boolean>;
}) {
    const [termo, setTermo] = useState(espera.q);
    const ir = (extra: Record<string, string>) =>
        router.get('/agendamento', { ...parametros, ...extra }, { preserveScroll: true, preserveState: true });
    const procurar = (e: FormEvent) => {
        e.preventDefault();
        ir({ espera_q: termo.trim() });
    };

    return (
        <aside
            aria-label="Lista de espera"
            className="flex max-h-[calc(100vh-8rem)] flex-col rounded-lg border border-slate-200 bg-white lg:sticky lg:top-4"
        >
            <header className="border-b border-slate-200 px-3 py-2.5">
                <h2 className="text-sm font-semibold text-slate-900">Lista de espera</h2>
                <p className="text-xs text-slate-600">{podeAgendar ? 'Arraste um doente para um dia do calendário.' : 'Doentes por agendar.'}</p>
                <div className="mt-2 inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Doentes a mostrar">
                    {(
                        [
                            ['meus', 'Os meus'],
                            ['todos', 'Todos'],
                        ] as const
                    ).map(([valor, rotulo]) => (
                        <button
                            key={valor}
                            type="button"
                            aria-pressed={espera.filtro === valor}
                            onClick={() => ir({ espera: valor })}
                            className={`rounded px-3 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-teal-700 ${
                                espera.filtro === valor ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'
                            }`}
                        >
                            {rotulo}
                        </button>
                    ))}
                </div>
                <form onSubmit={procurar} role="search" className="mt-2">
                    <label htmlFor="espera-q" className="sr-only">
                        Procurar na lista de espera
                    </label>
                    <input
                        id="espera-q"
                        type="search"
                        value={termo}
                        onChange={(e) => setTermo(e.target.value)}
                        placeholder="Nome ou processo"
                        className={`${estiloEntrada} py-1.5 text-xs`}
                    />
                </form>
            </header>

            {espera.itens.length === 0 ? (
                <p className="px-3 py-6 text-center text-sm text-slate-600">
                    {espera.filtro === 'meus' ? 'Não tem doentes por agendar.' : 'Sem doentes por agendar.'}
                    {espera.filtro === 'meus' && (
                        <button
                            type="button"
                            onClick={() => ir({ espera: 'todos' })}
                            className="mt-1 block w-full text-xs font-medium text-teal-800 underline"
                        >
                            Ver todos
                        </button>
                    )}
                </p>
            ) : (
                <ul className="divide-y divide-slate-100 overflow-y-auto">
                    {espera.itens.map((e) => {
                        const dias = e.data ? Math.max(0, diasEntre(e.data, hoje)) : null;
                        return (
                            <li
                                key={e.id}
                                draggable={podeAgendar}
                                onDragStart={(ev) => {
                                    ev.dataTransfer.setData('text/plain', `espera:${e.id}`);
                                    ev.dataTransfer.effectAllowed = 'move';
                                    aoArrastar(e.id);
                                }}
                                onDragEnd={aoSoltar}
                                className={`px-3 py-2 ${podeAgendar ? 'cursor-grab active:cursor-grabbing' : ''}`}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium text-slate-900">{e.utente.nome ?? 'Utente sem ficha'}</p>
                                        <p className="text-xs text-slate-600 tabular-nums">
                                            Processo {e.utente.numero_processo ?? '—'}
                                            {dias !== null && dias < 3650 && ` · há ${dias} d`}
                                        </p>
                                    </div>
                                    {COR_PRIORIDADE[e.prioridade] && (
                                        <span className={`shrink-0 rounded px-1.5 py-0.5 text-xs font-medium ${COR_PRIORIDADE[e.prioridade]}`}>
                                            {PRIORIDADE[e.prioridade]}
                                        </span>
                                    )}
                                </div>
                                <p className="mt-0.5 line-clamp-2 text-xs text-slate-700">
                                    {e.intervencoes.length > 0 ? e.intervencoes.join(', ') : 'Sem intervenção indicada'}
                                </p>
                                <p className="mt-0.5 flex items-center justify-between text-xs text-slate-500">
                                    <span title={e.data ? `Inscrito a ${formatarData(e.data)}` : undefined}>
                                        {e.responsavel ?? 'Sem responsável'}
                                    </span>
                                    {podeAgendar && (
                                        <Link
                                            href={`/agendamento/lista-de-espera/${e.id}/agendar`}
                                            className="font-medium text-[#7d1830] hover:underline"
                                        >
                                            Agendar…
                                        </Link>
                                    )}
                                </p>
                            </li>
                        );
                    })}
                </ul>
            )}
            <footer className="border-t border-slate-200 px-3 py-1.5 text-xs text-slate-600">
                {espera.itens.length < espera.total ? `${espera.itens.length} de ${espera.total} · ` : `${espera.total} · `}
                <Link href="/agendamento/lista-de-espera" className="underline underline-offset-2">
                    Ver a lista completa
                </Link>
            </footer>
        </aside>
    );
}
