import { Link, router, usePage } from '@inertiajs/react';
import { LayoutGrid, Rows3 } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { diasEntre, idade, plural } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import type { PropsPartilhadas } from '../../clinico/tipos';
import { ModalCirurgia, type ModalCirurgiaDados } from '../../components/cirurgias/modal-cirurgia';
import { BotaoLigacao, estiloEntrada } from '../../components/clinico/campos';
import { ModalInternamento, type ModalInternamentoDados } from '../../components/internamentos/modal-internamento';
import { ModalUtente, type ModalUtenteDados } from '../../components/utentes/modal-utente';
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
    cirurgias: { id: number; data: string; tipo: string | null; intervencoes: string[] }[];
};

type Props = {
    internados: Internado[];
    equipa: 'A' | 'B' | null;
    hoje: string;
    modal: ModalInternamentoDados | null;
    modalUtente: ModalUtenteDados | null;
    modalCirurgia: ModalCirurgiaDados | null;
};

/** Cor e comprimento da barra de demora: até 7 dias, 8 a 14, mais de 14. */
function demora(dias: number) {
    const largura = `${Math.max(6, Math.min(100, (dias / 30) * 100))}%`;
    if (dias > 14) return { largura, barra: 'bg-rose-600', texto: 'text-rose-800' };
    if (dias > 7) return { largura, barra: 'bg-amber-500', texto: 'text-amber-800' };
    return { largura, barra: 'bg-teal-600', texto: 'text-slate-700' };
}

function Cama({ internado, hoje, equipa, podeCriar }: { internado: Internado; hoje: string; equipa: 'A' | 'B' | null; podeCriar: boolean }) {
    const dias = Math.max(0, diasEntre(internado.data_de_entrada, hoje));
    const estilo = demora(dias);
    const anos = idade(internado.utente.data_nascimento, hoje);
    const resumo = internado.diagnosticos.length > 0 ? internado.diagnosticos.join(', ') : internado.motivo;

    return (
        <li>
            <div className="relative flex h-full flex-col rounded-lg border border-slate-200 bg-white focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-teal-700 hover:border-teal-700">
                <div className="flex items-start gap-3 px-3 pt-3">
                    <span
                        className="min-w-11 rounded-md bg-slate-900 px-1.5 py-1 text-center text-lg leading-none font-semibold text-white tabular-nums"
                        aria-label={`Cama ${internado.cama}`}
                    >
                        {internado.cama}
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-slate-900">
                            <Link
                                href={`/quadro/${internado.id}${equipa ? `?equipa=${equipa}` : ''}`}
                                preserveScroll
                                className="after:absolute after:inset-0 after:content-[''] focus-visible:outline-none"
                            >
                                {internado.utente.nome ?? 'Utente sem ficha'}
                            </Link>
                        </p>
                        <p className="text-xs text-slate-600 tabular-nums">
                            {anos !== null ? `${anos} anos` : 'Idade por confirmar'}
                            {internado.utente.sexo ? `, ${internado.utente.sexo.toLowerCase()}` : ''}
                        </p>
                        {internado.utente.numero_processo && (
                            <p className="text-xs text-slate-600 tabular-nums">Processo {internado.utente.numero_processo}</p>
                        )}
                    </div>
                    {internado.responsavel && (
                        <span className="shrink-0 text-xs font-medium text-slate-600" title={internado.responsavel.nome}>
                            {internado.responsavel.abrev ?? internado.responsavel.nome}
                        </span>
                    )}
                </div>

                <p className="mt-2 line-clamp-2 flex-1 px-3 text-sm text-slate-700">{resumo}</p>

                {internado.cirurgias.map((c) => (
                    <Link
                        key={c.id}
                        href={`/quadro/cirurgias/${c.id}`}
                        preserveScroll
                        title="Abrir a cirurgia"
                        className="relative z-10 mx-3 mt-2 block rounded bg-[#a3213e]/10 px-2 py-1 text-xs font-medium text-[#7d1830] hover:bg-[#a3213e]/20 focus-visible:outline-2 focus-visible:outline-[#7d1830]"
                    >
                        Cirurgia {c.data.split('-').reverse().join('/')}
                        {c.tipo ? ` (${c.tipo})` : ''}
                        {c.intervencoes.length > 0 ? `: ${c.intervencoes.join(', ')}` : ''}
                    </Link>
                ))}
                {podeCriar && (
                    <Link
                        href={`/quadro/${internado.id}/cirurgias/nova`}
                        preserveScroll
                        className="relative z-10 mx-3 mt-2 self-start rounded border border-dashed border-slate-300 px-2 py-0.5 text-xs font-medium text-slate-700 hover:border-[#7d1830] hover:text-[#7d1830] focus-visible:outline-2 focus-visible:outline-[#7d1830]"
                    >
                        + Cirurgia
                    </Link>
                )}

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
            </div>
        </li>
    );
}

