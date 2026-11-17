import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { COR_ESTADO, PRIORIDADE, nomeEstado } from '../../clinico/agendamento';
import { formatarData } from '../../clinico/formato';
import type { Opcao, PropsPartilhadas } from '../../clinico/tipos';
import { ModalAgendamento, type Modal } from '../../components/agendamento/modal-agendamento';
import { Botao, BotaoLigacao, estiloEntrada } from '../../components/clinico/campos';
import { NumeroProcesso } from '../../components/clinico/numero-processo';
import ClinicoLayout from '../../layouts/clinico-layout';

type Linha = {
    id: number;
    utente: { id: number | null; nome: string | null; numero_processo: number | null };
    data: string | null;
    prioridade: number;
    estado: number;
    intervencoes: string[];
    diagnosticos: string[];
    responsavel: string | null;
    agendamento: { id: number; data: string | null } | null;
};

type Props = {
    lista: { data: Linha[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    filtros: { estado: string; prioridade: number | null; q: string };
    prioridades: Opcao[];
    estados: Opcao[];
    dataAlvo: string | null;
    modal: Modal | null;
};

const ESTADOS = [
    ['abertas', 'Em espera'],
    ['agendadas', 'Agendadas'],
    ['operadas', 'Operadas'],
    ['canceladas', 'Canceladas'],
    ['todas', 'Todas'],
];

export default function ListaDeEspera({ lista, filtros, prioridades, estados, dataAlvo, modal }: Props) {
    const { permissoes } = usePage<PropsPartilhadas>().props.clinico;
    const [q, setQ] = useState(filtros.q);
    const filtrar = (novos: Record<string, unknown>) =>
        router.get(
            '/agendamento/lista-de-espera',
            { ...filtros, ...(dataAlvo ? { agendar_em: dataAlvo } : {}), ...novos },
            { preserveState: true, replace: true },
        );

    return (
        <ClinicoLayout
            titulo="Lista de espera"
            subtitulo={
                dataAlvo
                    ? `Escolha o utente a agendar para ${formatarData(dataAlvo)}`
                    : `${lista.total} inscrições · para inscrever um utente, abra a sua ficha`
            }
            largura="larga"
            acoes={
                <BotaoLigacao href="/agendamento" variante="secundario">
                    Agenda
                </BotaoLigacao>
            }
        >
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <form
                    className="flex gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        filtrar({ q });
                    }}
                >
                    <input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Nome ou processo"
                        className={`${estiloEntrada} w-56`}
                        aria-label="Procurar utente"
                    />
                    <Botao type="submit" variante="secundario">
                        Procurar
                    </Botao>
                </form>
                {ESTADOS.map(([valor, rotulo]) => (
                    <Botao key={valor} variante={filtros.estado === valor ? 'primario' : 'secundario'} onClick={() => filtrar({ estado: valor })}>
                        {rotulo}
                    </Botao>
                ))}
                <select
                    value={filtros.prioridade ?? ''}
                    onChange={(e) => filtrar({ prioridade: e.target.value || null })}
                    className={`${estiloEntrada} w-auto`}
                    aria-label="Prioridade"
                >
                    <option value="">Todas as prioridades</option>
                    {prioridades.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.nome}
                        </option>
                    ))}
                </select>
            </div>

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-600">
                        <tr>
                            <th className="px-4 py-2 font-medium">Utente</th>
                            <th className="px-4 py-2 font-medium">Inscrição</th>
                            <th className="px-4 py-2 font-medium">Prioridade</th>
                            <th className="px-4 py-2 font-medium">Intervenção</th>
                            <th className="px-4 py-2 font-medium">Responsável</th>
                            <th className="px-4 py-2 font-medium">Estado</th>
                            <th className="px-4 py-2 font-medium">Cirurgia</th>
                            <th className="px-4 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {lista.data.map((l) => (
                            <tr key={l.id}>
                                <td className="px-4 py-2">
                                    {l.utente.id ? (
                                        <Link href={`/utentes/${l.utente.id}`} className="font-medium hover:underline">
                                            {l.utente.nome}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                    <span className="ml-2 text-xs text-slate-500 tabular-nums">
                                        <NumeroProcesso semRotulo valor={l.utente.numero_processo} />
                                    </span>
                                </td>
                                <td className="px-4 py-2 tabular-nums">{formatarData(l.data)}</td>
                                <td className="px-4 py-2">
                                    <span className={l.prioridade === 2 ? 'font-semibold text-[#7d1830]' : ''}>
                                        {PRIORIDADE[l.prioridade] ?? l.prioridade}
                                    </span>
                                </td>
                                <td className="max-w-xs px-4 py-2">
                                    {l.intervencoes.join(', ') || '—'}
                                    {l.diagnosticos.length > 0 && <p className="text-xs text-slate-500">{l.diagnosticos.join(', ')}</p>}
                                </td>
                                <td className="px-4 py-2">{l.responsavel ?? '—'}</td>
                                <td className="px-4 py-2">
                                    <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${COR_ESTADO[l.estado] ?? COR_ESTADO[6]}`}>
                                        {nomeEstado(estados, l.estado)}
                                    </span>
                                </td>
                                <td className="px-4 py-2 tabular-nums">
                                    {l.agendamento ? (
                                        <Link href={`/agendamento/${l.agendamento.id}`} className="text-[#7d1830] hover:underline">
                                            {formatarData(l.agendamento.data)}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                                <td className="space-x-3 px-4 py-2 text-right whitespace-nowrap">
                                    {permissoes.includes('create agendamento') && ![4, 5].includes(l.estado) && (
                                        <Link
                                            href={`/agendamento/lista-de-espera/${l.id}/agendar${dataAlvo ? `?agendar_em=${dataAlvo}` : ''}`}
                                            className="font-medium text-[#7d1830] hover:underline"
                                        >
                                            Agendar
                                        </Link>
                                    )}
                                    <Link href={`/agendamento/lista-de-espera/${l.id}`} className="text-slate-600 hover:underline">
                                        Abrir
                                    </Link>
                                </td>
                            </tr>
                        ))}
                        {lista.data.length === 0 && (
                            <tr>
                                <td colSpan={8} className="px-4 py-6 text-center text-slate-600">
                                    Nenhuma inscrição encontrada.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {lista.links.length > 3 && (
                <nav className="mt-4 flex flex-wrap gap-1" aria-label="Páginas">
                    {lista.links.map((l, n) => (
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
            <ModalAgendamento modal={modal} />
        </ClinicoLayout>
    );
}

ListaDeEspera.layout = (pagina: React.ReactNode) => pagina;
