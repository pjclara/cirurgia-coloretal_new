import { useState } from 'react';
import { FiltroTrimestres } from '../../components/analise/filtro-trimestres';
import { Cartao, ESTILO_VIZ, Indicador, formatar } from '../../components/analise/graficos';
import ClinicoLayout from '../../layouts/clinico-layout';

type Trimestre = { trimestre: string; n: number; eventos: number; taxa: number | null; cumpre: boolean | null; incompleto: boolean };
type Serie = {
    n: number;
    eventos: number;
    taxa: number | null;
    cumpre: boolean | null;
    melhor: Trimestre | null;
    pior: Trimestre | null;
    serie: Trimestre[];
};
type Chave = 'conservacao' | 'deiscencia' | 'mortalidade' | 'morbilidade';

type Props = {
    trimestres: string[];
    de: string | null;
    ate: string | null;
    geradoEm: string;
    total: number;
    totalConservacao: number;
    indicadores: Record<Chave, Serie>;
};

const OK = '#2a8f57';
const FORA = '#c0392b';

const INDICADORES: {
    chave: Chave;
    titulo: string;
    curto: string;
    cor: string;
    meta: number;
    sentido: 'min' | 'max';
    textoMeta: string;
    descricao: string;
    evento: string;
}[] = [
    {
        chave: 'conservacao',
        titulo: 'Taxa de cirurgia conservadora de esfíncter',
        curto: 'Conservação de esfíncter',
        cor: '#3266ad',
        meta: 75,
        sentido: 'min',
        textoMeta: '> 75%',
        descricao: 'Ressecção anterior do reto sem amputação abdomino-perineal, sobre as cirurgias retais sem a ressecção reto-sigmoideia.',
        evento: 'conservações',
    },
    {
        chave: 'deiscencia',
        titulo: 'Taxa de deiscência anastomótica',
        curto: 'Deiscência anastomótica',
        cor: '#e2763d',
        meta: 15,
        sentido: 'max',
        textoMeta: '< 15%',
        descricao: 'Deiscência da anastomose registada na alta ou aos 30 dias, sobre todas as cirurgias retais.',
        evento: 'deiscências',
    },
    {
        chave: 'mortalidade',
        titulo: 'Taxa de mortalidade operatória',
        curto: 'Mortalidade operatória',
        cor: '#2a8f57',
        meta: 3,
        sentido: 'max',
        textoMeta: '< 3%',
        descricao: 'Internamentos da cirurgia com destino «falecido», sobre todas as cirurgias retais.',
        evento: 'óbitos',
    },
    {
        chave: 'morbilidade',
        titulo: 'Taxa de morbilidade',
        curto: 'Morbilidade global',
        cor: '#9b59b6',
        meta: 20,
        sentido: 'max',
        textoMeta: '< 20%',
        descricao: 'Complicações de grau 3 ou superior de Clavien-Dindo (grau definitivo, aos 30 dias), sobre todas as cirurgias retais.',
        evento: 'complicações graves',
    },
];

const pc = (v: number | null) => (v === null ? '—' : `${formatar(v)}%`);
const corEstado = (cumpre: boolean | null) => (cumpre === null ? 'var(--muted)' : cumpre ? OK : FORA);
const rotuloTrimestre = (t: string) => t.replace(' ', ' ');

