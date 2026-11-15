import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { BarrasH, Cartao, Colunas, ESTILO_VIZ, Indicador, Linha, formatar, type Ponto } from '../components/analise/graficos';
import { SeparadoresAnalise } from '../components/analise/separadores';
import ClinicoLayout from '../layouts/clinico-layout';

type Props = {
    periodo: '12' | '24' | 'todos';
    geradoEm: string;
    indicadores: {
        internamentos: { valor: number; anterior: number | null };
        altas: number;
        demora: number | null;
        complicacoes: number | null;
        mortalidade: number | null;
        cirurgias: number;
        seguimentos_em_atraso: number;
    };
    mensal: Ponto[];
    anual: { ano: number; internamentos: number; cirurgias: number; demora: number | null; complicacoes: number | null }[];
    clavien: Ponto[];
    intervencoes: Ponto[];
    diagnosticos: Ponto[];
    espera: { total: number; mediana_dias: number | null; por_prioridade: Ponto[]; por_espera: Ponto[] };
};

const PERIODOS: { valor: Props['periodo']; rotulo: string }[] = [
    { valor: '12', rotulo: 'Últimos 12 meses' },
    { valor: '24', rotulo: 'Últimos 24 meses' },
    { valor: 'todos', rotulo: 'Todo o período' },
];

const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
const nomeMes = (ym: string) => `${MESES[Number(ym.slice(5)) - 1]} ${ym.slice(2, 4)}`;

