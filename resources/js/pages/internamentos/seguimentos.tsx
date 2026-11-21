import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { formatarData } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import type { Opcao, OpcaoAgrupada, Paginado, Responsavel } from '../../clinico/tipos';
import { FiltroTrimestres } from '../../components/analise/filtro-trimestres';
import { ModalCirurgia, type ModalCirurgiaDados } from '../../components/cirurgias/modal-cirurgia';
import type { ResolucaoComGrau } from '../../components/clinico/bloco-morbilidade';
import { CaixaPesquisa } from '../../components/clinico/caixa-pesquisa';
import { Botao, BotaoLigacao, SelecaoResponsavel } from '../../components/clinico/campos';
import { NumeroProcesso } from '../../components/clinico/numero-processo';
import { Paginacao } from '../../components/clinico/paginacao';
import { ModalInternamento, type ModalInternamentoDados } from '../../components/internamentos/modal-internamento';
import { ModalSeguimento } from '../../components/internamentos/modal-seguimento';
import ClinicoLayout from '../../layouts/clinico-layout';

type Linha = {
    id: number;
    utente: { id: number | null; nome: string | null; numero_processo: number | null };
    diagnosticos: string[];
    data_de_alta: string;
    prevista: string;
    dias_de_atraso: number;
    responsavel: string | null;
    cr: boolean;
    clavien?: string | null;
    bloqueado: boolean;
};

type Estado = 'todos' | 'em_atraso' | 'a_decorrer';

type Props = {
    seguimentos: Paginado<Linha>;
    trimestres: string[];
    filtros: { estado: Estado; de: string | null; ate: string | null; responsavel_id: number | null; cr: boolean; q: string };
    responsaveis: Responsavel[];
    contagens: { em_atraso: number; a_decorrer: number; cr: number | null };
    podeVerCr: boolean;
    podeRegistar: boolean;
    modal: ModalInternamentoDados | null;
    modalCirurgia: ModalCirurgiaDados | null;
    dominios: { complicacoes: OpcaoAgrupada[]; resolucoes: ResolucaoComGrau[]; clavien: Opcao[] };
};