/** Barras (nº de cirurgias), linha (taxa) e meta tracejada, por trimestre. */
function GraficoTrimestral({
    serie,
    cor,
    meta,
    sentido,
    evento,
}: {
    serie: Trimestre[];
    cor: string;
    meta: number;
    sentido: 'min' | 'max';
    evento: string;
}) {
    const [ativo, setAtivo] = useState<number | null>(null);
    const L = 820;
    const H = 280;
    const M = { e: 44, d: 40, t: 16, b: 52 };
    const w = L - M.e - M.d;
    const h = H - M.t - M.b;
    const maxTaxa = Math.max(...serie.map((s) => s.taxa ?? 0), meta);
    const topo = Math.min(100, Math.max(10, Math.ceil((maxTaxa * 1.15) / 5) * 5));
    const maxN = Math.max(...serie.map((s) => s.n), 1);
    const banda = w / serie.length;
    const x = (i: number) => M.e + banda * (i + 0.5);
    const y = (v: number) => M.t + h - (v / topo) * h;
    const yn = (n: number) => M.t + h - (n / (maxN * 2.6)) * h; // barras ocupam só a parte baixa
    const passo = Math.max(1, Math.ceil(serie.length / 14));
    const traco = [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(topo * f));
    const comTaxa = serie.map((s, i) => ({ s, i })).filter((p) => p.s.taxa !== null);
    const linha = comTaxa.map((p, k) => `${k === 0 ? 'M' : 'L'}${x(p.i)},${y(p.s.taxa as number)}`).join(' ');
    const sel = ativo !== null ? serie[ativo] : null;

    return (
        <div className="relative" onPointerLeave={() => setAtivo(null)}>
            {sel && (
                <div
                    role="status"
                    className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-md border bg-[var(--surface)] px-3 py-2 shadow-sm"
                    style={{ left: `${Math.min(82, Math.max(18, (x(ativo as number) / L) * 100))}%`, borderColor: 'var(--border)' }}
                >
                    <p className="text-xs text-[var(--ink2)]">
                        {sel.trimestre}
                        {sel.incompleto ? ' (em curso)' : ''}
                    </p>
                    <p className="text-lg leading-tight font-semibold" style={{ color: corEstado(sel.cumpre) }}>
                        {pc(sel.taxa)}
                    </p>
                    <p className="text-xs text-[var(--ink)]">{sel.n === 0 ? 'Sem cirurgias' : `${sel.eventos} ${evento} em ${sel.n} cirurgias`}</p>
                    <p className="text-xs text-[var(--ink2)]">
                        Meta: {sentido === 'min' ? '>' : '<'} {meta}%{sel.cumpre !== null && ` · ${sel.cumpre ? 'cumpre' : 'fora da meta'}`}
                    </p>
                </div>
            )}
            <svg viewBox={`0 0 ${L} ${H}`} className="w-full" role="img" aria-label="Taxa por trimestre, número de cirurgias e meta">
                {traco.map((v) => (
                    <g key={v}>
                        <line x1={M.e} x2={L - M.d} y1={y(v)} y2={y(v)} stroke={v === 0 ? 'var(--axis)' : 'var(--grid)'} strokeWidth={1} />
                        <text x={M.e - 6} y={y(v) + 4} textAnchor="end" fontSize={11} fill="var(--muted)">
                            {v}%
                        </text>
                    </g>
                ))}
                {serie.map((s, i) => {
                    const bw = Math.min(26, banda * 0.6);
                    return (
                        <g key={s.trimestre}>
                            {s.n > 0 && (
                                <rect
                                    x={x(i) - bw / 2}
                                    y={yn(s.n)}
                                    width={bw}
                                    height={yn(0) - yn(s.n)}
                                    rx={2}
                                    fill={cor}
                                    opacity={ativo === i ? 0.3 : 0.15}
                                />
                            )}
                            {i % passo === 0 && (
                                <text
                                    x={x(i)}
                                    y={H - M.b + 14}
                                    textAnchor="end"
                                    fontSize={11}
                                    fill="var(--muted)"
                                    transform={`rotate(-40 ${x(i)} ${H - M.b + 14})`}
                                >
                                    {s.trimestre}
                                </text>
                            )}
                        </g>
                    );
                })}
                <line x1={M.e} x2={L - M.d} y1={y(meta)} y2={y(meta)} stroke="#d99a1e" strokeWidth={2} strokeDasharray="7 4" />
                <text x={L - M.d + 4} y={y(meta) + 4} fontSize={11} fontWeight={600} fill="#b07a0f">
                    {sentido === 'min' ? '>' : '<'} {meta}%
                </text>
                {ativo !== null && <line x1={x(ativo)} x2={x(ativo)} y1={M.t} y2={y(0)} stroke="var(--axis)" strokeWidth={1} />}
                {linha && <path d={linha} fill="none" stroke={cor} strokeWidth={2.2} strokeLinejoin="round" strokeLinecap="round" />}
                {comTaxa.map((p) => (
                    <circle
                        key={p.s.trimestre}
                        cx={x(p.i)}
                        cy={y(p.s.taxa as number)}
                        r={ativo === p.i ? 6 : 4.5}
                        fill={corEstado(p.s.cumpre)}
                        stroke="var(--surface)"
                        strokeWidth={2}
                        opacity={p.s.incompleto ? 0.5 : 1}
                    />
                ))}
                {serie.map((s, i) => (
                    <rect
                        key={s.trimestre}
                        x={x(i) - banda / 2}
                        y={M.t}
                        width={banda}
                        height={h}
                        fill="transparent"
                        tabIndex={0}
                        aria-label={`${s.trimestre}: ${pc(s.taxa)}, ${s.eventos} em ${s.n}`}
                        onPointerEnter={() => setAtivo(i)}
                        onFocus={() => setAtivo(i)}
                        onBlur={() => setAtivo(null)}
                        style={{ outline: 'none' }}
                    />
                ))}
            </svg>
        </div>
    );
}

