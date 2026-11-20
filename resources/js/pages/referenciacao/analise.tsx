import { useState } from 'react';
import { FiltroTrimestres } from '../../components/analise/filtro-trimestres';
import { BarrasH, Cartao, Colunas, ESTILO_VIZ, Indicador, formatar, type Ponto } from '../../components/analise/graficos';
import { SeparadoresAnalise } from '../../components/analise/separadores';
import { nomeMes, nomePeriodo, tabela } from '../../components/analise/util';
import ClinicoLayout from '../../layouts/clinico-layout';

type Grupo = { internamentos: number; demora: number | null; complicacoes: number | null; mortalidade: number | null; operados: number | null };

type DadosDt = {
    decisoes: { valor: number; anterior: number | null };
    doentes: number;
    primeiras: number;
    por_doente: number | null;
    referenciadas_com_dt: { valor: number; de: number };
    dias_ate_primeira: number | null;
    media_ate_primeira: number | null;
    tempos_ate_dt: Ponto[];
    n_ate_dt: number;
    dias_dt_cirurgia: number | null;
    media_dt_cirurgia: number | null;
    tempos_dt_cirurgia: Ponto[];
    n_dt_cirurgia: number;
    operados_depois: { valor: number; de: number };
    mensal: Ponto[];
    anual: Ponto[];
    estados: Ponto[];
    timmings: Ponto[];
};

type Props = {
    dt: DadosDt;
    trimestres: string[];
    de: string | null;
    ate: string | null;
    geradoEm: string;
    indicadores: {
        referenciacoes: { valor: number; anterior: number | null };
        doentes: number;
        referenciados: number;
        no_centro: number;
        saidas: number;
        ate_entrada: number | null;
        diagnostico_ate_referenciacao: number | null;
        internados: number;
        operados: number;
    };
    anual: Ponto[];
    mensal: Ponto[];
    origens: Ponto[];
    responsaveis: Ponto[];
    diagnosticos: Ponto[];
    modos: Ponto[];
    histologias: Ponto[];
    tempos: Ponto[];
    comparacao: { cr: Grupo; restantes: Grupo };
};

const pc = (parte: number, todo: number) => (todo > 0 ? `${formatar(Math.round((1000 * parte) / todo) / 10)}%` : '—');
const valor = (v: number | null, sufixo = '') => (v === null ? '—' : formatar(v, sufixo));

