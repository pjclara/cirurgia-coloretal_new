import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { formatarData } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import { FiltroTrimestres } from '../../components/analise/filtro-trimestres';
import { ESTILO_VIZ, formatar } from '../../components/analise/graficos';
import { NumeroProcesso } from '../../components/clinico/numero-processo';
import ClinicoLayout from '../../layouts/clinico-layout';

type Campo = 'alta' | 'destino' | 'morbilidade_alta' | 'seguimento_30' | 'clavien_30' | 'datas';
type Qualidade = {
    cirurgias: number;
    totais: Record<Campo, number>;
    linhas: {
        bloco: number;
        internamento: number;
        utente: { id: number; nome: string | null; numero_processo: number | null };
        data: string;
        intervencao: string;
        falta: Campo[];
    }[];
};

const CAMPOS: { campo: Campo; rotulo: string; ajuda: string }[] = [
    { campo: 'alta', rotulo: 'Data de alta', ajuda: 'Sem data de alta registada' },
    { campo: 'destino', rotulo: 'Destino', ajuda: 'Sem destino à alta (necessário para a mortalidade)' },
    { campo: 'morbilidade_alta', rotulo: 'Morbilidade na alta', ajuda: 'Sem registo de complicações na alta' },
    { campo: 'seguimento_30', rotulo: 'Seguimento 30 dias', ajuda: 'Doente vivo, alta há mais de 30 dias, sem registo aos 30 dias' },
    { campo: 'clavien_30', rotulo: 'Clavien-Dindo 30 dias', ajuda: 'Registo aos 30 dias sem grau de Clavien-Dindo' },
    { campo: 'datas', rotulo: 'Datas', ajuda: 'Cirurgia antes da entrada ou depois da alta' },
];

type Props = {
    qualidade: Qualidade;
    trimestres: string[];
    de: string | null;
    ate: string | null;
    geradoEm: string;
};

export default function QualidadeDados({ qualidade, trimestres, de, ate, geradoEm }: Props) {
    const [aCarregar, setACarregar] = useState(false);

    return (
        <ClinicoLayout titulo="Qualidade dos dados" subtitulo={`Cancro retal · doentes do CR · gerada a ${geradoEm}`} largura="larga" pesquisa={null}>
            <style>{ESTILO_VIZ}</style>
            <div className="viz-root space-y-5">
                <FiltroTrimestres url="/analise/cancro-retal/qualidade" trimestres={trimestres} de={de} ate={ate} aoMudar={setACarregar} />
                <div className={`transition-opacity ${aCarregar ? 'opacity-50' : ''}`}>
                    <section
                        className="rounded-lg border bg-[var(--surface)] p-4"
                        style={{ borderColor: 'var(--border)' }}
                        aria-label="Qualidade dos dados"
                    >
                        <h2 className="text-sm font-semibold text-[var(--ink)]">Qualidade dos dados</h2>
                        <p className="mb-3 text-xs text-[var(--ink2)]">
                            Doentes referenciados operados no período escolhido com dados em falta para os indicadores:{' '}
                            {formatar(qualidade.linhas.length)} de {formatar(qualidade.cirurgias)} cirurgias. A morbilidade e a deiscência dependem
                            dos registos da alta e dos 30 dias; a mortalidade, do destino.
                        </p>
                        {qualidade.linhas.length === 0 ? (
                            <p className="py-6 text-center text-sm text-[var(--ink2)]">Nenhum dado em falta neste período.</p>
                        ) : (
                            <div className="max-h-[32rem] overflow-auto">
                                <table className="w-full text-left text-xs text-[var(--ink)]">
                                    <thead className="sticky top-0 bg-[var(--surface)]">
                                        <tr className="border-b text-[var(--ink2)]" style={{ borderColor: 'var(--grid)' }}>
                                            <th className="py-1.5 pr-3 font-medium">Doente</th>
                                            <th className="py-1.5 pr-3 font-medium">Cirurgia</th>
                                            <th className="py-1.5 pr-3 font-medium">Intervenção</th>
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
                                                    <Link href={rotas.internamentos.registo(l.internamento)} className="underline underline-offset-2">
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
        </ClinicoLayout>
    );
}

QualidadeDados.layout = (pagina: React.ReactNode) => pagina;
