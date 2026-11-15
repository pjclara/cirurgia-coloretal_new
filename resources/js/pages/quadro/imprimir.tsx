import { Head, Link, router } from '@inertiajs/react';
import { diasEntre, formatarData, idade, plural } from '../../clinico/formato';

type Internado = {
    id: number;
    cama: string;
    localizacao: string;
    data_de_entrada: string;
    motivo: string;
    caso_social: string | null;
    centro_referencia: boolean;
    utente: { nome: string | null; data_nascimento: string | null; sexo: string | null; numero_processo: number | null };
    responsavel: { nome: string; abrev: string | null; equipa: string | null } | null;
    diagnosticos: string[];
    cirurgias: { data: string; tipo: string | null; intervencoes: string[] }[];
};

type Props = { internados: Internado[]; equipa: 'A' | 'B' | null; hoje: string; geradoEm: string };

/** Última cirurgia já realizada, com os dias de pós-operatório (D+n). */
function posOperatorio(internado: Internado, hoje: string) {
    const feitas = internado.cirurgias.filter((c) => c.data.slice(0, 10) <= hoje);
    const ultima = feitas[feitas.length - 1];
    if (!ultima) return null;
    return { dias: diasEntre(ultima.data.slice(0, 10), hoje), texto: ultima.intervencoes.join(', ') || ultima.tipo || 'Cirurgia' };
}

/** Lista de doentes internados para imprimir: passar a visita e escrever as notas à cabeceira. */
export default function ImprimirQuadro({ internados, equipa, hoje, geradoEm }: Props) {
    const porLocalizacao = new Map<string, Internado[]>();
    for (const i of internados) porLocalizacao.set(i.localizacao, [...(porLocalizacao.get(i.localizacao) ?? []), i]);

    const ligacao = 'rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 hover:bg-slate-50';
    const titulo = `Lista de visita${equipa ? ` · equipa ${equipa}` : ''} · ${formatarData(hoje)}`;

    return (
        <div className="mx-auto max-w-[96rem] bg-white p-6 font-sans text-slate-900 print:max-w-none print:p-0">
            <Head title={titulo} />
            <style>{`@media print { @page { size: A4 landscape; margin: 10mm; } body { background: white !important; } thead { display: table-header-group; } tr { break-inside: avoid; } }`}</style>

            <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
                <button
                    type="button"
                    onClick={() => {
                        // Fecha o separador; se o browser não deixar (página aberta diretamente), volta à aplicação.
                        window.close();
                        setTimeout(() => router.visit('/quadro'), 200);
                    }}
                    className={ligacao}
                >
                    Fechar
                </button>
                {([null, 'A', 'B'] as const).map((e) => (
                    <Link
                        key={e ?? 'todas'}
                        href={e ? `/quadro/imprimir?equipa=${e}` : '/quadro/imprimir'}
                        className={`${ligacao} ${equipa === e ? 'border-slate-900 ring-1 ring-slate-900' : ''}`}
                    >
                        {e ? `Equipa ${e}` : 'Todas as equipas'}
                    </Link>
                ))}
                <button
                    type="button"
                    onClick={() => window.print()}
                    className="ml-auto rounded-md bg-[#a3213e] px-4 py-1.5 text-sm font-semibold text-white hover:bg-[#8a1b34]"
                >
                    Imprimir
                </button>
            </div>

            <header className="mb-3 border-b-2 border-slate-900 pb-2">
                <p className="text-xs tracking-wide text-slate-600 uppercase">Unidade Funcional de Cirurgia Colorectal · ULS Região de Leiria</p>
                <h1 className="text-xl font-semibold">{titulo}</h1>
                <p className="text-sm text-slate-600">{plural(internados.length, 'doente internado', 'doentes internados')}</p>
            </header>

            {internados.length === 0 && <p className="py-6 text-sm text-slate-600">Não há doentes internados.</p>}

            <div className="space-y-5">
                {[...porLocalizacao.entries()].map(([localizacao, lista]) => (
                    <section key={localizacao}>
                        <h2 className="mb-1 border-b border-slate-400 text-sm font-semibold">
                            {localizacao} <span className="font-normal text-slate-600">({plural(lista.length, 'cama', 'camas')})</span>
                        </h2>
                        <table className="w-full border-collapse text-left text-xs">
                            <thead>
                                <tr className="border-b border-slate-400 text-slate-600">
                                    <th className="w-12 py-1 pr-2 font-medium">Cama</th>
                                    <th className="w-56 py-1 pr-2 font-medium">Utente</th>
                                    <th className="w-16 py-1 pr-2 font-medium">Dia</th>
                                    <th className="w-64 py-1 pr-2 font-medium">Diagnóstico / cirurgia</th>
                                    <th className="w-12 py-1 pr-2 font-medium">Resp.</th>
                                    <th className="py-1 pr-2 font-medium">Evolução / plano</th>
                                    <th className="w-14 py-1 text-center font-medium">Alta hoje</th>
                                </tr>
                            </thead>
                            <tbody>
                                {lista.map((i) => {
                                    const anos = idade(i.utente.data_nascimento, hoje);
                                    const pop = posOperatorio(i, hoje);
                                    return (
                                        <tr key={i.id} className="h-16 border-b border-slate-300 align-top">
                                            <td className="py-1.5 pr-2 text-base font-semibold tabular-nums">{i.cama}</td>
                                            <td className="py-1.5 pr-2">
                                                <p className="font-semibold">{i.utente.nome ?? 'Utente sem ficha'}</p>
                                                <p className="text-slate-600 tabular-nums">
                                                    {anos !== null ? `${anos} anos` : ''}
                                                    {i.utente.sexo ? `, ${i.utente.sexo.toLowerCase()}` : ''} · Proc.{' '}
                                                    {i.utente.numero_processo ?? '—'}
                                                </p>
                                            </td>
                                            <td className="py-1.5 pr-2 tabular-nums">
                                                <p className="font-semibold">D{Math.max(0, diasEntre(i.data_de_entrada, hoje))}</p>
                                                <p className="text-slate-600">{formatarData(i.data_de_entrada).slice(0, 5)}</p>
                                            </td>
                                            <td className="py-1.5 pr-2">
                                                <p>{i.diagnosticos.length > 0 ? i.diagnosticos.join(', ') : i.motivo}</p>
                                                {pop && (
                                                    <p className="text-slate-700">
                                                        <span className="font-semibold tabular-nums">D+{pop.dias}</span> {pop.texto}
                                                    </p>
                                                )}
                                                {i.caso_social && <p className="font-medium">Caso social: {i.caso_social}</p>}
                                                {i.centro_referencia && <p className="font-medium">Centro de referência</p>}
                                            </td>
                                            <td className="py-1.5 pr-2">{i.responsavel?.abrev ?? '—'}</td>
                                            <td className="border-l border-dashed border-slate-300 py-1.5 pr-2" />
                                            <td className="py-1.5 text-center">
                                                <span className="inline-block size-4 border border-slate-700" aria-label="Alta hoje" />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </section>
                ))}
            </div>

            <p className="mt-4 text-xs text-slate-500">Gerado a {geradoEm}</p>
        </div>
    );
}

ImprimirQuadro.layout = (pagina: React.ReactNode) => pagina;