export default function AnaliseCR({
    dt,
    trimestres,
    de,
    ate,
    geradoEm,
    indicadores: ind,
    anual,
    mensal,
    origens,
    responsaveis,
    diagnosticos,
    modos,
    histologias,
    tempos,
    comparacao,
}: Props) {
    const [aCarregar, setACarregar] = useState(false);
    const periodo = nomePeriodo(de, ate);
    const { referenciacoes: ref } = ind;
    const variacaoDt = dt.decisoes.anterior ? Math.round(((dt.decisoes.valor - dt.decisoes.anterior) / dt.decisoes.anterior) * 100) : null;
    const variacao = ref.anterior ? Math.round(((ref.valor - ref.anterior) / ref.anterior) * 100) : null;

    const linhasComparacao: { rotulo: string; cr: string; restantes: string }[] = [
        { rotulo: 'Internamentos', cr: formatar(comparacao.cr.internamentos), restantes: formatar(comparacao.restantes.internamentos) },
        { rotulo: 'Demora média (dias)', cr: valor(comparacao.cr.demora), restantes: valor(comparacao.restantes.demora) },
        { rotulo: 'Altas com complicações', cr: valor(comparacao.cr.complicacoes, '%'), restantes: valor(comparacao.restantes.complicacoes, '%') },
        { rotulo: 'Mortalidade (nas altas)', cr: valor(comparacao.cr.mortalidade, '%'), restantes: valor(comparacao.restantes.mortalidade, '%') },
        { rotulo: 'Internamentos com cirurgia', cr: valor(comparacao.cr.operados, '%'), restantes: valor(comparacao.restantes.operados, '%') },
    ];

    return (
        <ClinicoLayout titulo="Análise" subtitulo={`Centro de referência: doentes referenciados · gerada a ${geradoEm}`} largura="larga">
            <style>{ESTILO_VIZ}</style>
            <SeparadoresAnalise atual="cr" />
            <div className="viz-root space-y-5">
                <div className="flex flex-wrap items-center gap-3">
                    <FiltroTrimestres url="/referenciacao/analise" trimestres={trimestres} de={de} ate={ate} aoMudar={setACarregar} />
                    <p className="text-xs text-slate-600 dark:text-slate-400">
                        Os trimestres aplicam-se à data de referenciação. Só a série por ano mostra sempre o histórico.
                    </p>
                </div>

                <div className={`space-y-5 transition-opacity ${aCarregar ? 'opacity-50' : ''}`}>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-5">
                        <Indicador
                            rotulo={`Referenciações (${periodo})`}
                            valor={formatar(ref.valor)}
                            nota={
                                variacao !== null
                                    ? `${variacao > 0 ? '+' : ''}${variacao}% face ao período anterior (${formatar(ref.anterior ?? 0)})`
                                    : `${formatar(ind.doentes)} doentes`
                            }
                            destaque
                        />
                        <Indicador rotulo="Aguardam entrada" valor={formatar(ind.referenciados)} nota="Referenciados, sem data de entrada" />
                        <Indicador rotulo="No centro" valor={formatar(ind.no_centro)} nota="Com entrada e sem saída" />
                        <Indicador rotulo="Com saída" valor={formatar(ind.saidas)} nota="Saída do centro registada" />
                        <Indicador
                            rotulo="Espera até à entrada"
                            valor={ind.ate_entrada !== null ? `${formatar(ind.ate_entrada)} d` : '—'}
                            nota="Mediana, da referenciação à entrada"
                        />
                        <Indicador
                            rotulo="Do diagnóstico à referenciação"
                            valor={ind.diagnostico_ate_referenciacao !== null ? `${formatar(ind.diagnostico_ate_referenciacao)} d` : '—'}
                            nota="Mediana, onde há data de diagnóstico"
                        />
                        <Indicador
                            rotulo="Já internados no serviço"
                            valor={pc(ind.internados, ref.valor)}
                            nota={`${formatar(ind.internados)} de ${formatar(ref.valor)}, depois de referenciados`}
                        />
                        <Indicador
                            rotulo="Já operados no serviço"
                            valor={pc(ind.operados, ref.valor)}
                            nota={`${formatar(ind.operados)} de ${formatar(ref.valor)}, depois de referenciados`}
                        />
                    </div>

                    <Cartao
                        titulo="Referenciações por mês"
                        nota={`Por data de referenciação (${periodo}); o mês em curso está incompleto`}
                        colunas={['Mês', 'Referenciações']}
                        linhas={tabela(mensal.map((m) => ({ ...m, rotulo: nomeMes(m.rotulo) })))}
                    >
                        <Colunas dados={mensal} etiqueta={(p) => nomeMes(p.rotulo)} passoRotulos={2} largura={1280} altura={210} />
                    </Cartao>

                    <div className="grid gap-5 lg:grid-cols-2">
                        <Cartao
                            titulo="Referenciações por ano"
                            nota="Desde 2017; o ano em curso está incompleto"
                            colunas={['Ano', 'Referenciações']}
                            linhas={tabela(anual)}
                        >
                            <Colunas dados={anual} passoRotulos={1} />
                        </Cartao>
                        <Cartao
                            titulo="Tempo até à entrada no centro"
                            nota={`Dias entre a referenciação e a entrada (${periodo})`}
                            colunas={['Tempo', 'Referenciações']}
                            linhas={tabela(tempos)}
                        >
                            <Colunas dados={tempos} passoRotulos={1} />
                        </Cartao>
                        <Cartao titulo="Origem da referenciação" nota={periodo} colunas={['Origem', 'Referenciações']} linhas={tabela(origens)}>
                            <BarrasH dados={origens} />
                        </Cartao>
                        <Cartao
                            titulo="Responsável"
                            nota={`Top 10 (${periodo})`}
                            colunas={['Responsável', 'Referenciações']}
                            linhas={tabela(responsaveis)}
                        >
                            <BarrasH dados={responsaveis} />
                        </Cartao>
                        <Cartao
                            titulo="Diagnósticos mais frequentes"
                            nota={`Top 10 (${periodo})`}
                            colunas={['Diagnóstico', 'Referenciações']}
                            linhas={tabela(diagnosticos)}
                        >
                            <BarrasH dados={diagnosticos} />
                        </Cartao>
                        <Cartao
                            titulo="Modo de diagnóstico"
                            nota={`Diagnósticos registados (${periodo})`}
                            colunas={['Modo', 'Diagnósticos']}
                            linhas={tabela(modos)}
                        >
                            <BarrasH dados={modos} />
                        </Cartao>
                        <Cartao
                            titulo="Histologia"
                            nota={`Diagnósticos registados (${periodo})`}
                            colunas={['Histologia', 'Diagnósticos']}
                            linhas={tabela(histologias)}
                        >
                            <BarrasH dados={histologias} />
                        </Cartao>

                        <section
                            className="flex flex-col rounded-lg border bg-[var(--surface)] p-4"
                            style={{ borderColor: 'var(--border)' }}
                            aria-label="Doentes do CR e restantes"
                        >
                            <header className="mb-3">
                                <h2 className="text-sm font-semibold text-[var(--ink)]">Internamentos: doentes do CR e restantes</h2>
                                <p className="text-xs text-[var(--ink2)]">
                                    Internamentos do serviço ({periodo}) de doentes já referenciados ao CR na data de entrada, contra os restantes.
                                </p>
                            </header>
                            <table className="w-full text-left text-xs text-[var(--ink)]">
                                <thead>
                                    <tr className="border-b text-[var(--ink2)]" style={{ borderColor: 'var(--grid)' }}>
                                        <th className="py-1 pr-3 font-medium"> </th>
                                        <th className="py-1 pr-3 text-right font-medium">CR</th>
                                        <th className="py-1 text-right font-medium">Restantes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {linhasComparacao.map((l) => (
                                        <tr key={l.rotulo} className="border-b last:border-0" style={{ borderColor: 'var(--grid)' }}>
                                            <th scope="row" className="py-1.5 pr-3 font-normal text-[var(--ink2)]">
                                                {l.rotulo}
                                            </th>
                                            <td className="py-1.5 pr-3 text-right font-semibold tabular-nums">{l.cr}</td>
                                            <td className="py-1.5 text-right tabular-nums">{l.restantes}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <p className="mt-3 text-xs text-[var(--ink2)]">
                                Comparação simples, sem ajuste por idade, diagnóstico ou gravidade: os doentes do CR são sobretudo neoplasias
                                operadas, por isso não permite concluir que o CR melhora ou piora os resultados.
                            </p>
                        </section>
                    </div>

                    <h2 className="pt-2 text-base font-semibold text-[var(--ink)]">Decisão terapêutica (DT)</h2>
                    <p className="-mt-3 text-xs text-[var(--ink2)]">
                        Reuniões de decisão terapêutica dos doentes referenciados; os trimestres aplicam-se à data da decisão. Os dados mais recentes
                        vêm do ficheiro do hospital (só data e estado).
                    </p>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-5">
                        <Indicador
                            rotulo={`Decisões DT (${periodo})`}
                            valor={formatar(dt.decisoes.valor)}
                            nota={
                                variacaoDt !== null
                                    ? `${variacaoDt > 0 ? '+' : ''}${variacaoDt}% face ao período anterior (${formatar(dt.decisoes.anterior ?? 0)})`
                                    : `${formatar(dt.doentes)} doentes`
                            }
                            destaque
                        />
                        <Indicador rotulo="Doentes discutidos" valor={formatar(dt.doentes)} nota="Doentes distintos com decisão no período" />
                        <Indicador rotulo="Primeira DT" valor={formatar(dt.primeiras)} nota="Doentes cuja 1.ª decisão é neste período" />
                        <Indicador
                            rotulo="Decisões por doente"
                            valor={dt.por_doente !== null ? formatar(dt.por_doente) : '—'}
                            nota="Média no período"
                        />
                        <Indicador
                            rotulo="Referenciações com DT"
                            valor={pc(dt.referenciadas_com_dt.valor, dt.referenciadas_com_dt.de)}
                            nota={`${formatar(dt.referenciadas_com_dt.valor)} de ${formatar(dt.referenciadas_com_dt.de)} referenciadas no período`}
                        />
                        <Indicador
                            rotulo="Da DT à cirurgia"
                            valor={dt.dias_dt_cirurgia !== null ? `${formatar(dt.dias_dt_cirurgia)} d` : '—'}
                            nota={`Mediana · ${pc(dt.operados_depois.valor, dt.operados_depois.de)} dos doentes operados depois`}
                        />
                        <Indicador
                            rotulo="Da referenciação à 1.ª DT"
                            valor={dt.dias_ate_primeira !== null ? `${formatar(dt.dias_ate_primeira)} d` : '—'}
                            nota="Mediana, referenciações do período com DT"
                        />
                    </div>

                    <div className="grid gap-5 lg:grid-cols-2">
                        <Cartao
                            titulo="Tempo da referenciação à 1.ª DT"
                            nota={`Dias entre a referenciação e a 1.ª decisão (${periodo}) · ${formatar(dt.n_ate_dt)} referenciações · mediana ${valor(dt.dias_ate_primeira)} d, média ${valor(dt.media_ate_primeira)} d`}
                            colunas={['Tempo', 'Referenciações']}
                            linhas={tabela(dt.tempos_ate_dt)}
                        >
                            <Colunas dados={dt.tempos_ate_dt} passoRotulos={1} />
                        </Cartao>
                        <Cartao
                            titulo="Tempo da DT à cirurgia"
                            nota={`Dias entre a última DT e a cirurgia (até 1 ano), cirurgias dos doentes discutidos no período · ${formatar(dt.n_dt_cirurgia)} cirurgias · mediana ${valor(dt.dias_dt_cirurgia)} d, média ${valor(dt.media_dt_cirurgia)} d · ${pc(dt.operados_depois.valor, dt.operados_depois.de)} dos doentes operados depois`}
                            colunas={['Tempo', 'Cirurgias']}
                            linhas={tabela(dt.tempos_dt_cirurgia)}
                        >
                            <Colunas dados={dt.tempos_dt_cirurgia} passoRotulos={1} />
                        </Cartao>
                    </div>

                    <Cartao
                        titulo="Decisões DT por mês"
                        nota={`Por data da decisão (${periodo}); o mês em curso está incompleto`}
                        colunas={['Mês', 'Decisões']}
                        linhas={tabela(dt.mensal.map((m) => ({ ...m, rotulo: nomeMes(m.rotulo) })))}
                    >
                        <Colunas dados={dt.mensal} etiqueta={(p) => nomeMes(p.rotulo)} passoRotulos={2} largura={1280} altura={210} />
                    </Cartao>

                    <div className="grid gap-5 lg:grid-cols-2">
                        <Cartao
                            titulo="Decisões DT por ano"
                            nota="Desde 2021; o ano em curso está incompleto"
                            colunas={['Ano', 'Decisões']}
                            linhas={tabela(dt.anual)}
                        >
                            <Colunas dados={dt.anual} passoRotulos={1} />
                        </Cartao>
                        <Cartao titulo="Estado da decisão" nota={periodo} colunas={['Estado', 'Decisões']} linhas={tabela(dt.estados)}>
                            <BarrasH dados={dt.estados} />
                        </Cartao>
                        {dt.timmings.length > 0 && (
                            <Cartao
                                titulo="Timming"
                                nota={`Decisões com timming registado (${periodo})`}
                                colunas={['Timming', 'Decisões']}
                                linhas={tabela(dt.timmings)}
                            >
                                <BarrasH dados={dt.timmings} />
                            </Cartao>
                        )}
                    </div>

                    <p className="text-xs text-[var(--ink2)]">
                        “Aguardam entrada”, “No centro” e “Com saída” seguem as datas registadas em cada referenciação (entrada e saída).
                    </p>
                </div>
            </div>
        </ClinicoLayout>
    );
}

AnaliseCR.layout = (pagina: React.ReactNode) => pagina;
