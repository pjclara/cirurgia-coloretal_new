import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { formatarData, idade } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import type { Paginado, PropsPartilhadas } from '../../clinico/tipos';
import { Botao, BotaoLigacao, estiloEntrada } from '../../components/clinico/campos';
import { NumeroProcesso } from '../../components/clinico/numero-processo';
import { Paginacao } from '../../components/clinico/paginacao';
import ClinicoLayout from '../../layouts/clinico-layout';

type Linha = {
    id: number;
    nome: string;
    numero_processo: number;
    numero_utente: number | null;
    data_nascimento: string | null;
    sexo: string | null;
    concelho: string | null;
    internamentos: number;
};

export default function Utentes({ utentes, q }: { utentes: Paginado<Linha>; q: string }) {
    const { permissoes } = usePage<PropsPartilhadas>().props.clinico;
    const [termo, setTermo] = useState(q);

    const procurar = (evento: FormEvent) => {
        evento.preventDefault();
        router.get(rotas.utentes.lista, termo.trim() ? { q: termo.trim() } : {}, { preserveState: true });
    };

    return (
        <ClinicoLayout
            titulo="Utentes"
            acoes={permissoes.includes('create internamento') && <BotaoLigacao href={rotas.utentes.novo}>Criar utente</BotaoLigacao>}
        >
            <form onSubmit={procurar} className="mb-4 flex max-w-xl gap-2" role="search">
                <label htmlFor="q" className="sr-only">
                    Procurar por nome, processo ou número de utente
                </label>
                <input
                    id="q"
                    type="search"
                    value={termo}
                    onChange={(e) => setTermo(e.target.value)}
                    placeholder="Nome, processo ou número de utente"
                    className={estiloEntrada}
                />
                <Botao type="submit" variante="secundario">
                    Procurar
                </Botao>
            </form>

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-slate-700">
                        <tr>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Nome
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Processo
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                N.º utente
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Nascimento
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Concelho
                            </th>
                            <th scope="col" className="px-4 py-2.5 text-right font-semibold">
                                Internamentos
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {utentes.data.map((u) => {
                            const anos = idade(u.data_nascimento);
                            return (
                                <tr key={u.id} className="hover:bg-slate-50">
                                    <td className="px-4 py-2.5">
                                        <Link href={rotas.utentes.ficha(u.id)} className="font-medium text-teal-800 hover:underline">
                                            {u.nome}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2.5 tabular-nums">
                                        <NumeroProcesso semRotulo valor={u.numero_processo} />
                                    </td>
                                    <td className="px-4 py-2.5 text-slate-700 tabular-nums">{u.numero_utente ?? '—'}</td>
                                    <td className="px-4 py-2.5 text-slate-700 tabular-nums">
                                        {formatarData(u.data_nascimento)}
                                        {anos !== null && <span className="text-slate-500"> ({anos} anos)</span>}
                                    </td>
                                    <td className="px-4 py-2.5 text-slate-700">{u.concelho ?? '—'}</td>
                                    <td className="px-4 py-2.5 text-right tabular-nums">{u.internamentos}</td>
                                </tr>
                            );
                        })}
                        {utentes.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-10 text-center text-slate-600">
                                    {q ? `Nenhum utente corresponde a "${q}".` : 'Ainda não há utentes.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <Paginacao pagina={utentes} nome="utentes" />
        </ClinicoLayout>
    );
}

Utentes.layout = (pagina: ReactNode) => pagina;