export default function Analise({ periodo, geradoEm, indicadores, mensal, anual, clavien, intervencoes, diagnosticos, espera }: Props) {
    const [aCarregar, setACarregar] = useState(false);
    const mudar = (valor: Props['periodo']) =>
        router.get('/dashboard', valor === '12' ? {} : { periodo: valor }, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setACarregar(true),
            onFinish: () => setACarregar(false),
        });

    const { internamentos: ent } = indicadores;
    const variacao = ent.anterior ? Math.round(((ent.valor - ent.anterior) / ent.anterior) * 100) : null;
    const nomePeriodo = PERIODOS.find((p) => p.valor === periodo)?.rotulo.toLowerCase() ?? '';
    const anos: Ponto[] = anual.map((a) => ({ rotulo: String(a.ano), valor: a.internamentos }));
    const tabela = (pontos: Ponto[], sufixo = '') => pontos.map((p) => [p.rotulo, p.valor === null ? '—' : `${formatar(p.valor)}${sufixo}`]);

    return (
        <ClinicoLayout titulo="Análise" subtitulo={`Avaliação da base de dados · gerada a ${geradoEm}`} largura="larga">
            <style>{ESTILO_VIZ}</style>
            <SeparadoresAnalise atual="geral" />
            <div className="viz-root space-y-5">
                <div className="flex flex-wrap items-center gap-3">
                    <div
                        className="inline-flex rounded-md border border-slate-300 bg-white p-0.5 dark:border-slate-600 dark:bg-transparent"
                        role="group"
                        aria-label="Período"
                    >
                        {PERIODOS.map((p) => (
                            <button
                                key={p.valor}
                                type="button"
                                aria-pressed={periodo === p.valor}
                                onClick={() => mudar(p.valor)}
                                className={`rounded px-3 py-1.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-teal-700 ${
                                    periodo === p.valor
                                        ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900'
                                        : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10'
                                }`}
                            >
                                {p.rotulo}
                            </button>
                        ))}
                    </div>
                    <p className="text-xs text-slate-600 dark:text-slate-400">
                        O período aplica-se aos indicadores, ao grau de Clavien-Dindo e aos mais frequentes. As séries por mês e por ano mostram
                        sempre o histórico.
                    </p>
                </div>

                <div className={`space-y-5 transition-opacity ${aCarregar ? 'opacity-50' : ''}`}>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                        <Indicador
                            rotulo={`Internamentos (${nomePeriodo})`}
                            valor={formatar(ent.valor)}
                            nota={
                                variacao !== null
                                    ? `${variacao > 0 ? '+' : ''}${variacao}% face ao período anterior (${formatar(ent.anterior ?? 0)})`
                                    : undefined
                            }
                            destaque
                        />
                        <Indicador
                            rotulo="Demora média"
                            valor={indicadores.demora !== null ? `${formatar(indicadores.demora)} d` : '—'}
                            nota={`${formatar(indicadores.altas)} altas`}
                        />
                        <Indicador
                            rotulo="Altas com complicações"
                            valor={indicadores.complicacoes !== null ? `${formatar(indicadores.complicacoes)}%` : '—'}
                            nota="Pelo menos uma complicação registada"
                        />
                        <Indicador
                            rotulo="Mortalidade"
                            valor={indicadores.mortalidade !== null ? `${formatar(indicadores.mortalidade)}%` : '—'}
                            nota="Destino falecido, nas altas"
                        />
                        <Indicador rotulo="Cirurgias" valor={formatar(indicadores.cirurgias)} nota="Bloco operatório" />
                        <Indicador
                            rotulo="Em lista de espera"
                            valor={formatar(espera.total)}
                            nota={espera.mediana_dias !== null ? `Espera mediana ${formatar(espera.mediana_dias)} dias` : undefined}
                        />
                        <Indicador
                            rotulo="Seguimentos em atraso"
                            valor={formatar(indicadores.seguimentos_em_atraso)}
                            nota={
                                <Link href="/internamentos/seguimentos" className="underline underline-offset-2">
                                    Ver lista (altas dos últimos 90 dias)
                                </Link>
                            }
                        />
                    </div>

                    <Cartao
                        titulo="Internamentos por mês"
                        nota="Entradas nos últimos 24 meses; o mês em curso está incompleto"
                        colunas={['Mês', 'Entradas']}
                        linhas={tabela(mensal.map((m) => ({ ...m, rotulo: nomeMes(m.rotulo) })))}
                    >
                        <Colunas dados={mensal} etiqueta={(p) => nomeMes(p.rotulo)} passoRotulos={2} largura={1280} altura={210} />
                    </Cartao>

                    <div className="grid gap-5 lg:grid-cols-2">
                        <Cartao
                            titulo="Internamentos por ano"
                            nota="Por ano de entrada; o ano em curso está incompleto"
                            colunas={['Ano', 'Internamentos']}
                            linhas={tabela(anos)}
                        >
                            <Colunas dados={anos} passoRotulos={1} />
                        </Cartao>
                        <Cartao
                            titulo="Cirurgias por ano"
                            nota="Bloco operatório; o ano em curso está incompleto"
                            colunas={['Ano', 'Cirurgias']}
                            linhas={tabela(anual.map((a) => ({ rotulo: String(a.ano), valor: a.cirurgias })))}
                        >
                            <Colunas dados={anual.map((a) => ({ rotulo: String(a.ano), valor: a.cirurgias }))} passoRotulos={1} />
                        </Cartao>
                        <Cartao
                            titulo="Demora média por ano"
                            nota="Dias entre entrada e alta, por ano de alta"
                            colunas={['Ano', 'Dias']}
                            linhas={tabela(anual.map((a) => ({ rotulo: String(a.ano), valor: a.demora })))}
                        >
                            <Linha dados={anual.map((a) => ({ rotulo: String(a.ano), valor: a.demora }))} sufixo=" d" />
                        </Cartao>
                        <Cartao
                            titulo="Altas com complicações por ano"
                            nota="% das altas com pelo menos uma complicação"
                            colunas={['Ano', '%']}
                            linhas={tabela(
                                anual.map((a) => ({ rotulo: String(a.ano), valor: a.complicacoes })),
                                '%',
                            )}
                        >
                            <Linha dados={anual.map((a) => ({ rotulo: String(a.ano), valor: a.complicacoes }))} sufixo="%" />
                        </Cartao>
                        <Cartao
                            titulo="Grau de Clavien-Dindo"
                            nota={`Grau mais grave por internamento (${nomePeriodo}); 3 e 4 incluem A e B`}
                            colunas={['Grau', 'Internamentos']}
                            linhas={tabela(clavien)}
                        >
                            <Colunas dados={clavien} passoRotulos={1} />
                        </Cartao>
                        <Cartao
                            titulo="Lista de espera por tempo"
                            nota="Inscrições ativas, desde a data de inscrição"
                            colunas={['Tempo de espera', 'Inscrições']}
                            linhas={tabela(espera.por_espera)}
                        >
                            <Colunas dados={espera.por_espera} passoRotulos={1} />
                        </Cartao>
                        <Cartao
                            titulo="Intervenções mais frequentes"
                            nota={`Top 10 (${nomePeriodo})`}
                            colunas={['Intervenção', 'Cirurgias']}
                            linhas={tabela(intervencoes)}
                        >
                            <BarrasH dados={intervencoes} />
                        </Cartao>
                        <Cartao
                            titulo="Diagnósticos mais frequentes"
                            nota={`Top 10 nos internamentos (${nomePeriodo})`}
                            colunas={['Diagnóstico', 'Internamentos']}
                            linhas={tabela(diagnosticos)}
                        >
                            <BarrasH dados={diagnosticos} />
                        </Cartao>
                        <Cartao
                            titulo="Lista de espera por prioridade"
                            nota="Inscrições ativas"
                            colunas={['Prioridade', 'Inscrições']}
                            linhas={tabela(espera.por_prioridade)}
                        >
                            <BarrasH dados={espera.por_prioridade} />
                        </Cartao>
                    </div>
                </div>
            </div>
        </ClinicoLayout>
    );
}

Analise.layout = (pagina: React.ReactNode) => pagina;
