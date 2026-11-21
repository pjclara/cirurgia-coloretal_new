import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { diasEntre, formatarData, hojeIso } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import type { Paginado, Responsavel } from '../../clinico/tipos';
import { ModalCirurgia, type ModalCirurgiaDados } from '../../components/cirurgias/modal-cirurgia';
import { CaixaPesquisa } from '../../components/clinico/caixa-pesquisa';
import { BotaoLigacao, Selecao } from '../../components/clinico/campos';
import { NumeroProcesso } from '../../components/clinico/numero-processo';
import { Paginacao } from '../../components/clinico/paginacao';
import { ModalInternamento, type ModalInternamentoDados } from '../../components/internamentos/modal-internamento';
import ClinicoLayout from '../../layouts/clinico-layout';

type Linha = {
    id: number;
    utente: { id: number | null; nome: string | null; numero_processo: number | null };
    cama: string;
    localizacao: string | null;
    data_de_entrada: string | null;
    data_de_alta: string | null;
    diagnosticos: string[];
    responsavel: string | null;
    centro_referencia: boolean;
    destino: string | null;
    clavien: string | null;
    bloqueado: boolean;
};

type Filtros = {
    q: string;
    ano: number | null;
    responsavel_id: number | null;
    cr: boolean;
    cirurgia: boolean;
};

type Props = {
    internamentos: Paginado<Linha>;
    filtros: Filtros;
    responsaveis: Responsavel[];
    anos: number[];
    modal: ModalInternamentoDados | null;
    modalCirurgia: ModalCirurgiaDados | null;
};

