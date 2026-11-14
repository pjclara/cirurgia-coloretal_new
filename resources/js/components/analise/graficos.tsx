import { useState, type ReactNode } from 'react';

export type Ponto = { rotulo: string; valor: number | null };

const numero = new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 1 });
export const formatar = (v: number, sufixo = '') => `${numero.format(v)}${sufixo}`;

/** Limite superior "redondo" do eixo e os valores dos traços de grelha. */
function escala(maximo: number, tracos = 4) {
    const bruto = Math.max(maximo, 1) / tracos;
    const potencia = 10 ** Math.floor(Math.log10(bruto));
    const passo = [1, 2, 2.5, 5, 10].map((m) => m * potencia).find((p) => p >= bruto) ?? bruto;
    return { passo, valores: Array.from({ length: tracos + 1 }, (_, i) => i * passo), topo: passo * tracos };
}

const L0 = 640; // largura do viewBox por omissão
const M = { e: 40, d: 12, t: 14, b: 24 };

/** Contentor de um gráfico: título, nota e alternância gráfico / tabela. */
export function Cartao({
    titulo,
    nota,
    colunas,
    linhas,
    children,
}: {
    titulo: string;
    nota?: string;
    colunas: string[];
    linhas: (string | number)[][];
    children: ReactNode;
}) {
    const [tabela, setTabela] = useState(false);
    return (
        <section className="flex flex-col rounded-lg border bg-[var(--surface)] p-4" style={{ borderColor: 'var(--border)' }} aria-label={titulo}>
            <header className="mb-3 flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold text-[var(--ink)]">{titulo}</h2>
                    {nota && <p className="text-xs text-[var(--ink2)]">{nota}</p>}
                </div>
                <button
                    type="button"
                    onClick={() => setTabela(!tabela)}
                    className="shrink-0 rounded px-2 py-1 text-xs text-[var(--ink2)] hover:bg-black/5 dark:hover:bg-white/10"
                >
                    {tabela ? 'Ver gráfico' : 'Ver tabela'}
                </button>
            </header>
            {tabela ? (
                <div className="max-h-64 overflow-auto">
                    <table className="w-full text-left text-xs text-[var(--ink)]">
                        <thead>
                            <tr className="border-b text-[var(--ink2)]" style={{ borderColor: 'var(--grid)' }}>
                                {colunas.map((c) => (
                                    <th key={c} className="py-1 pr-3 font-medium">
                                        {c}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {linhas.map((l, i) => (
                                <tr key={i} className="border-b" style={{ borderColor: 'var(--grid)' }}>
                                    {l.map((c, j) => (
                                        <td key={j} className="py-1 pr-3 tabular-nums">
                                            {c}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                children
            )}
        </section>
    );
}

function Dica({ esquerda, rotulo, valor }: { esquerda: number; rotulo: string; valor: string }) {
    return (
        <div
            role="status"
            className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-md border bg-[var(--surface)] px-2.5 py-1.5 shadow-sm"
            style={{ left: `${Math.min(88, Math.max(12, esquerda))}%`, borderColor: 'var(--border)' }}
        >
            <p className="text-sm leading-tight font-semibold text-[var(--ink)]">{valor}</p>
            <p className="text-xs text-[var(--ink2)]">{rotulo}</p>
        </div>
    );
}

/** Colunas de uma série: uma cor, eixo a zero, valor só no máximo e no último. */
export function Colunas({
    dados,
    sufixo = '',
    altura = 190,
    largura = L0,
    etiqueta,
    passoRotulos,
}: {
    dados: Ponto[];
    sufixo?: string;
    altura?: number;
    largura?: number;
    etiqueta?: (p: Ponto) => string;
    passoRotulos?: number;
}) {
    const L = largura;
    const [ativo, setAtivo] = useState<number | null>(null);
    const valores = dados.map((d) => d.valor ?? 0);
    const { valores: tracos, topo } = escala(Math.max(...valores, 1));
    const area = { w: L - M.e - M.d, h: altura - M.t - M.b };
    const banda = area.w / dados.length;
    const espessura = Math.min(24, banda * 0.7);
    const y = (v: number) => M.t + area.h - (v / topo) * area.h;
    const passo = passoRotulos ?? Math.ceil(dados.length / 8);
    const maximo = valores.indexOf(Math.max(...valores));
    const rotuloEixo = (d: Ponto) => (etiqueta ? etiqueta(d) : d.rotulo);

    return (
        <div className="relative" onPointerLeave={() => setAtivo(null)}>
            {ativo !== null && (
                <Dica
                    esquerda={((M.e + banda * (ativo + 0.5)) / L) * 100}
                    rotulo={rotuloEixo(dados[ativo])}
                    valor={formatar(valores[ativo], sufixo)}
                />
            )}
            <svg viewBox={`0 0 ${L} ${altura}`} className="w-full" role="img" aria-label="Gráfico de colunas">
                {tracos.map((v) => (
                    <g key={v}>
                        <line x1={M.e} x2={L - M.d} y1={y(v)} y2={y(v)} stroke={v === 0 ? 'var(--axis)' : 'var(--grid)'} strokeWidth={1} />
                        <text x={M.e - 6} y={y(v) + 4} textAnchor="end" fontSize={11} fill="var(--muted)">
                            {formatar(v)}
                        </text>
                    </g>
                ))}
                {dados.map((d, i) => {
                    const cx = M.e + banda * (i + 0.5);
                    const x = cx - espessura / 2;
                    const topoBarra = y(valores[i]);
                    const h = Math.max(0, y(0) - topoBarra);
                    const r = Math.min(4, h, espessura / 2);
                    const caminho =
                        h === 0
                            ? ''
                            : `M${x},${y(0)} V${topoBarra + r} Q${x},${topoBarra} ${x + r},${topoBarra} H${x + espessura - r} Q${x + espessura},${topoBarra} ${x + espessura},${topoBarra + r} V${y(0)} Z`;
                    return (
                        <g key={d.rotulo}>
                            <path d={caminho} fill={ativo === i ? 'var(--serie-hover)' : 'var(--serie)'} />
                            {(i === maximo || i === dados.length - 1) && h > 0 && (
                                <text x={cx} y={topoBarra - 5} textAnchor="middle" fontSize={11} fontWeight={600} fill="var(--ink)">
                                    {formatar(valores[i], sufixo)}
                                </text>
                            )}
                            {i % passo === 0 && (
                                <text x={cx} y={altura - 6} textAnchor="middle" fontSize={11} fill="var(--muted)">
                                    {rotuloEixo(d)}
                                </text>
                            )}
                            <rect
                                x={cx - banda / 2}
                                y={M.t}
                                width={banda}
                                height={area.h}
                                fill="transparent"
                                tabIndex={0}
                                aria-label={`${rotuloEixo(d)}: ${formatar(valores[i], sufixo)}`}
                                onPointerEnter={() => setAtivo(i)}
                                onFocus={() => setAtivo(i)}
                                onBlur={() => setAtivo(null)}
                                style={{ outline: 'none' }}
                            />
                        </g>
                    );
                })}
            </svg>
        </div>
    );
}

/** Linha de uma série ao longo do tempo, com cursor que acompanha o ponteiro. */
export function Linha({ dados, sufixo = '', altura = 190, largura = L0 }: { dados: Ponto[]; sufixo?: string; altura?: number; largura?: number }) {
    const L = largura;
    const [ativo, setAtivo] = useState<number | null>(null);
    const validos = dados.map((d, i) => ({ ...d, i })).filter((d): d is Ponto & { i: number; valor: number } => d.valor !== null);
    const { valores: tracos, topo } = escala(Math.max(...validos.map((d) => d.valor), 1));
    const area = { w: L - M.e - M.d - 16, h: altura - M.t - M.b };
    const x = (i: number) => M.e + 8 + (dados.length === 1 ? area.w / 2 : (i / (dados.length - 1)) * area.w);
    const y = (v: number) => M.t + area.h - (v / topo) * area.h;
    const caminho = validos.map((d, k) => `${k === 0 ? 'M' : 'L'}${x(d.i)},${y(d.valor)}`).join(' ');
    const ultimo = validos[validos.length - 1];
    const seleccionado = ativo !== null ? validos[ativo] : null;

    const mover = (e: React.PointerEvent<SVGSVGElement>) => {
        const caixa = e.currentTarget.getBoundingClientRect();
        const px = ((e.clientX - caixa.left) / caixa.width) * L;
        let melhor = 0;
        validos.forEach((d, k) => {
            if (Math.abs(x(d.i) - px) < Math.abs(x(validos[melhor].i) - px)) melhor = k;
        });
        setAtivo(melhor);
    };

    if (validos.length === 0) return <p className="py-8 text-center text-sm text-[var(--ink2)]">Sem dados.</p>;

    return (
        <div className="relative" onPointerLeave={() => setAtivo(null)}>
            {seleccionado && (
                <Dica esquerda={(x(seleccionado.i) / L) * 100} rotulo={seleccionado.rotulo} valor={formatar(seleccionado.valor, sufixo)} />
            )}
            <svg
                viewBox={`0 0 ${L} ${altura}`}
                className="w-full"
                role="img"
                aria-label="Gráfico de linha"
                onPointerMove={mover}
                tabIndex={0}
                onFocus={() => setAtivo(validos.length - 1)}
                onBlur={() => setAtivo(null)}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowLeft') setAtivo((a) => Math.max(0, (a ?? validos.length) - 1));
                    if (e.key === 'ArrowRight') setAtivo((a) => Math.min(validos.length - 1, (a ?? -1) + 1));
                }}
                style={{ outline: 'none' }}
            >
                {tracos.map((v) => (
                    <g key={v}>
                        <line x1={M.e} x2={L - M.d} y1={y(v)} y2={y(v)} stroke={v === 0 ? 'var(--axis)' : 'var(--grid)'} strokeWidth={1} />
                        <text x={M.e - 6} y={y(v) + 4} textAnchor="end" fontSize={11} fill="var(--muted)">
                            {formatar(v)}
                        </text>
                    </g>
                ))}
                {dados.map((d, i) => (
                    <text key={d.rotulo} x={x(i)} y={altura - 6} textAnchor="middle" fontSize={11} fill="var(--muted)">
                        {d.rotulo}
                    </text>
                ))}
                <path d={`${caminho} L${x(ultimo.i)},${y(0)} L${x(validos[0].i)},${y(0)} Z`} fill="var(--serie)" opacity={0.1} />
                <path d={caminho} fill="none" stroke="var(--serie)" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
                {seleccionado && <line x1={x(seleccionado.i)} x2={x(seleccionado.i)} y1={M.t} y2={y(0)} stroke="var(--axis)" strokeWidth={1} />}
                {[...validos].map((d) => (
                    <circle
                        key={d.i}
                        cx={x(d.i)}
                        cy={y(d.valor)}
                        r={d === seleccionado ? 5 : 4}
                        fill="var(--serie)"
                        stroke="var(--surface)"
                        strokeWidth={2}
                    />
                ))}
                <text x={x(ultimo.i)} y={y(ultimo.valor) - 10} textAnchor="end" fontSize={11} fontWeight={600} fill="var(--ink)">
                    {formatar(ultimo.valor, sufixo)}
                </text>
            </svg>
        </div>
    );
}

/** Barras horizontais ordenadas, o valor na ponta. */
export function BarrasH({ dados }: { dados: Ponto[] }) {
    const maximo = Math.max(...dados.map((d) => d.valor ?? 0), 1);
    if (dados.length === 0) return <p className="py-8 text-center text-sm text-[var(--ink2)]">Sem dados.</p>;
    return (
        <ul className="space-y-1.5">
            {dados.map((d) => (
                <li
                    key={d.rotulo}
                    className="group grid grid-cols-[minmax(0,12rem)_1fr] items-center gap-3 text-xs"
                    title={`${d.rotulo}: ${formatar(d.valor ?? 0)}`}
                >
                    <span className="truncate text-[var(--ink2)]">{d.rotulo}</span>
                    <div className="flex items-center gap-2">
                        <div
                            className="h-4 rounded-r-[4px] bg-[var(--serie)] group-hover:bg-[var(--serie-hover)]"
                            style={{ width: `${Math.max(1, ((d.valor ?? 0) / maximo) * 82)}%` }}
                        />
                        <span className="font-semibold text-[var(--ink)] tabular-nums">{formatar(d.valor ?? 0)}</span>
                    </div>
                </li>
            ))}
        </ul>
    );
}

/** Indicador de topo: valor grande, legenda e uma nota (variação ou contexto). */
export function Indicador({ rotulo, valor, nota, destaque }: { rotulo: string; valor: string; nota?: ReactNode; destaque?: boolean }) {
    return (
        <div className="rounded-lg border bg-[var(--surface)] px-4 py-3" style={{ borderColor: 'var(--border)' }}>
            <p className="text-xs text-[var(--ink2)]">{rotulo}</p>
            <p className={`mt-0.5 leading-none font-semibold text-[var(--ink)] ${destaque ? 'text-5xl' : 'text-3xl'}`}>{valor}</p>
            {nota && <p className="mt-1.5 text-xs text-[var(--ink2)]">{nota}</p>}
        </div>
    );
}