export default function Seguimentos({
    seguimentos,
    trimestres,
    filtros,
    responsaveis,
    contagens,
    podeVerCr,
    podeRegistar,
    dominios,
    modal,
    modalCirurgia,
}: Props) {
    const consultaAtual = usePage().url.includes('?') ? usePage().url.slice(usePage().url.indexOf('?')) : '';
    const [termo, setTermo] = useState(filtros.q);
    const [abertoId, setAbertoId] = useState<number | null>(null);
    const indice = seguimentos.data.findIndex((l) => l.id === abertoId);
    const aberto = indice >= 0 ? seguimentos.data[indice] : null;
    const seguinte = indice >= 0 ? (seguimentos.data[indice + 1] ?? null) : null;
    const aplicar = (alteracoes: Partial<Props['filtros']>) => {
        const novos = { ...filtros, q: termo.trim(), ...alteracoes };
        const consulta: Record<string, string> = {};
        if (novos.estado !== 'todos') consulta.estado = novos.estado;
        if (novos.de) consulta.de = novos.de;
        if (novos.ate) consulta.ate = novos.ate;
        if (novos.responsavel_id) consulta.responsavel_id = String(novos.responsavel_id);
        if (novos.cr) consulta.cr = '1';
        if (novos.q) consulta.q = novos.q;
        router.get(rotas.internamentos.seguimentos, consulta, { preserveState: true, preserveScroll: true });
    };

    const extra: Record<string, string> = {};
    if (filtros.estado !== 'todos') extra.estado = filtros.estado;
    if (filtros.responsavel_id) extra.responsavel_id = String(filtros.responsavel_id);
    if (filtros.cr) extra.cr = '1';
    if (filtros.q) extra.q = filtros.q;

    const cartoes: { estado: Estado; rotulo: string; valor: number; cor: string }[] = [
        { estado: 'todos', rotulo: 'Por registar', valor: contagens.em_atraso + contagens.a_decorrer, cor: 'text-slate-900' },
        { estado: 'em_atraso', rotulo: 'Em atraso', valor: contagens.em_atraso, cor: 'text-rose-800' },
        { estado: 'a_decorrer', rotulo: 'A decorrer', valor: contagens.a_decorrer, cor: 'text-teal-800' },
    ];

    return (
        <ClinicoLayout
            titulo="Seguimentos aos 30 dias"
            subtitulo="Altas sem morbilidade registada aos 30 dias. Não inclui doentes falecidos. Os doentes do CR (centro de referência) estão identificados com «CR» e podem filtrar-se."
            largura="larga"
            acoes={
                <BotaoLigacao href={rotas.internamentos.lista} variante="secundario">
                    Internamentos
                </BotaoLigacao>
            }
        >
            <div className="mb-4 flex flex-wrap items-stretch gap-3">
                {cartoes.map((c) => (
                    <button
                        key={c.estado}
                        type="button"
                        aria-pressed={filtros.estado === c.estado}
                        onClick={() => aplicar({ estado: c.estado })}
                        className={`min-w-36 rounded-lg border bg-white px-4 py-2.5 text-left focus-visible:outline-2 focus-visible:outline-teal-700 ${
                            filtros.estado === c.estado ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-200 hover:border-slate-400'
                        }`}
                    >
                        <span className={`block text-2xl font-semibold tabular-nums ${c.cor}`}>{c.valor}</span>
                        <span className="text-sm text-slate-600">{c.rotulo}</span>
                    </button>
                ))}

                {podeVerCr && (
                    <button
                        type="button"
                        aria-pressed={filtros.cr}
                        onClick={() => aplicar({ cr: !filtros.cr })}
                        className={`min-w-36 rounded-lg border bg-white px-4 py-2.5 text-left focus-visible:outline-2 focus-visible:outline-teal-700 ${
                            filtros.cr ? 'border-violet-700 ring-1 ring-violet-700' : 'border-slate-200 hover:border-slate-400'
                        }`}
                    >
                        <span className="block text-2xl font-semibold text-violet-800 tabular-nums">{contagens.cr}</span>
                        <span className="text-sm text-slate-600">{filtros.cr ? 'Só doentes do CR' : 'Doentes do CR'}</span>
                    </button>
                )}

                <div className="ml-auto flex flex-wrap items-end gap-2">
                    <label className="text-sm">
                        <span className="sr-only">Médico responsável</span>
                        <SelecaoResponsavel
                            responsaveis={responsaveis}
                            valor={filtros.responsavel_id ?? ''}
                            aoMudar={(v) => aplicar({ responsavel_id: v === '' ? null : v })}
                            vazio="Todos os médicos"
                            className="w-56"
                        />
                    </label>
                </div>
            </div>

            {trimestres.length > 0 && (
                <div className="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2">
                    <FiltroTrimestres url={rotas.internamentos.seguimentos} trimestres={trimestres} de={filtros.de} ate={filtros.ate} extra={extra} />
                </div>
            )}

            <CaixaPesquisa
                id="q"
                termo={termo}
                aoEscrever={setTermo}
                aoPesquisar={() => aplicar({})}
                rotulo="Procurar por nome ou processo"
                placeholder="Nome ou processo"
                className="mb-4 max-w-md"
            />

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-600 uppercase">
                        <tr>
                            <th className="px-4 py-2.5 font-medium">Utente</th>
                            <th className="px-4 py-2.5 font-medium">Diagnósticos</th>
                            <th className="px-4 py-2.5 font-medium">Alta</th>
                            <th className="px-4 py-2.5 font-medium">Seguimento previsto</th>
                            <th className="px-4 py-2.5 font-medium">Responsável</th>
                            <th className="px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {seguimentos.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-slate-500">
                                    Não há seguimentos por registar neste período.
                                </td>
                            </tr>
                        )}
                        {seguimentos.data.map((l) => (
                            <tr key={l.id}>
                                <td className="px-4 py-2.5">
                                    {l.utente.id ? (
                                        <Link href={rotas.internamentos.nosSeguimentos(l.id, consultaAtual)} className="font-medium hover:underline">
                                            {l.utente.nome}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                    {l.cr && (
                                        <span
                                            className="ml-2 rounded bg-violet-100 px-1.5 py-0.5 text-xs font-semibold text-violet-900"
                                            title="Doente referenciado ao centro de referência"
                                        >
                                            CR
                                        </span>
                                    )}
                                    <p className="text-xs text-slate-500 tabular-nums">
                                        <NumeroProcesso valor={l.utente.numero_processo} />
                                    </p>
                                </td>
                                <td className="max-w-xs px-4 py-2.5">
                                    <span className="line-clamp-2">{l.diagnosticos.length > 0 ? l.diagnosticos.join(' · ') : '—'}</span>
                                </td>
                                <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">{formatarData(l.data_de_alta)}</td>
                                <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">
                                    {formatarData(l.prevista)}
                                    {l.dias_de_atraso > 0 ? (
                                        <span className="ml-2 rounded bg-rose-100 px-1.5 py-0.5 text-xs font-medium text-rose-900">
                                            {l.dias_de_atraso} dias em atraso
                                        </span>
                                    ) : (
                                        <span className="ml-2 rounded bg-teal-100 px-1.5 py-0.5 text-xs font-medium text-teal-900">
                                            {l.dias_de_atraso === 0 ? 'hoje' : `daqui a ${-l.dias_de_atraso} dias`}
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-2.5">{l.responsavel ?? '—'}</td>
                                <td className="px-4 py-2.5 text-right whitespace-nowrap">
                                    {podeRegistar && !l.bloqueado ? (
                                        <Botao
                                            variante="secundario"
                                            onClick={() => setAbertoId(l.id)}
                                            disabled={l.dias_de_atraso < 0}
                                            title={
                                                l.dias_de_atraso < 0 ? `Só a partir de ${formatarData(l.prevista)} (30 dias após a alta)` : undefined
                                            }
                                        >
                                            Registar
                                        </Botao>
                                    ) : (
                                        <Link
                                            href={rotas.internamentos.registo(l.id)}
                                            className="font-medium text-[#7d1830] hover:underline"
                                            title={l.bloqueado ? 'Registo bloqueado' : undefined}
                                        >
                                            Abrir
                                        </Link>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <Paginacao pagina={seguimentos} nome="seguimentos" />
            </div>

            <ModalInternamento modal={modal} />
            <ModalCirurgia modal={modalCirurgia} />

            {aberto && (
                <ModalSeguimento
                    key={aberto.id}
                    linha={aberto}
                    dominios={dominios}
                    temSeguinte={seguinte !== null}
                    aoFechar={() => setAbertoId(null)}
                    aoGuardar={(irParaSeguinte) => setAbertoId(irParaSeguinte && seguinte ? seguinte.id : null)}
                />
            )}
        </ClinicoLayout>
    );
}

Seguimentos.layout = (pagina: React.ReactNode) => pagina;
