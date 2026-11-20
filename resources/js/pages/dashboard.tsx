import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { BotaoPartilhar } from '../components/analise/botao-partilhar';
import { FiltroTrimestres } from '../components/analise/filtro-trimestres';
import { BarrasH, Cartao, Colunas, ESTILO_VIZ, Indicador, Linha, formatar, type Ponto } from '../components/analise/graficos';
import { nomeMes, nomePeriodo, tabela } from '../components/analise/util';
import ClinicoLayout from '../layouts/clinico-layout';

type Props = {
    trimestres: string[];
    de: string | null;
    ate: string | null;
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

export default function Analise({ trimestres, de, ate, geradoEm, indicadores, mensal, anual, clavien, intervencoes, diagnosticos, espera }: Props) {
    const [aCarregar, setACarregar] = useState(false);
    const { internamentos: ent } = indicadores;
    const variacao = ent.anterior ? Math.round(((ent.valor - ent.anterior) / ent.anterior) * 100) : null;
    const periodo = nomePeriodo(de, ate);
    const anos: Ponto[] = anual.map((a) => ({ rotulo: String(a.ano), valor: a.internamentos }));

    return (
        <ClinicoLayout
            titulo="Análise global"
            subtitulo={`Avaliação da base de dados · gerada a ${geradoEm}`}
            largura="larga"
            acoes={<BotaoPartilhar ficheiro="analise-global" periodo={periodo} />}
        >
            <style>{ESTILO_VIZ}</style>
            <div className="viz-root space-y-5">
                <div className="flex flex-wrap items-center gap-3">
                    <FiltroTrimestres url="/dashboard" trimestres={trimestres} de={de} ate={ate} aoMudar={setACarregar} />
                    <p className="text-xs text-slate-600 dark:text-slate-400">
                        O trimestre escolhido aplica-se aos indicadores, ao grau de Clavien-Dindo, aos mais frequentes e à série por mês. As séries
                        por ano mostram sempre o histórico.
                    </p>
                </div>

                <div className={`space-y-5 transition-opacity ${aCarregar ? 'opacity-50' : ''}`}>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                        <Indicador
                            rotulo={`Internamentos (${periodo})`}
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
                        nota={`Entradas por mês (${periodo}); o mês em curso está incompleto`}
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
                            nota={`Grau mais grave por internamento (${periodo}); 3 e 4 incluem A e B`}
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
                            nota={`Top 10 (${periodo})`}
                            colunas={['Intervenção', 'Cirurgias']}
                            linhas={tabela(intervencoes)}
                        >
                            <BarrasH dados={intervencoes} />
                        </Cartao>
                        <Cartao
                            titulo="Diagnósticos mais frequentes"
                            nota={`Top 10 nos internamentos (${periodo})`}
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
