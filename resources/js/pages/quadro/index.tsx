import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { diasEntre, idade, plural } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import type { PropsPartilhadas } from '../../clinico/tipos';
import { BotaoLigacao } from '../../components/clinico/campos';
import { ModalInternamento, type ModalInternamentoDados } from '../../components/internamentos/modal-internamento';
import ClinicoLayout from '../../layouts/clinico-layout';

type Internado = {
    id: number;
    cama: string;
    localizacao_id: number;
    localizacao: string;
    data_de_entrada: string;
    motivo: string;
    caso_social: string | null;
    centro_referencia: boolean;
    utente: { id: number | null; nome: string | null; data_nascimento: string | null; sexo: string | null; numero_processo: number | null };
    responsavel: { nome: string; abrev: string | null; equipa: string | null } | null;
    diagnosticos: string[];
    cirurgias: { data: string; tipo: string | null; intervencoes: string[] }[];
};

type Props = { internados: Internado[]; equipa: 'A' | 'B' | null; hoje: string; modal: ModalInternamentoDados | null };

/** Cor e comprimento da barra de demora: até 7 dias, 8 a 14, mais de 14. */
function demora(dias: number) {
    const largura = `${Math.max(6, Math.min(100, (dias / 30) * 100))}%`;
    if (dias > 14) return { largura, barra: 'bg-rose-600', texto: 'text-rose-800' };
    if (dias > 7) return { largura, barra: 'bg-amber-500', texto: 'text-amber-800' };
    return { largura, barra: 'bg-teal-600', texto: 'text-slate-700' };
}

function Cama({ internado, hoje, equipa }: { internado: Internado; hoje: string; equipa: 'A' | 'B' | null }) {
    const dias = Math.max(0, diasEntre(internado.data_de_entrada, hoje));
    const estilo = demora(dias);
    const anos = idade(internado.utente.data_nascimento, hoje);
    const resumo = internado.diagnosticos.length > 0 ? internado.diagnosticos.join(', ') : internado.motivo;

    return (
        <li>
            <Link
                href={`/quadro/${internado.id}${equipa ? `?equipa=${equipa}` : ''}`}
                preserveScroll
                className="flex h-full flex-col rounded-lg border border-slate-200 bg-white hover:border-teal-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700"
            >
                <div className="flex items-start gap-3 px-3 pt-3">
                    <span
                        className="min-w-11 rounded-md bg-slate-900 px-1.5 py-1 text-center text-lg leading-none font-semibold text-white tabular-nums"
                        aria-label={`Cama ${internado.cama}`}
                    >
                        {internado.cama}
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-slate-900">{internado.utente.nome ?? 'Utente sem ficha'}</p>
                        <p className="text-xs text-slate-600 tabular-nums">
                            {anos !== null ? `${anos} anos` : 'Idade por confirmar'}
                            {internado.utente.sexo ? `, ${internado.utente.sexo.toLowerCase()}` : ''}
                        </p>
                    </div>
                    {internado.responsavel && (
                        <span className="shrink-0 text-xs font-medium text-slate-600" title={internado.responsavel.nome}>
                            {internado.responsavel.abrev ?? internado.responsavel.nome}
                        </span>
                    )}
                </div>

                <p className="mt-2 line-clamp-2 flex-1 px-3 text-sm text-slate-700">{resumo}</p>

                {internado.cirurgias.map((c, n) => (
                    <p key={n} className="mx-3 mt-2 rounded bg-[#a3213e]/10 px-2 py-1 text-xs font-medium text-[#7d1830]">
                        Cirurgia {c.data.split('-').reverse().join('/')}
                        {c.tipo ? ` (${c.tipo})` : ''}
                        {c.intervencoes.length > 0 ? `: ${c.intervencoes.join(', ')}` : ''}
                    </p>
                ))}

                {internado.centro_referencia && (
                    <p className="mx-3 mt-2">
                        <span
                            className="rounded bg-violet-100 px-1.5 py-0.5 text-xs font-medium text-violet-900"
                            title="Sinalizado para o centro de referência"
                        >
                            Centro de referência
                        </span>
                    </p>
                )}

                {internado.caso_social && (
                    <p className="mx-3 mt-2 rounded bg-amber-50 px-2 py-1 text-xs font-medium text-amber-900">Caso social: {internado.caso_social}</p>
                )}

                <div className="mt-3 px-3 pb-3">
                    <p className={`text-xs font-medium tabular-nums ${estilo.texto}`}>{dias === 0 ? 'Entrou hoje' : plural(dias, 'dia', 'dias')}</p>
                    <div className="mt-1 h-1.5 rounded-full bg-slate-100" aria-hidden="true">
                        <div className={`h-1.5 rounded-full ${estilo.barra}`} style={{ width: estilo.largura }} />
                    </div>
                </div>
            </Link>
        </li>
    );
}

