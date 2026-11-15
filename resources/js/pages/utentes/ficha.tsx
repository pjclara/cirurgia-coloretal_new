import { Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { diasEntre, formatarData, hojeIso, idade, plural } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import type { PropsPartilhadas } from '../../clinico/tipos';
import { Botao, BotaoLigacao, Seccao } from '../../components/clinico/campos';
import { SinalizarCentro } from '../../components/referenciacao/sinalizar-centro';
import ClinicoLayout from '../../layouts/clinico-layout';

type Utente = {
    id: number;
    nome: string;
    numero_processo: number;
    numero_utente: number | null;
    data_nascimento: string | null;
    sexo: string | null;
    concelho: string | null;
    estado_civil: string | null;
};

type Internamento = {
    id: number;
    data_de_entrada: string | null;
    data_de_alta: string | null;
    motivo: string;
    localizacao: string | null;
    destino: string | null;
    clavien: string | null;
    responsavel: string | null;
    diagnosticos: string[];
    cirurgias: { data: string; tipo: string | null; intervencoes: string[] }[];
};

function Dado({ rotulo, children }: { rotulo: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-slate-600">{rotulo}</dt>
            <dd className="text-sm font-medium text-slate-900 tabular-nums">{children}</dd>
        </div>
    );
}

type Referenciacao = {
    id: number;
    data_de_referenciacao: string | null;
    origem: string | null;
    data_de_entrada: string | null;
    data_de_saida: string | null;
    destino: string | null;
    responsavel: string | null;
    situacao: 'referenciado' | 'no_centro' | 'saida';
    diagnosticos: string[];
};

const SITUACAO = { referenciado: 'Referenciado', no_centro: 'No centro', saida: 'Saída registada' } as const;

export default function FichaUtente({
    utente,
    internamentos,
    referenciacoes = [],
    origensReferenciacao = [],
    responsaveisReferenciacao = [],
}: {
    utente: Utente;
    internamentos: Internamento[];
    referenciacoes?: Referenciacao[];
    origensReferenciacao?: { id: number; nome: string }[];
    responsaveisReferenciacao?: { id: number; nome: string; equipa: string | null }[];
}) {
    const [sinalizar, setSinalizar] = useState(false);
    const sinalizado = referenciacoes.some((r) => r.situacao !== 'saida');
    const { permissoes } = usePage<PropsPartilhadas>().props.clinico;
    const anos = idade(utente.data_nascimento);
    const aberto = internamentos.find((i) => !i.data_de_alta);

    return (
        <ClinicoLayout
            titulo={utente.nome}
            subtitulo={
                <span className="tabular-nums">
                    Processo {utente.numero_processo}
                    {sinalizado && (
                        <span className="ml-2 rounded bg-violet-100 px-1.5 py-0.5 text-xs font-medium text-violet-900">Centro de referência</span>
                    )}
                </span>
            }
            acoes={
                <>
                    <BotaoLigacao href={`/utentes/${utente.id}/cirurgias`} variante="secundario">
                        Cirurgias
                    </BotaoLigacao>
                    {permissoes.includes('create agendamento') && (
                        <BotaoLigacao href={`/agendamento/utentes/${utente.id}/inscrever`} variante="secundario">
                            Inscrever em lista de espera
                        </BotaoLigacao>
                    )}
                    {permissoes.includes('create referenciacao') && !sinalizado && (
                        <Botao variante="secundario" onClick={() => setSinalizar(true)}>
                            Sinalizar para o centro
                        </Botao>
                    )}
                    {permissoes.includes('update internamento') && (
                        <BotaoLigacao href={rotas.utentes.editar(utente.id)} variante="secundario">
                            Editar dados
                        </BotaoLigacao>
                    )}
                    {permissoes.includes('create internamento') && !aberto && (
                        <BotaoLigacao href={rotas.internamentos.novo(utente.id)}>Registar internamento</BotaoLigacao>
                    )}
                </>
            }
        >
            <SinalizarCentro
                utenteId={utente.id}
                nome={utente.nome}
                origens={origensReferenciacao}
                responsaveis={responsaveisReferenciacao}
                aberto={sinalizar}
                aoFechar={() => setSinalizar(false)}
            />
            <div className="space-y-5">
                <Seccao titulo="Identificação">
                    <dl className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3 lg:grid-cols-6">
                        <Dado rotulo="Nascimento">{formatarData(utente.data_nascimento)}</Dado>
                        <Dado rotulo="Idade">{anos !== null ? `${anos} anos` : 'Por confirmar'}</Dado>
                        <Dado rotulo="Sexo">{utente.sexo ?? '—'}</Dado>
                        <Dado rotulo="N.º utente">{utente.numero_utente ?? '—'}</Dado>
                        <Dado rotulo="Concelho">{utente.concelho ?? '—'}</Dado>
                        <Dado rotulo="Estado civil">{utente.estado_civil ?? '—'}</Dado>
                    </dl>
                </Seccao>

                {permissoes.includes('show referenciacao') && (
                    <Seccao
                        titulo="Centro de referência"
                        descricao={referenciacoes.length > 0 ? plural(referenciacoes.length, 'referenciação', 'referenciações') : undefined}
                    >
                        {referenciacoes.length === 0 ? (
                            <p className="text-sm text-slate-600">Este utente não está referenciado ao centro de referência.</p>
                        ) : (
                            <ol className="divide-y divide-slate-100">
                                {referenciacoes.map((r) => (
                                    <li key={r.id} className="flex flex-wrap items-baseline gap-x-6 gap-y-1 py-3 first:pt-0 last:pb-0">
                                        <p className="w-52 shrink-0 text-sm text-slate-700 tabular-nums">
                                            Referenciado a {formatarData(r.data_de_referenciacao)}
                                            {r.data_de_entrada && (
                                                <span className="block text-xs text-slate-500">
                                                    Entrada {formatarData(r.data_de_entrada)}
                                                    {r.data_de_saida ? `, saída ${formatarData(r.data_de_saida)}` : ''}
                                                </span>
                                            )}
                                        </p>
                                        <div className="min-w-0 flex-1">
                                            <Link href={`/referenciacao/${r.id}`} className="text-sm font-medium text-teal-800 hover:underline">
                                                {r.diagnosticos.length > 0 ? r.diagnosticos.join(', ') : 'Sem diagnóstico registado'}
                                            </Link>
                                            <p className="text-xs text-slate-600">
                                                {[r.origem, r.destino ? `Destino: ${r.destino}` : null, r.responsavel].filter(Boolean).join(' · ')}
                                            </p>
                                        </div>
                                        <span className="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-800">
                                            {SITUACAO[r.situacao]}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Seccao>
                )}

                <Seccao titulo="Internamentos" descricao={internamentos.length > 0 ? plural(internamentos.length, 'registo', 'registos') : undefined}>
                    {internamentos.length === 0 ? (
                        <p className="text-sm text-slate-600">Este utente ainda não tem internamentos registados.</p>
                    ) : (
                        <ol className="divide-y divide-slate-100">
                            {internamentos.map((i) => {
                                const dias = i.data_de_entrada ? diasEntre(i.data_de_entrada, i.data_de_alta ?? hojeIso()) : null;
                                return (
                                    <li key={i.id} className="flex flex-wrap items-baseline gap-x-6 gap-y-1 py-3 first:pt-0 last:pb-0">
                                        <p className="w-52 shrink-0 text-sm text-slate-700 tabular-nums">
                                            {formatarData(i.data_de_entrada)} a {i.data_de_alta ? formatarData(i.data_de_alta) : 'hoje'}
                                            {dias !== null && dias >= 0 && dias < 3650 && (
                                                <span className="text-slate-500"> ({plural(dias, 'dia', 'dias')})</span>
                                            )}
                                        </p>
                                        <div className="min-w-0 flex-1">
                                            <Link
                                                href={rotas.internamentos.registo(i.id)}
                                                className="text-sm font-medium text-teal-800 hover:underline"
                                            >
                                                {i.motivo}
                                            </Link>
                                            {i.diagnosticos.length > 0 && <p className="text-sm text-slate-600">{i.diagnosticos.join(', ')}</p>}
                                            {i.cirurgias.map((c, n) => (
                                                <p key={n} className="mt-1 text-sm font-medium text-[#7d1830]">
                                                    Cirurgia {formatarData(c.data)}
                                                    {c.tipo ? ` (${c.tipo})` : ''}
                                                    {c.intervencoes.length > 0 ? `: ${c.intervencoes.join(', ')}` : ''}
                                                </p>
                                            ))}
                                        </div>
                                        <p className="text-sm text-slate-600">
                                            {!i.data_de_alta ? (
                                                <span className="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-900">Internado</span>
                                            ) : (
                                                (i.destino ?? 'Sem destino')
                                            )}
                                            {i.clavien && <span className="ml-2 text-rose-800">Clavien-Dindo: {i.clavien}</span>}
                                        </p>
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                </Seccao>
            </div>
        </ClinicoLayout>
    );
}

FichaUtente.layout = (pagina: ReactNode) => pagina;