export default function Internamentos({ internamentos, filtros, responsaveis, anos, modal, modalCirurgia }: Props) {
    const [termo, setTermo] = useState(filtros.q);

    const aplicar = (alteracoes: Partial<Filtros>) => {
        const novos = { ...filtros, q: termo.trim(), ...alteracoes };
        const consulta: Record<string, string | number> = {};
        if (novos.q) consulta.q = novos.q;
        if (novos.ano) consulta.ano = novos.ano;
        if (novos.responsavel_id) consulta.responsavel_id = novos.responsavel_id;
        if (novos.cr) consulta.cr = '1';
        if (novos.cirurgia) consulta.cirurgia = '1';
        router.get(rotas.internamentos.lista, consulta, { preserveState: true, preserveScroll: true });
    };

    const comFiltros = !!filtros.q || !!filtros.ano || !!filtros.responsavel_id || filtros.cr || filtros.cirurgia;

    return (
        <ClinicoLayout
            titulo="Internamentos"
            largura="larga"
            acoes={
                <>
                    <BotaoLigacao href={rotas.internamentos.seguimentos} variante="secundario">
                        Seguimentos aos 30 dias
                    </BotaoLigacao>
                </>
            }
        >
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <CaixaPesquisa
                    id="q"
                    termo={termo}
                    aoEscrever={setTermo}
                    aoPesquisar={() => aplicar({})}
                    rotulo="Procurar por utente, processo ou diagnóstico"
                    placeholder="Utente, processo ou diagnóstico"
                    className="min-w-64 flex-1 sm:max-w-md"
                />

                <div className="w-28">
                    <Selecao
                        aria-label="Ano de entrada"
                        opcoes={anos.map((a) => ({ id: a, nome: String(a) }))}
                        valor={filtros.ano ?? ''}
                        aoMudar={(v) => aplicar({ ano: v === '' ? null : v })}
                        vazio="Ano"
                    />
                </div>
                <div className="w-52">
                    <Selecao
                        aria-label="Responsável"
                        opcoes={responsaveis.map((r) => ({ id: r.id, nome: r.nome }))}
                        valor={filtros.responsavel_id ?? ''}
                        aoMudar={(v) => aplicar({ responsavel_id: v === '' ? null : v })}
                        vazio="Responsável"
                    />
                </div>

                <button
                    type="button"
                    aria-pressed={filtros.cr}
                    onClick={() => aplicar({ cr: !filtros.cr })}
                    className={`rounded-md border px-3 py-2 text-sm font-medium ${
                        filtros.cr
                            ? 'border-violet-700 bg-violet-100 text-violet-900 ring-1 ring-violet-700'
                            : 'border-slate-300 bg-white text-slate-700 hover:border-slate-500'
                    }`}
                >
                    Só centro de referência
                </button>

                <button
                    type="button"
                    aria-pressed={filtros.cirurgia}
                    onClick={() => aplicar({ cirurgia: !filtros.cirurgia })}
                    className={`rounded-md border px-3 py-2 text-sm font-medium ${
                        filtros.cirurgia
                            ? 'border-teal-700 bg-teal-100 text-teal-900 ring-1 ring-teal-700'
                            : 'border-slate-300 bg-white text-slate-700 hover:border-slate-500'
                    }`}
                >
                    Com cirurgia
                </button>

                {comFiltros && (
                    <Link href={rotas.internamentos.lista} className="py-2 text-sm font-medium text-teal-800 hover:underline">
                        Limpar filtros
                    </Link>
                )}
            </div>

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-slate-700">
                        <tr>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Utente
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Diagnósticos
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Entrada
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Alta
                            </th>
                            <th scope="col" className="px-4 py-2.5 text-right font-semibold">
                                Dias
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Local
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Resp.
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Destino
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {internamentos.data.map((i) => {
                            const dias = i.data_de_entrada ? diasEntre(i.data_de_entrada, i.data_de_alta ?? hojeIso()) : null;
                            const diasValidos = dias !== null && dias >= 0 && dias < 3650;
                            return (
                                <tr key={i.id} className="hover:bg-slate-50">
                                    <td className="px-4 py-2.5">
                                        <Link href={rotas.internamentos.naLista(i.id)} className="font-medium text-teal-800 hover:underline">
                                            {i.utente.nome ?? 'Utente sem ficha'}
                                        </Link>
                                        <span className="block text-xs text-slate-600 tabular-nums">
                                            <NumeroProcesso valor={i.utente.numero_processo} />
                                        </span>
                                        {i.centro_referencia && (
                                            <span className="mt-1 inline-block">
                                                <span
                                                    className="rounded bg-violet-100 px-1.5 py-0.5 text-xs font-medium text-violet-900"
                                                    title="Sinalizado para o centro de referência"
                                                >
                                                    Centro de referência
                                                </span>
                                            </span>
                                        )}
                                    </td>
                                    <td className="max-w-xs px-4 py-2.5 text-slate-800">
                                        <span className="line-clamp-2">{i.diagnosticos.length > 0 ? i.diagnosticos.join(' · ') : '—'}</span>
                                        {i.clavien && <span className="text-xs text-rose-800">Clavien-Dindo: {i.clavien}</span>}
                                    </td>
                                    <td className={`px-4 py-2.5 tabular-nums ${diasValidos ? 'text-slate-700' : 'font-medium text-rose-700'}`}>
                                        {formatarData(i.data_de_entrada)}
                                        {!diasValidos && <span className="block text-xs">Data a corrigir</span>}
                                    </td>
                                    <td className="px-4 py-2.5 text-slate-700 tabular-nums">
                                        {i.data_de_alta ? (
                                            formatarData(i.data_de_alta)
                                        ) : (
                                            <span className="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900">Internado</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2.5 text-right text-slate-700 tabular-nums">{diasValidos ? dias : '—'}</td>
                                    <td className="px-4 py-2.5 text-slate-700">
                                        {i.localizacao ?? '—'}
                                        <span className="block text-xs text-slate-600 tabular-nums">Cama {i.cama}</span>
                                    </td>
                                    <td className="px-4 py-2.5 text-slate-700">{i.responsavel ?? '—'}</td>
                                    <td className="px-4 py-2.5 text-slate-700">
                                        {i.destino ?? '—'}
                                        {i.bloqueado && <span className="block text-xs text-slate-500">Registo bloqueado</span>}
                                    </td>
                                </tr>
                            );
                        })}
                        {internamentos.data.length === 0 && (
                            <tr>
                                <td colSpan={8} className="px-4 py-10 text-center text-slate-600">
                                    {comFiltros ? 'Nenhum internamento corresponde a estes filtros.' : 'Ainda não há internamentos registados.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <Paginacao pagina={internamentos} nome="internamentos" />
            <ModalInternamento modal={modal} />
            <ModalCirurgia modal={modalCirurgia} />
        </ClinicoLayout>
    );
}

Internamentos.layout = (pagina: ReactNode) => pagina;