export default function Quadro({ internados, equipa, hoje, modal }: Props) {
    const { permissoes } = usePage<PropsPartilhadas>().props.clinico;
    const podeCriar = permissoes.includes('create internamento');

    const porLocalizacao = new Map<string, Internado[]>();
    for (const internado of internados) {
        porLocalizacao.set(internado.localizacao, [...(porLocalizacao.get(internado.localizacao) ?? []), internado]);
    }

    const longos = internados.filter((i) => diasEntre(i.data_de_entrada, hoje) > 14).length;
    const sociais = internados.filter((i) => i.caso_social).length;

    const filtrar = (valor: 'A' | 'B' | null) =>
        router.get(rotas.quadro, valor ? { equipa: valor } : {}, { preserveState: true, preserveScroll: true });

    const equipas: { rotulo: string; valor: 'A' | 'B' | null }[] = [
        { rotulo: 'Todas', valor: null },
        { rotulo: 'Equipa A', valor: 'A' },
        { rotulo: 'Equipa B', valor: 'B' },
    ];

    return (
        <ClinicoLayout
            titulo="Quadro do serviço"
            largura="larga"
            subtitulo={
                <span className="tabular-nums">
                    {plural(internados.length, 'doente internado', 'doentes internados')}
                    {longos > 0 && `, ${longos} há mais de 14 dias`}
                    {sociais > 0 && `, ${plural(sociais, 'caso social', 'casos sociais')}`}
                </span>
            }
            acoes={
                <>
                    <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Filtrar por equipa">
                        {equipas.map((opcao) => (
                            <button
                                key={opcao.rotulo}
                                type="button"
                                aria-pressed={equipa === opcao.valor}
                                onClick={() => filtrar(opcao.valor)}
                                className={`rounded px-3 py-1.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-teal-700 ${
                                    equipa === opcao.valor ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'
                                }`}
                            >
                                {opcao.rotulo}
                            </button>
                        ))}
                    </div>
                    <BotaoLigacao href={equipa ? `/quadro/imprimir?equipa=${equipa}` : '/quadro/imprimir'} variante="secundario">
                        Imprimir lista
                    </BotaoLigacao>
                    {podeCriar && <BotaoLigacao href={rotas.internamentos.novo()}>Registar internamento</BotaoLigacao>}
                </>
            }
        >
            {internados.length === 0 ? (
                <div className="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                    <p className="text-base font-medium text-slate-900">
                        {equipa ? `A equipa ${equipa} não tem doentes internados.` : 'Não há doentes internados.'}
                    </p>
                    {podeCriar && <p className="mt-1 text-sm text-slate-600">Registe um internamento para ele aparecer aqui.</p>}
                </div>
            ) : (
                <div className="space-y-7">
                    {[...porLocalizacao.entries()].map(([localizacao, lista]) => (
                        <section key={localizacao} aria-label={localizacao}>
                            <h2 className="mb-2 flex items-baseline gap-2 text-base font-semibold text-slate-900">
                                {localizacao}
                                <span className="text-sm font-normal text-slate-600 tabular-nums">{plural(lista.length, 'cama', 'camas')}</span>
                            </h2>
                            <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                                {lista.map((internado) => (
                                    <Cama key={internado.id} internado={internado} hoje={hoje} equipa={equipa} />
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
            )}
            <ModalInternamento modal={modal} />
        </ClinicoLayout>
    );
}

Quadro.layout = (pagina: ReactNode) => pagina;
