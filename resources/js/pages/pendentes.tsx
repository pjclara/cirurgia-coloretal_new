import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { formatarData } from '../clinico/formato';
import type { Opcao, OpcaoAgrupada } from '../clinico/tipos';
import { ModalCirurgia, type ModalCirurgiaDados } from '../components/cirurgias/modal-cirurgia';
import type { ResolucaoComGrau } from '../components/clinico/bloco-morbilidade';
import { NumeroProcesso } from '../components/clinico/numero-processo';
import { ModalSeguimento } from '../components/internamentos/modal-seguimento';
import ClinicoLayout from '../layouts/clinico-layout';

type Utente = { id: number | null; nome: string | null; numero_processo: number | null };

type Props = {
    utilizadorId: number;
    limite: number;
    seguimentos: {
        total: number;
        linhas: { id: number; utente: Utente; diagnosticos: string[]; data_de_alta: string; prevista: string; dias_de_atraso: number }[];
    };
    cr: { total: number | null; linhas: { bloco: number; data: string; intervencoes: string[]; falta: string[]; utente: Utente }[] };
    modalCirurgia: ModalCirurgiaDados | null;
    podeRegistar: boolean;
    dominios: { complicacoes: OpcaoAgrupada[]; resolucoes: ResolucaoComGrau[]; clavien: Opcao[] };
};

const FALTA: Record<string, string> = { cr: 'dados do CR', descricao: 'descrição das intervenções' };

function Cartao({
    titulo,
    total,
    limite,
    aoFundo,
    vazio,
    children,
}: {
    titulo: string;
    total: number;
    limite: number;
    aoFundo?: { href: string; texto: string };
    vazio: string;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white p-4" aria-label={titulo}>
            <div className="mb-3 flex items-baseline justify-between gap-2">
                <h2 className="text-base font-semibold text-slate-900">{titulo}</h2>
                <span
                    className={`rounded px-2 py-0.5 text-sm font-semibold tabular-nums ${total > 0 ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-900'}`}
                >
                    {total}
                </span>
            </div>
            {total === 0 ? <p className="py-4 text-sm text-slate-600">{vazio}</p> : <ul className="divide-y divide-slate-100">{children}</ul>}
            {total > limite && aoFundo && (
                <p className="mt-3 text-sm text-slate-600">
                    A mostrar os {limite} primeiros.{' '}
                    <Link href={aoFundo.href} className="font-medium underline underline-offset-2">
                        {aoFundo.texto}
                    </Link>
                </p>
            )}
        </section>
    );
}

export default function Pendentes({ utilizadorId, limite, seguimentos, cr, modalCirurgia, podeRegistar, dominios }: Props) {
    // O seguimento regista-se numa janela por cima desta página, que continua aberta depois de gravar.
    const [abertoId, setAbertoId] = useState<number | null>(null);
    const indice = seguimentos.linhas.findIndex((l) => l.id === abertoId);
    const aberto = indice >= 0 ? seguimentos.linhas[indice] : null;
    const seguinte = indice >= 0 ? (seguimentos.linhas[indice + 1] ?? null) : null;

    return (
        <ClinicoLayout titulo="O que me falta fazer" subtitulo="Os internamentos de que é responsável">
            <div className="grid gap-5 lg:grid-cols-2">
                <Cartao
                    titulo="Seguimentos aos 30 dias"
                    total={seguimentos.total}
                    limite={limite}
                    vazio="Não tem seguimentos por registar."
                    aoFundo={{ href: `/internamentos/seguimentos?responsavel_id=${utilizadorId}`, texto: 'Ver todos nos seguimentos' }}
                >
                    {seguimentos.linhas.map((l) => (
                        <li key={l.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                            <div className="min-w-0">
                                {podeRegistar ? (
                                    <button
                                        type="button"
                                        onClick={() => setAbertoId(l.id)}
                                        className="text-left font-medium text-slate-900 hover:underline focus-visible:outline-2 focus-visible:outline-teal-700"
                                    >
                                        {l.utente.nome}
                                    </button>
                                ) : (
                                    <span className="font-medium text-slate-900">{l.utente.nome}</span>
                                )}
                                <p className="text-xs text-slate-600 tabular-nums">
                                    <NumeroProcesso valor={l.utente.numero_processo} /> · alta a {formatarData(l.data_de_alta)}
                                </p>
                            </div>
                            <span
                                className={`shrink-0 rounded px-1.5 py-0.5 text-xs font-medium ${l.dias_de_atraso > 0 ? 'bg-rose-100 text-rose-900' : 'bg-slate-100 text-slate-800'}`}
                            >
                                {l.dias_de_atraso > 0
                                    ? `${l.dias_de_atraso} dias em atraso`
                                    : l.dias_de_atraso === 0
                                      ? 'previsto hoje'
                                      : `faltam ${-l.dias_de_atraso} dias`}
                            </span>
                        </li>
                    ))}
                </Cartao>

                {cr.total !== null && (
                    <Cartao
                        titulo="Dados do centro de referência por preencher"
                        total={cr.total}
                        limite={limite}
                        vazio="Todas as cirurgias do CR estão completas."
                        aoFundo={{ href: `/analise/cancro-retal/qualidade?responsavel=${utilizadorId}`, texto: 'Ver na qualidade dos dados' }}
                    >
                        {cr.linhas.map((l) => (
                            <li key={l.bloco} className="flex items-center justify-between gap-3 py-2 text-sm">
                                <div className="min-w-0">
                                    <Link
                                        href={`/pendentes/cirurgias/${l.bloco}`}
                                        preserveScroll
                                        className="font-medium text-slate-900 hover:underline"
                                    >
                                        {l.utente.nome}
                                    </Link>
                                    <p className="truncate text-xs text-slate-600 tabular-nums">
                                        <NumeroProcesso valor={l.utente.numero_processo} /> · cirurgia a {formatarData(l.data)}
                                        {l.intervencoes.length > 0 ? ` · ${l.intervencoes.join(', ')}` : ''}
                                    </p>
                                </div>
                                <span className="shrink-0 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900">
                                    falta: {l.falta.map((f) => FALTA[f] ?? f).join(' e ')}
                                </span>
                            </li>
                        ))}
                    </Cartao>
                )}
            </div>

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

Pendentes.layout = (pagina: React.ReactNode) => pagina;