export default function CancroRetal({ trimestres, de, ate, geradoEm, total, totalConservacao, indicadores }: Props) {
    const [aCarregar, setACarregar] = useState(false);
    return (
        <ClinicoLayout titulo="Análise" subtitulo={`Cancro retal · doentes do CR · gerada a ${geradoEm}`} largura="larga" pesquisa={null}>
            <style>{ESTILO_VIZ}</style>
            <div className="viz-root space-y-5">
                <FiltroTrimestres url="/analise/cancro-retal" trimestres={trimestres} de={de} ate={ate} aoMudar={setACarregar} />
                <p className="text-xs text-slate-600 dark:text-slate-400">
                    Doentes referenciados ao centro de referência até à data da cirurgia. A taxa de conservação de esfíncter exclui a ressecção
                    reto-sigmoideia ({formatar(totalConservacao)} cirurgias); os restantes indicadores incluem-na ({formatar(total)} cirurgias). O
                    trimestre em curso está incompleto.
                </p>

                <div className={`space-y-5 transition-opacity ${aCarregar ? 'opacity-50' : ''}`}>
                    <section aria-label="Metas definidas">
                        <h2 className="mb-2 text-xs font-semibold tracking-wide text-[var(--ink2)] uppercase">Metas definidas</h2>
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            {INDICADORES.map((d) => (
                                <Indicador
                                    key={d.chave}
                                    rotulo={d.curto}
                                    valor={d.textoMeta}
                                    nota={
                                        <span style={{ color: corEstado(indicadores[d.chave].cumpre) }}>
                                            Atual: {pc(indicadores[d.chave].taxa)}
                                            {indicadores[d.chave].cumpre !== null && (indicadores[d.chave].cumpre ? ' · cumpre' : ' · fora da meta')}
                                        </span>
                                    }
                                />
                            ))}
                        </div>
                    </section>

                    {INDICADORES.map((d) => {
                        const ind = indicadores[d.chave];
                        const foraDaMeta = ind.serie.filter((t) => t.cumpre === false && !t.incompleto);
                        const comCirurgias = ind.serie.filter((t) => t.n > 0 && !t.incompleto);
                        const aZero = comCirurgias.filter((t) => t.taxa === 0).length;
                        return (
                            <Cartao
                                key={d.chave}
                                titulo={d.titulo}
                                nota={d.descricao}
                                colunas={['Trimestre', 'Cirurgias', 'Eventos', 'Taxa', 'Meta']}
                                linhas={ind.serie.map((t) => [
                                    t.trimestre + (t.incompleto ? ' (em curso)' : ''),
                                    t.n,
                                    t.eventos,
                                    pc(t.taxa),
                                    t.cumpre === null ? '—' : t.cumpre ? 'Cumpre' : 'Fora da meta',
                                ])}
                            >
                                <div className="mb-3 grid grid-cols-2 gap-2.5 md:grid-cols-4">
                                    <Indicador
                                        rotulo="Taxa acumulada"
                                        valor={pc(ind.taxa)}
                                        nota={`${ind.eventos} ${d.evento} em ${ind.n} cirurgias`}
                                    />
                                    <Indicador
                                        rotulo={d.sentido === 'min' ? 'Pior trimestre' : 'Pior trimestre'}
                                        valor={pc(ind.pior?.taxa ?? null)}
                                        nota={ind.pior ? `${ind.pior.trimestre} — ${ind.pior.eventos} em ${ind.pior.n}` : undefined}
                                    />
                                    <Indicador
                                        rotulo="Trimestres fora da meta"
                                        valor={`${foraDaMeta.length} / ${comCirurgias.length}`}
                                        nota={foraDaMeta.length ? foraDaMeta.map((t) => rotuloTrimestre(t.trimestre)).join(' · ') : 'Nenhum'}
                                    />
                                    <Indicador
                                        rotulo={d.sentido === 'min' ? 'Melhor trimestre' : 'Trimestres a 0%'}
                                        valor={d.sentido === 'min' ? pc(ind.melhor?.taxa ?? null) : `${aZero} / ${comCirurgias.length}`}
                                        nota={
                                            d.sentido === 'min' && ind.melhor
                                                ? `${ind.melhor.trimestre} — ${ind.melhor.eventos} em ${ind.melhor.n}`
                                                : undefined
                                        }
                                    />
                                </div>
                                <div className="mb-2 flex flex-wrap gap-4 text-xs text-[var(--ink2)]">
                                    <span className="inline-flex items-center gap-1.5">
                                        <span className="inline-block h-3 w-3.5 rounded-sm" style={{ background: d.cor, opacity: 0.2 }} /> Nº de
                                        cirurgias
                                    </span>
                                    <span className="inline-flex items-center gap-1.5">
                                        <span className="inline-block h-0.5 w-5" style={{ background: d.cor }} /> Taxa (%)
                                    </span>
                                    <span className="inline-flex items-center gap-1.5">
                                        <span className="inline-block w-5 border-t-2 border-dashed" style={{ borderColor: '#d99a1e' }} /> Meta (
                                        {d.textoMeta})
                                    </span>
                                    <span className="inline-flex items-center gap-1.5">
                                        <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ background: OK }} /> cumpre
                                        <span className="ml-2 inline-block h-2.5 w-2.5 rounded-full" style={{ background: FORA }} /> fora da meta
                                    </span>
                                </div>
                                <GraficoTrimestral serie={ind.serie} cor={d.cor} meta={d.meta} sentido={d.sentido} evento={d.evento} />
                            </Cartao>
                        );
                    })}
                </div>
            </div>
        </ClinicoLayout>
    );
}

CancroRetal.layout = (pagina: React.ReactNode) => pagina;
