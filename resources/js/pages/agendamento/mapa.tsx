import { Head, Link, router } from '@inertiajs/react';
import { NOMES_DIA, nomeEstado } from '../../clinico/agendamento';
import { formatarData } from '../../clinico/formato';
import type { Opcao } from '../../clinico/tipos';
import { EtiquetaCr } from '../../components/clinico/etiqueta-cr';
import { NumeroProcesso } from '../../components/clinico/numero-processo';

type Item = {
    id: number;
    data: string;
    utente: { id: number | null; nome: string | null; numero_processo: number | null; cr: boolean };
    idade: number | null;
    intervencoes: string[];
    hora: string | null;
    periodo: string | null;
    local: string | null;
    sala: string | null;
    tipo: string | null;
    estado: number | null;
    responsavel: string | null;
    reserva_sangue: boolean;
    comentarios: string | null;
};

type Props = {
    dias: { data: string; util: boolean; agendamentos: Item[] }[];
    vista: 'dia' | 'semana';
    inicio: string;
    anterior: string;
    seguinte: string;
    estados: Opcao[];
    geradoEm: string;
};

/** Mapa operatório sem menus nem cores de fundo, pensado para imprimir (ou guardar em PDF pelo diálogo de impressão). */
export default function Mapa({ dias, vista, inicio, anterior, seguinte, estados, geradoEm }: Props) {
    const total = dias.reduce((soma, d) => soma + d.agendamentos.length, 0);
    const ligacao = 'rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 hover:bg-slate-50';
    const titulo = vista === 'dia' ? `Mapa operatório · ${formatarData(inicio)}` : `Mapa operatório · semana de ${formatarData(inicio)}`;

    return (
        <div className="mx-auto max-w-6xl bg-white p-6 font-sans text-slate-900 print:max-w-none print:p-0">
            <Head title={titulo} />
            <style>{`@media print { @page { size: A4 landscape; margin: 12mm; } body { background: white !important; } }`}</style>

            <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
                <button
                    type="button"
                    onClick={() => {
                        // Fecha o separador; se o browser não deixar (página aberta diretamente), volta à aplicação.
                        window.close();
                        setTimeout(() => router.visit('/agendamento'), 200);
                    }}
                    className={ligacao}
                >
                    Fechar
                </button>
                <Link href={`/agendamento/mapa?vista=${vista}&data=${anterior}`} className={ligacao}>
                    ‹ {vista === 'dia' ? 'Dia anterior' : 'Semana anterior'}
                </Link>
                <Link href={`/agendamento/mapa?vista=${vista}&data=${seguinte}`} className={ligacao}>
                    {vista === 'dia' ? 'Dia seguinte' : 'Semana seguinte'} ›
                </Link>
                <Link href={`/agendamento/mapa?vista=${vista === 'dia' ? 'semana' : 'dia'}&data=${inicio}`} className={ligacao}>
                    Ver {vista === 'dia' ? 'semana' : 'dia'}
                </Link>
                <button
                    type="button"
                    onClick={() => window.print()}
                    className="ml-auto rounded-md bg-[#a3213e] px-4 py-1.5 text-sm font-semibold text-white hover:bg-[#8a1b34]"
                >
                    Imprimir
                </button>
            </div>

            <header className="mb-4 border-b-2 border-slate-900 pb-2">
                <p className="text-xs tracking-wide text-slate-600 uppercase">Unidade Funcional de Cirurgia Colorectal · ULS Região de Leiria</p>
                <h1 className="text-xl font-semibold">{titulo}</h1>
                <p className="text-sm text-slate-600">
                    {total} {total === 1 ? 'cirurgia' : 'cirurgias'} · cancelamentos não incluídos
                </p>
            </header>

            <div className="space-y-6">
                {dias.map((dia) => {
                    const d = new Date(dia.data + 'T00:00:00');
                    return (
                        <section key={dia.data} className="break-inside-avoid">
                            <h2 className="mb-1 border-b border-slate-400 text-sm font-semibold">
                                {NOMES_DIA[d.getDay()]}, {formatarData(dia.data)}
                            </h2>
                            {dia.agendamentos.length === 0 ? (
                                <p className="py-1 text-sm text-slate-500">Sem cirurgias agendadas.</p>
                            ) : (
                                <table className="w-full border-collapse text-left text-xs">
                                    <thead>
                                        <tr className="border-b border-slate-300 text-slate-600">
                                            <th className="py-1 pr-2 font-medium">Hora / sala</th>
                                            <th className="py-1 pr-2 font-medium">Utente</th>
                                            <th className="py-1 pr-2 font-medium">Processo</th>
                                            <th className="py-1 pr-2 font-medium">Intervenção</th>
                                            <th className="py-1 pr-2 font-medium">Tipo</th>
                                            <th className="py-1 pr-2 font-medium">Responsável</th>
                                            <th className="py-1 pr-2 font-medium">Estado</th>
                                            <th className="py-1 font-medium">Notas</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {dia.agendamentos.map((a) => (
                                            <tr key={a.id} className="border-b border-slate-200 align-top">
                                                <td className="py-1.5 pr-2 whitespace-nowrap">
                                                    {[a.hora, a.periodo, a.sala ? `Sala ${a.sala}` : null].filter(Boolean).join(' · ') || '—'}
                                                </td>
                                                <td className="py-1.5 pr-2 font-medium">
                                                    {a.utente.nome ?? 'Utente sem ficha'}
                                                    {a.utente.cr && <EtiquetaCr />}
                                                    {a.idade !== null && <span className="font-normal text-slate-600"> ({a.idade} anos)</span>}
                                                </td>
                                                <td className="py-1.5 pr-2 tabular-nums">
                                                    <NumeroProcesso semRotulo valor={a.utente.numero_processo} />
                                                </td>
                                                <td className="py-1.5 pr-2">{a.intervencoes.join(', ') || '—'}</td>
                                                <td className="py-1.5 pr-2">{[a.tipo, a.local].filter(Boolean).join(' · ') || '—'}</td>
                                                <td className="py-1.5 pr-2">{a.responsavel ?? '—'}</td>
                                                <td className="py-1.5 pr-2">{nomeEstado(estados, a.estado)}</td>
                                                <td className="py-1.5">
                                                    {a.reserva_sangue && <span className="font-semibold">Reserva de sangue. </span>}
                                                    {a.comentarios}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </section>
                    );
                })}
            </div>

            <p className="mt-6 text-xs text-slate-500">Gerado a {geradoEm}</p>
        </div>
    );
}

Mapa.layout = (pagina: React.ReactNode) => pagina;
