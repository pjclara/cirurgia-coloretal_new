import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { formatarData } from '../../clinico/formato';
import type { Paginado, PropsPartilhadas } from '../../clinico/tipos';
import { Botao, estiloEntrada } from '../../components/clinico/campos';
import { Paginacao } from '../../components/clinico/paginacao';
import { FormularioReferenciacao, type PropsReferenciacao } from '../../components/referenciacao/formulario-referenciacao';
import ClinicoLayout from '../../layouts/clinico-layout';

type Linha = {
    id: number;
    utente: { id: number | null; nome: string | null; numero_processo: number | null };
    data_de_referenciacao: string | null;
    origem: string | null;
    data_de_entrada: string | null;
    data_de_saida: string | null;
    destino: string | null;
    responsavel: string | null;
    situacao: 'referenciado' | 'no_centro' | 'saida';
    diagnosticos: string[];
};

type Situacao = 'todas' | 'referenciado' | 'no_centro' | 'saida';

type Props = {
    lista: Paginado<Linha>;
    filtros: { situacao: Situacao; q: string };
    modal: PropsReferenciacao | null;
};

const SITUACOES: { valor: Situacao; rotulo: string }[] = [
    { valor: 'todas', rotulo: 'Todas' },
    { valor: 'referenciado', rotulo: 'Referenciados' },
    { valor: 'no_centro', rotulo: 'No centro' },
    { valor: 'saida', rotulo: 'Com saída' },
];

const COR = { referenciado: 'bg-sky-100 text-sky-900', no_centro: 'bg-amber-100 text-amber-900', saida: 'bg-teal-100 text-teal-900' } as const;
const ROTULO = { referenciado: 'Referenciado', no_centro: 'No centro', saida: 'Saída registada' } as const;

export default function Referenciacao({ lista, filtros, modal }: Props) {
    const { permissoes } = usePage<PropsPartilhadas>().props.clinico;
    const [termo, setTermo] = useState(filtros.q);
    const aplicar = (alteracoes: Partial<Props['filtros']>) => {
        const novos = { ...filtros, q: termo.trim(), ...alteracoes };
        const consulta: Record<string, string> = {};
        if (novos.situacao !== 'todas') consulta.situacao = novos.situacao;
        if (novos.q) consulta.q = novos.q;
        router.get('/referenciacao', consulta, { preserveState: true, preserveScroll: true });
    };
    const fechar = () => modal && router.visit(modal.fechar_url, { preserveScroll: true });

    return (
        <ClinicoLayout
            titulo="Referenciação"
            subtitulo={`${lista.total} referenciações ao centro de referência · para referenciar um utente, abra a sua ficha`}
            largura="larga"
        >
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Situação">
                    {SITUACOES.map((s) => (
                        <button
                            key={s.valor}
                            type="button"
                            aria-pressed={filtros.situacao === s.valor}
                            onClick={() => aplicar({ situacao: s.valor })}
                            className={`rounded px-3 py-1.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-teal-700 ${filtros.situacao === s.valor ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'}`}
                        >
                            {s.rotulo}
                        </button>
                    ))}
                </div>
                <form
                    onSubmit={(e: FormEvent) => {
                        e.preventDefault();
                        aplicar({});
                    }}
                    className="flex min-w-64 flex-1 gap-2 sm:max-w-md"
                    role="search"
                >
                    <label htmlFor="q" className="sr-only">
                        Procurar por utente ou processo
                    </label>
                    <input
                        id="q"
                        type="search"
                        value={termo}
                        onChange={(e) => setTermo(e.target.value)}
                        placeholder="Utente ou processo"
                        className={estiloEntrada}
                    />
                    <Botao type="submit" variante="secundario">
                        Procurar
                    </Botao>
                </form>
            </div>

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-600 uppercase">
                        <tr>
                            <th className="px-4 py-2.5 font-medium">Utente</th>
                            <th className="px-4 py-2.5 font-medium">Diagnósticos</th>
                            <th className="px-4 py-2.5 font-medium">Referenciação</th>
                            <th className="px-4 py-2.5 font-medium">Entrada</th>
                            <th className="px-4 py-2.5 font-medium">Saída</th>
                            <th className="px-4 py-2.5 font-medium">Situação</th>
                            <th className="px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {lista.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-8 text-center text-slate-500">
                                    Nenhuma referenciação encontrada.
                                </td>
                            </tr>
                        )}
                        {lista.data.map((l) => (
                            <tr key={l.id}>
                                <td className="px-4 py-2.5">
                                    {l.utente.id ? (
                                        <Link href={`/utentes/${l.utente.id}`} className="font-medium hover:underline">
                                            {l.utente.nome}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                    <p className="text-xs text-slate-500 tabular-nums">Processo {l.utente.numero_processo ?? '—'}</p>
                                </td>
                                <td className="max-w-xs px-4 py-2.5">
                                    <span className="line-clamp-2">{l.diagnosticos.length > 0 ? l.diagnosticos.join(', ') : '—'}</span>
                                    <span className="text-xs text-slate-500">{[l.origem, l.responsavel].filter(Boolean).join(' · ')}</span>
                                </td>
                                <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">{formatarData(l.data_de_referenciacao)}</td>
                                <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">{formatarData(l.data_de_entrada)}</td>
                                <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">
                                    {formatarData(l.data_de_saida)}
                                    {l.destino && <span className="block text-xs text-slate-500">{l.destino}</span>}
                                </td>
                                <td className="px-4 py-2.5">
                                    <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${COR[l.situacao]}`}>{ROTULO[l.situacao]}</span>
                                </td>
                                <td className="px-4 py-2.5 text-right whitespace-nowrap">
                                    {permissoes.includes('show referenciacao') && (
                                        <Link href={`/referenciacao/${l.id}`} className="font-medium text-[#7d1830] hover:underline">
                                            Abrir
                                        </Link>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <Paginacao pagina={lista} nome="referenciações" />
            </div>

            <Dialog open={modal !== null} onOpenChange={(aberto) => !aberto && fechar()}>
                <DialogContent
                    className="max-h-[90vh] max-w-4xl overflow-y-auto bg-white text-slate-900"
                    onInteractOutside={(e) => e.preventDefault()}
                    aria-describedby={undefined}
                >
                    {modal && <FormularioReferenciacao key={modal.referenciacao?.id ?? modal.utente.id} {...modal} fechar={fechar} />}
                </DialogContent>
            </Dialog>
        </ClinicoLayout>
    );
}

Referenciacao.layout = (pagina: React.ReactNode) => pagina;
