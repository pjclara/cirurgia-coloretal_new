import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { formatarData } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import type { Paginado, PropsPartilhadas } from '../../clinico/tipos';
import { CaixaPesquisa } from '../../components/clinico/caixa-pesquisa';
import { BotaoLigacao, estiloEntrada } from '../../components/clinico/campos';
import { NumeroProcesso } from '../../components/clinico/numero-processo';
import { Paginacao } from '../../components/clinico/paginacao';
import { PesquisaUtente } from '../../components/clinico/pesquisa-utente';
import { ModalInternamento, type ModalInternamentoDados } from '../../components/internamentos/modal-internamento';
import { FormularioReferenciacao, type PropsReferenciacao } from '../../components/referenciacao/formulario-referenciacao';
import { SinalizarCentro } from '../../components/referenciacao/sinalizar-centro';
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
    filtros: { situacao: Situacao; q: string; ano: string; trimestre: string };
    anos: string[];
    modal: PropsReferenciacao | null;
    modalInternamento: ModalInternamentoDados | null;
    origens: { id: number; nome: string }[];
    responsaveis: { id: number; nome: string; equipa: string | null }[];
    /** Utente a sinalizar de imediato (acabado de criar). */
    referenciar: { id: number; nome: string; numero_processo: number } | null;
};

const SITUACOES: { valor: Situacao; rotulo: string }[] = [
    { valor: 'todas', rotulo: 'Todas' },
    { valor: 'referenciado', rotulo: 'Referenciados' },
    { valor: 'no_centro', rotulo: 'No centro' },
    { valor: 'saida', rotulo: 'Com saída' },
];

const COR = { referenciado: 'bg-sky-100 text-sky-900', no_centro: 'bg-amber-100 text-amber-900', saida: 'bg-teal-100 text-teal-900' } as const;
const ROTULO = { referenciado: 'Referenciado', no_centro: 'No centro', saida: 'Saída registada' } as const;

export default function Referenciacao({ lista, filtros, anos, modal, modalInternamento, origens, responsaveis, referenciar }: Props) {
    const { permissoes } = usePage<PropsPartilhadas>().props.clinico;
    const [termo, setTermo] = useState(filtros.q);
    const podeReferenciar = permissoes.includes('create referenciacao');
    // Referenciação rápida: um utente escolhido na pesquisa (ou acabado de criar), sem precisar de internamento nem de agendamento.
    const [alvo, setAlvo] = useState<{ id: number; nome: string } | null>(referenciar);
    useEffect(() => setAlvo(referenciar), [referenciar?.id]); // eslint-disable-line react-hooks/exhaustive-deps
    const fecharSinalizacao = () => {
        setAlvo(null);
        if (referenciar) router.get('/referenciacao', {}, { replace: true, preserveState: true, preserveScroll: true });
    };
    const aplicar = (alteracoes: Partial<Props['filtros']>) => {
        const novos = { ...filtros, q: termo.trim(), ...alteracoes };
        const consulta: Record<string, string> = {};
        if (novos.situacao !== 'todas') consulta.situacao = novos.situacao;
        if (novos.q) consulta.q = novos.q;
        if (novos.ano) consulta.ano = novos.ano;
        if (novos.ano && novos.trimestre) consulta.trimestre = novos.trimestre;
        router.get('/referenciacao', consulta, { preserveState: true, preserveScroll: true });
    };
    const fechar = () => modal && router.visit(modal.fechar_url, { preserveScroll: true });

    return (
        <ClinicoLayout
            titulo="Referenciação"
            subtitulo={`${lista.total} referenciações ao centro de referência`}
            largura="larga"
            acoes={
                <BotaoLigacao href="/referenciacao/analise" variante="secundario">
                    Análise
                </BotaoLigacao>
            }
        >
            {podeReferenciar && (
                <section aria-label="Referenciar um doente" className="mb-5 max-w-xl rounded-lg border border-violet-200 bg-violet-50/60 px-4 py-3">
                    <p className="mb-2 text-sm font-medium text-slate-900">Referenciar um doente</p>
                    <PesquisaUtente
                        podeCriar={permissoes.includes('create internamento')}
                        criarHref="/quadro/novo-utente?origem=referenciacao"
                        placeholder="Procurar o doente por nome ou processo"
                        aoEscolher={(u) => (u.referenciado ? router.visit(rotas.utentes.ficha(u.id)) : setAlvo({ id: u.id, nome: u.nome }))}
                    />
                    <p className="mt-1.5 text-xs text-slate-600">
                        Não precisa de estar internado nem agendado. Se já estiver referenciado, abre a ficha do doente.
                    </p>
                </section>
            )}

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
                <div className="flex flex-wrap items-end gap-2" role="group" aria-label="Período da referenciação">
                    <label className="text-xs text-slate-600">
                        Ano
                        <select
                            value={filtros.ano}
                            onChange={(e) => aplicar({ ano: e.target.value, trimestre: e.target.value ? filtros.trimestre : '' })}
                            className={`${estiloEntrada} mt-0.5 block`}
                        >
                            <option value="">Todos</option>
                            {anos.map((a) => (
                                <option key={a} value={a}>
                                    {a}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-xs text-slate-600">
                        Trimestre
                        <select
                            value={filtros.trimestre}
                            disabled={!filtros.ano}
                            onChange={(e) => aplicar({ trimestre: e.target.value })}
                            className={`${estiloEntrada} mt-0.5 block`}
                        >
                            <option value="">Todos</option>
                            {['1', '2', '3', '4'].map((t) => (
                                <option key={t} value={t}>
                                    T{t}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                <CaixaPesquisa
                    id="q"
                    termo={termo}
                    aoEscrever={setTermo}
                    aoPesquisar={() => aplicar({})}
                    rotulo="Procurar por utente ou processo"
                    placeholder="Utente ou processo"
                    className="min-w-64 flex-1 sm:max-w-md"
                />
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
                                    <p className="text-xs text-slate-500 tabular-nums">
                                        <NumeroProcesso valor={l.utente.numero_processo} />
                                    </p>
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

            <SinalizarCentro
                key={alvo?.id ?? 'nenhum'}
                utenteId={alvo?.id ?? 0}
                nome={alvo?.nome}
                origens={origens}
                responsaveis={responsaveis}
                aberto={alvo !== null}
                aoFechar={fecharSinalizacao}
            />

            <Dialog open={modal !== null} onOpenChange={(aberto) => !aberto && fechar()}>
                <DialogContent
                    className="max-h-[90vh] max-w-4xl overflow-y-auto bg-white text-slate-900"
                    onInteractOutside={(e) => e.preventDefault()}
                    aria-describedby={undefined}
                >
                    {modal && <FormularioReferenciacao key={modal.referenciacao?.id ?? modal.utente.id} {...modal} fechar={fechar} />}
                </DialogContent>
            </Dialog>
            <ModalInternamento modal={modalInternamento} />
        </ClinicoLayout>
    );
}

Referenciacao.layout = (pagina: React.ReactNode) => pagina;
