import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { formatarData } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import { FiltroTrimestres } from '../../components/analise/filtro-trimestres';
import { ESTILO_VIZ, formatar } from '../../components/analise/graficos';
import { NumeroProcesso } from '../../components/clinico/numero-processo';
import { ModalInternamento, type ModalInternamentoDados } from '../../components/internamentos/modal-internamento';
import ClinicoLayout from '../../layouts/clinico-layout';

type Campo = 'alta' | 'destino' | 'morbilidade_alta' | 'seguimento_30' | 'clavien_30' | 'datas' | 'cr' | 'descricao_cr';
type Prioridade = 'alta' | 'media' | 'baixa';
type Qualidade = {
    cirurgias: number;
    totais: Record<Campo, number>;
    linhas: {
        bloco: number;
        internamento: number;
        utente: { id: number; nome: string | null; numero_processo: number | null };
        data: string;
        intervencao: string;
        responsavel: { id: number; nome: string };
        falta: Campo[];
        prioridade: Prioridade;
        motivo: string;
    }[];
    prioridades: Record<Prioridade, number>;
    responsaveis: { id: number; nome: string; cirurgias: number; com_falta: number; totais: Record<Campo, number> }[];
    responsavel: number | null;
};

const PRIORIDADES: { valor: Prioridade; rotulo: string; classe: string }[] = [
    { valor: 'alta', rotulo: 'Alta', classe: 'bg-rose-100 text-rose-900' },
    { valor: 'media', rotulo: 'Média', classe: 'bg-amber-100 text-amber-900' },
    { valor: 'baixa', rotulo: 'Baixa', classe: 'bg-slate-200 text-slate-800' },
];

const CAMPOS: { campo: Campo; rotulo: string; ajuda: string }[] = [
    { campo: 'alta', rotulo: 'Data de alta', ajuda: 'Sem data de alta registada' },
    { campo: 'destino', rotulo: 'Destino', ajuda: 'Sem destino à alta (necessário para a mortalidade)' },
    { campo: 'morbilidade_alta', rotulo: 'Morbilidade na alta', ajuda: 'Sem registo de complicações na alta' },
    { campo: 'seguimento_30', rotulo: 'Seguimento 30 dias', ajuda: 'Doente vivo, alta há mais de 30 dias, sem registo aos 30 dias' },
    { campo: 'clavien_30', rotulo: 'Clavien-Dindo 30 dias', ajuda: 'Registo aos 30 dias sem grau de Clavien-Dindo' },
    { campo: 'datas', rotulo: 'Datas', ajuda: 'Cirurgia antes da entrada ou depois da alta' },
    { campo: 'cr', rotulo: 'Dados do CR', ajuda: 'Dados do centro de referência da cirurgia por preencher' },
    { campo: 'descricao_cr', rotulo: 'Descrição das intervenções', ajuda: 'Descrição das intervenções de ressecção por preencher' },
];

type Props = {
    modal: ModalInternamentoDados | null;
    qualidade: Qualidade;
    trimestres: string[];
    de: string | null;
    ate: string | null;
    geradoEm: string;
};