function Tabela({ lista, hoje, equipa, podeCriar }: { lista: Internado[]; hoje: string; equipa: 'A' | 'B' | null; podeCriar: boolean }) {
    const ligacao = (id: number) => `/quadro/${id}${equipa ? `?equipa=${equipa}` : ''}`;

    return (
        <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table className="w-full min-w-[56rem] text-left text-sm">
                <thead className="bg-slate-50 text-xs font-semibold text-slate-600">
                    <tr>
                        <th scope="col" className="px-3 py-2">
                            Cama
                        </th>
                        <th scope="col" className="px-3 py-2">
                            Processo
                        </th>
                        <th scope="col" className="px-3 py-2">
                            Utente
                        </th>
                        <th scope="col" className="px-3 py-2">
                            Idade
                        </th>
                        <th scope="col" className="px-3 py-2">
                            Diagnóstico
                        </th>
                        <th scope="col" className="px-3 py-2">
                            Cirurgia
                        </th>
                        <th scope="col" className="px-3 py-2">
                            Resp.
                        </th>
                        <th scope="col" className="px-3 py-2 text-right">
                            Dias
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {lista.map((i) => {
                        const dias = Math.max(0, diasEntre(i.data_de_entrada, hoje));
                        const anos = idade(i.utente.data_nascimento, hoje);
                        const resumo = i.diagnosticos.length > 0 ? i.diagnosticos.join(', ') : i.motivo;
                        return (
                            <tr
                                key={i.id}
                                className="cursor-pointer hover:bg-teal-50/60"
                                onClick={() => router.visit(ligacao(i.id), { preserveScroll: true })}
                            >
                                <td className="px-3 py-2">
                                    <span className="inline-block min-w-9 rounded bg-slate-900 px-1.5 py-0.5 text-center font-semibold text-white tabular-nums">
                                        {i.cama}
                                    </span>
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap text-slate-700 tabular-nums">{i.utente.numero_processo ?? '–'}</td>
                                <td className="px-3 py-2 font-medium text-slate-900">
                                    <Link
                                        href={ligacao(i.id)}
                                        preserveScroll
                                        onClick={(e) => e.stopPropagation()}
                                        className="hover:underline focus-visible:outline-2 focus-visible:outline-teal-700"
                                    >
                                        {i.utente.nome ?? 'Utente sem ficha'}
                                    </Link>
                                    <span className="ml-1 inline-flex gap-1 align-middle">
                                        {i.centro_referencia && (
                                            <span
                                                className="rounded bg-violet-100 px-1.5 py-0.5 text-xs font-medium text-violet-900"
                                                title="Sinalizado para o centro de referência"
                                            >
                                                CR
                                            </span>
                                        )}
                                        {i.caso_social && (
                                            <span
                                                className="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900"
                                                title={`Caso social: ${i.caso_social}`}
                                            >
                                                Social
                                            </span>
                                        )}
                                    </span>
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap text-slate-700 tabular-nums">
                                    {anos !== null ? `${anos} a` : '–'}
                                    {i.utente.sexo ? ` ${i.utente.sexo.charAt(0).toUpperCase()}` : ''}
                                </td>
                                <td className="max-w-xs px-3 py-2 text-slate-700">{resumo}</td>
                                <td className="max-w-xs px-3 py-2 text-xs text-[#7d1830]">
                                    {i.cirurgias.map((c) => (
                                        <Link
                                            key={c.id}
                                            href={`/quadro/cirurgias/${c.id}`}
                                            preserveScroll
                                            onClick={(e) => e.stopPropagation()}
                                            title="Abrir a cirurgia"
                                            className="block hover:underline focus-visible:outline-2 focus-visible:outline-[#7d1830]"
                                        >
                                            {c.data.split('-').reverse().join('/')}
                                            {c.intervencoes.length > 0 ? `: ${c.intervencoes.join(', ')}` : ''}
                                        </Link>
                                    ))}
                                    {i.cirurgias.length === 0 && !podeCriar && '–'}
                                    {podeCriar && (
                                        <Link
                                            href={`/quadro/${i.id}/cirurgias/nova`}
                                            preserveScroll
                                            onClick={(e) => e.stopPropagation()}
                                            className="mt-0.5 block font-medium text-slate-600 hover:text-[#7d1830] hover:underline"
                                        >
                                            + Cirurgia
                                        </Link>
                                    )}
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap text-slate-700" title={i.responsavel?.nome}>
                                    {i.responsavel?.abrev ?? i.responsavel?.nome ?? '–'}
                                </td>
                                <td className={`px-3 py-2 text-right font-medium whitespace-nowrap tabular-nums ${demora(dias).texto}`}>
                                    {dias === 0 ? 'Hoje' : dias}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

type Vista = 'cartoes' | 'tabela';

function vistaGuardada(): Vista {
    try {
        return localStorage.getItem('quadro.vista') === 'tabela' ? 'tabela' : 'cartoes';
    } catch {
        return 'cartoes';
    }
}

export default function Quadro({ internados, equipa, hoje, modal, modalUtente, modalCirurgia }: Props) {
    const { permissoes } = usePage<PropsPartilhadas>().props.clinico;
    const podeCriar = permissoes.includes('create internamento');
    const [vista, setVista] = useState<Vista>(vistaGuardada);
    const mudarVista = (nova: Vista) => {
        setVista(nova);
        try {
            localStorage.setItem('quadro.vista', nova);
        } catch {
            /* sem armazenamento: a escolha vale só nesta página */
        }
    };

    const [termo, setTermo] = useState('');
    const semAcentos = (t: string) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    // Filtra os internados por nome, processo, cama, diagnóstico, localização ou responsável (todas as palavras têm de existir).
    const palavras = semAcentos(termo.trim()).split(/\s+/).filter(Boolean);
    const visiveis =
        palavras.length === 0
            ? internados
            : internados.filter((i) => {
                  const texto = semAcentos(
                      [
                          i.utente.nome,
                          i.utente.numero_processo,
                          i.cama,
                          i.localizacao,
                          i.motivo,
                          i.diagnosticos.join(' '),
                          i.responsavel?.abrev,
                          i.responsavel?.nome,
                      ].join(' '),
                  );
                  return palavras.every((p) => texto.includes(p));
              });

    const porLocalizacao = new Map<string, Internado[]>();
    for (const internado of visiveis) {
        porLocalizacao.set(internado.localizacao, [...(porLocalizacao.get(internado.localizacao) ?? []), internado]);
    }

    const longos = internados.filter((i) => diasEntre(i.data_de_entrada, hoje) > 14).length;
    const sociais = internados.filter((i) => i.caso_social).length;

    const filtrar = (valor: 'A' | 'B' | null) =>
        router.get(rotas.quadro, { equipa: valor ?? 'todas' }, { preserveState: true, preserveScroll: true });

    const equipas: { rotulo: string; valor: 'A' | 'B' | null }[] = [
        { rotulo: 'Todas', valor: null },
        { rotulo: 'Equipa A', valor: 'A' },
        { rotulo: 'Equipa B', valor: 'B' },
    ];

    return (
        <ClinicoLayout
            titulo="Quadro do serviço"
            largura="larga"
            pesquisa={
                <div className="mb-5 max-w-sm">
                    <label htmlFor="filtro-quadro" className="sr-only">
                        Filtrar os doentes internados
                    </label>
                    <input
                        id="filtro-quadro"
                        type="search"
                        value={termo}
                        onChange={(e) => setTermo(e.target.value)}
                        placeholder="Filtrar internados: nome, processo, cama…"
                        className={estiloEntrada}
                        autoComplete="off"
                    />
                </div>
            }
            subtitulo={
                <span className="tabular-nums">
                    {palavras.length > 0
                        ? `${visiveis.length} de ${plural(internados.length, 'doente internado', 'doentes internados')}`
                        : plural(internados.length, 'doente internado', 'doentes internados')}
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
                    <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Tipo de vista">
                        {(
                            [
                                { valor: 'cartoes', rotulo: 'Cartões', Icone: LayoutGrid },
                                { valor: 'tabela', rotulo: 'Tabela', Icone: Rows3 },
                            ] as const
                        ).map(({ valor, rotulo, Icone }) => (
                            <button
                                key={valor}
                                type="button"
                                aria-pressed={vista === valor}
                                onClick={() => mudarVista(valor)}
                                className={`inline-flex items-center gap-1.5 rounded px-3 py-1.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-teal-700 ${
                                    vista === valor ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'
                                }`}
                            >
                                <Icone className="size-4" aria-hidden="true" />
                                {rotulo}
                            </button>
                        ))}
                    </div>
                    <BotaoLigacao href={equipa ? `/quadro/imprimir?equipa=${equipa}` : '/quadro/imprimir'} variante="secundario" novoSeparador>
                        Imprimir lista
                    </BotaoLigacao>
                    {podeCriar && <BotaoLigacao href={rotas.internamentos.novo()}>Registar internamento</BotaoLigacao>}
                </>
            }
        >
            {internados.length > 0 && visiveis.length === 0 ? (
                <div className="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-10 text-center">
                    <p className="text-base font-medium text-slate-900">Nenhum internado corresponde a “{termo}”.</p>
                    <button
                        type="button"
                        onClick={() => setTermo('')}
                        className="mt-2 text-sm font-medium text-teal-800 underline underline-offset-2"
                    >
                        Limpar filtro
                    </button>
                </div>
            ) : internados.length === 0 ? (
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
                            {vista === 'tabela' ? (
                                <Tabela lista={lista} hoje={hoje} equipa={equipa} podeCriar={podeCriar} />
                            ) : (
                                <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                                    {lista.map((internado) => (
                                        <Cama key={internado.id} internado={internado} hoje={hoje} equipa={equipa} podeCriar={podeCriar} />
                                    ))}
                                </ul>
                            )}
                        </section>
                    ))}
                </div>
            )}
            <ModalInternamento modal={modal} />
            <ModalUtente modal={modalUtente} />
            <ModalCirurgia modal={modalCirurgia} />
        </ClinicoLayout>
    );
}

Quadro.layout = (pagina: ReactNode) => pagina;
