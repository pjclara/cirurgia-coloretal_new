import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { formatarData } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import type { Opcao, OpcaoAgrupada, Paginado, Responsavel } from '../../clinico/tipos';
import type { ResolucaoComGrau } from '../../components/clinico/bloco-morbilidade';
import { Botao, BotaoLigacao, SelecaoResponsavel } from '../../components/clinico/campos';
import { NumeroProcesso } from '../../components/clinico/numero-processo';
import { Paginacao } from '../../components/clinico/paginacao';
import { ModalSeguimento } from '../../components/internamentos/modal-seguimento';
import ClinicoLayout from '../../layouts/clinico-layout';

type Linha = {
    id: number;
    utente: { id: number | null; nome: string | null; numero_processo: number | null };
    motivo: string;
    data_de_alta: string;
    prevista: string;
    dias_de_atraso: number;
    responsavel: string | null;
    clavien?: string | null;
    bloqueado: boolean;
};

type Estado = 'todos' | 'em_atraso' | 'a_decorrer';
type Periodo = '90' | '365' | 'todos';

type Props = {
    seguimentos: Paginado<Linha>;
    filtros: { estado: Estado; periodo: Periodo; responsavel_id: number | null };
    responsaveis: Responsavel[];
    contagens: { em_atraso: number; a_decorrer: number };
    podeRegistar: boolean;
    dominios: { complicacoes: OpcaoAgrupada[]; resolucoes: ResolucaoComGrau[]; clavien: Opcao[] };
};

const PERIODOS: { valor: Periodo; rotulo: string }[] = [
    { valor: '90', rotulo: 'Altas dos últimos 90 dias' },
    { valor: '365', rotulo: 'Último ano' },
    { valor: 'todos', rotulo: 'Todas as altas' },
];

export default function Seguimentos({ seguimentos, filtros, responsaveis, contagens, podeRegistar, dominios }: Props) {
    const [abertoId, setAbertoId] = useState<number | null>(null);
    const indice = seguimentos.data.findIndex((l) => l.id === abertoId);
    const aberto = indice >= 0 ? seguimentos.data[indice] : null;
    const seguinte = indice >= 0 ? (seguimentos.data[indice + 1] ?? null) : null;
    const aplicar = (alteracoes: Partial<Props['filtros']>) => {
        const novos = { ...filtros, ...alteracoes };
        const consulta: Record<string, string> = {};
        if (novos.estado !== 'todos') consulta.estado = novos.estado;
        if (novos.periodo !== '90') consulta.periodo = novos.periodo;
        if (novos.responsavel_id) consulta.responsavel_id = String(novos.responsavel_id);
        router.get(rotas.internamentos.seguimentos, consulta, { preserveState: true, preserveScroll: true });
    };

    const cartoes: { estado: Estado; rotulo: string; valor: number; cor: string }[] = [
        { estado: 'todos', rotulo: 'Por registar', valor: contagens.em_atraso + contagens.a_decorrer, cor: 'text-slate-900' },
        { estado: 'em_atraso', rotulo: 'Em atraso', valor: contagens.em_atraso, cor: 'text-rose-800' },
        { estado: 'a_decorrer', rotulo: 'A decorrer', valor: contagens.a_decorrer, cor: 'text-teal-800' },
    ];

    return (
        <ClinicoLayout
            titulo="Seguimentos aos 30 dias"
            subtitulo="Altas sem morbilidade registada aos 30 dias. Não inclui doentes falecidos."
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
                    <label className="text-sm">
                        <span className="sr-only">Período</span>
                        <select
                            value={filtros.periodo}
                            onChange={(e) => aplicar({ periodo: e.target.value as Periodo })}
                            className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                        >
                            {PERIODOS.map((p) => (
                                <option key={p.valor} value={p.valor}>
                                    {p.rotulo}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
            </div>

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-600 uppercase">
                        <tr>
                            <th className="px-4 py-2.5 font-medium">Utente</th>
                            <th className="px-4 py-2.5 font-medium">Motivo</th>
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
                                        <Link href={rotas.utentes.ficha(l.utente.id)} className="font-medium hover:underline">
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
                                    <span className="line-clamp-2">{l.motivo}</span>
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