export default function QualidadeDados({ modal, qualidade, trimestres, de, ate, geradoEm }: Props) {
    const [aCarregar, setACarregar] = useState(false);

    const escolher = (id: number | null) =>
        router.get(
            '/analise/cancro-retal/qualidade',
            { de: de ?? '', ate: ate ?? '', ...(id !== null ? { responsavel: String(id) } : {}) },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setACarregar(true),
                onFinish: () => setACarregar(false),
            },
        );
    // Os filtros em vigor acompanham a modal, para a página ficar igual ao fechar ou gravar.
    const consultaAtual = `?${new URLSearchParams({
        de: de ?? '',
        ate: ate ?? '',
        ...(qualidade.responsavel !== null ? { responsavel: String(qualidade.responsavel) } : {}),
    }).toString()}`;
    const selecionado = qualidade.responsaveis.find((r) => r.id === qualidade.responsavel);

    return (
        <ClinicoLayout titulo="Qualidade dos dados" subtitulo={`Cancro retal · doentes do CR · gerada a ${geradoEm}`} largura="larga">
            <style>{ESTILO_VIZ}</style>
            <div className="viz-root space-y-5">
                <div className="flex flex-wrap items-center gap-4">
                    <FiltroTrimestres
                        url="/analise/cancro-retal/qualidade"
                        trimestres={trimestres}
                        de={de}
                        ate={ate}
                        aoMudar={setACarregar}
                        extra={qualidade.responsavel !== null ? { responsavel: String(qualidade.responsavel) } : undefined}
                    />
                    <label className="flex items-center gap-2 text-sm">
                        Responsável
                        <select
                            className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-transparent"
                            value={qualidade.responsavel ?? ''}
                            onChange={(e) => escolher(e.target.value === '' ? null : Number(e.target.value))}
                        >
                            <option value="">Todos</option>
                            {qualidade.responsaveis.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.nome}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                <div className={`space-y-5 transition-opacity ${aCarregar ? 'opacity-50' : ''}`}>
                    <section
                        className="rounded-lg border bg-[var(--surface)] p-4"
                        style={{ borderColor: 'var(--border)' }}
                        aria-label="Dados em falta por responsável"
                    >
                        <h2 className="text-sm font-semibold text-[var(--ink)]">Por responsável</h2>
                        <p className="mb-3 text-xs text-[var(--ink2)]">
                            Responsável pelo internamento da cirurgia. Cirurgias com pelo menos um dado em falta e, por campo, quantas faltam. Escolhe
                            um responsável para ver só os seus doentes.
                        </p>
                        {qualidade.responsaveis.length === 0 ? (
                            <p className="py-6 text-center text-sm text-[var(--ink2)]">Sem cirurgias neste período.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs text-[var(--ink)]">
                                    <thead>
                                        <tr className="border-b text-[var(--ink2)]" style={{ borderColor: 'var(--grid)' }}>
                                            <th className="py-1.5 pr-3 font-medium">Responsável</th>
                                            <th className="py-1.5 pr-3 font-medium">Cirurgias</th>
                                            <th className="py-1.5 pr-3 font-medium">Com dados em falta</th>
                                            {CAMPOS.map((c) => (
                                                <th key={c.campo} className="py-1.5 pr-3 font-medium" title={c.ajuda}>
                                                    {c.rotulo}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {qualidade.responsaveis.map((r) => (
                                            <tr
                                                key={r.id}
                                                className={`border-b ${r.id === qualidade.responsavel ? 'bg-black/5 dark:bg-white/10' : ''}`}
                                                style={{ borderColor: 'var(--grid)' }}
                                            >
                                                <td className="py-1.5 pr-3">
                                                    <button
                                                        type="button"
                                                        onClick={() => escolher(r.id === qualidade.responsavel ? null : r.id)}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {r.nome}
                                                    </button>
                                                </td>
                                                <td className="py-1.5 pr-3 tabular-nums">{formatar(r.cirurgias)}</td>
                                                <td className="py-1.5 pr-3 tabular-nums">
                                                    {formatar(r.com_falta)} ({formatar(Math.round((100 * r.com_falta) / r.cirurgias))}%)
                                                </td>
                                                {CAMPOS.map((c) => (
                                                    <td key={c.campo} className="py-1.5 pr-3 tabular-nums">
                                                        {r.totais[c.campo] > 0 ? r.totais[c.campo] : <span className="text-[var(--muted)]">—</span>}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section
                        className="rounded-lg border bg-[var(--surface)] p-4"
                        style={{ borderColor: 'var(--border)' }}
                        aria-label="Qualidade dos dados"
                    >
                        <h2 className="text-sm font-semibold text-[var(--ink)]">Qualidade dos dados</h2>
                        <p className="mb-3 text-xs text-[var(--ink2)]">
                            Doentes referenciados operados no período escolhido{selecionado ? ` (responsável: ${selecionado.nome})` : ''} com dados em
                            falta para os indicadores: {formatar(qualidade.linhas.length)} de {formatar(qualidade.cirurgias)} cirurgias. A morbilidade
                            e a deiscência dependem dos registos da alta e dos 30 dias; a mortalidade, do destino.
                        </p>
                        <p className="mb-3 flex flex-wrap gap-2 text-xs">
                            {PRIORIDADES.map((p) => (
                                <span key={p.valor} className={`rounded px-2 py-1 font-medium ${p.classe}`}>
                                    Prioridade {p.rotulo.toLowerCase()}: {formatar(qualidade.prioridades[p.valor])}
                                </span>
                            ))}
                        </p>
                        <p className="mb-3 text-xs text-[var(--ink2)]">
                            Alta: sem alta ou destino (a mortalidade não se calcula), ou complicação na alta sem seguimento aos 30 dias completo.
                            Média: seguimento em atraso ainda dentro dos 90 dias da alta. Baixa: os restantes. A lista vem ordenada por prioridade.
                        </p>
                        {qualidade.linhas.length === 0 ? (
                            <p className="py-6 text-center text-sm text-[var(--ink2)]">Nenhum dado em falta neste período.</p>
                        ) : (
                            <div className="max-h-[32rem] overflow-auto">
                                <table className="w-full text-left text-xs text-[var(--ink)]">
                                    <thead className="sticky top-0 bg-[var(--surface)]">
                                        <tr className="border-b text-[var(--ink2)]" style={{ borderColor: 'var(--grid)' }}>
                                            <th className="py-1.5 pr-3 font-medium">Prioridade</th>
                                            <th className="py-1.5 pr-3 font-medium">Doente</th>
                                            <th className="py-1.5 pr-3 font-medium">Cirurgia</th>
                                            <th className="py-1.5 pr-3 font-medium">Intervenção</th>
                                            <th className="py-1.5 pr-3 font-medium">Responsável</th>
                                            {CAMPOS.map((c) => (
                                                <th key={c.campo} className="py-1.5 pr-3 font-medium" title={c.ajuda}>
                                                    {c.rotulo}
                                                    <span className="block font-normal tabular-nums">
                                                        {formatar(qualidade.totais[c.campo])} em falta
                                                    </span>
                                                </th>
                                            ))}
                                            <th className="py-1.5" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {qualidade.linhas.map((l) => (
                                            <tr key={l.bloco} className="border-b" style={{ borderColor: 'var(--grid)' }}>
                                                <td className="py-1.5 pr-3" title={l.motivo}>
                                                    <span
                                                        className={`rounded px-1.5 py-0.5 font-medium ${PRIORIDADES.find((p) => p.valor === l.prioridade)?.classe}`}
                                                    >
                                                        {PRIORIDADES.find((p) => p.valor === l.prioridade)?.rotulo}
                                                    </span>
                                                    <p className="mt-0.5 max-w-44 text-[var(--ink2)]">{l.motivo}</p>
                                                </td>
                                                <td className="py-1.5 pr-3">
                                                    <Link href={rotas.utentes.ficha(l.utente.id)} className="font-medium hover:underline">
                                                        {l.utente.nome}
                                                    </Link>
                                                    <p className="text-[var(--ink2)] tabular-nums">
                                                        <NumeroProcesso valor={l.utente.numero_processo} />
                                                    </p>
                                                </td>
                                                <td className="py-1.5 pr-3 whitespace-nowrap tabular-nums">{formatarData(l.data)}</td>
                                                <td className="py-1.5 pr-3">{l.intervencao}</td>
                                                <td className="py-1.5 pr-3">{l.responsavel.nome}</td>
                                                {CAMPOS.map((c) => (
                                                    <td key={c.campo} className="py-1.5 pr-3">
                                                        {l.falta.includes(c.campo) ? (
                                                            <span className="rounded bg-rose-100 px-1.5 py-0.5 font-medium text-rose-900">Falta</span>
                                                        ) : (
                                                            <span className="text-[var(--muted)]" aria-label="Preenchido">
                                                                ✓
                                                            </span>
                                                        )}
                                                    </td>
                                                ))}
                                                <td className="py-1.5 whitespace-nowrap">
                                                    <Link
                                                        href={`/analise/cancro-retal/qualidade/internamentos/${l.internamento}${consultaAtual}`}
                                                        preserveScroll
                                                        className="underline underline-offset-2"
                                                    >
                                                        Abrir
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                </div>
            </div>
            <ModalInternamento modal={modal} />
        </ClinicoLayout>
    );
}

QualidadeDados.layout = (pagina: React.ReactNode) => pagina;
