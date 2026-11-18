import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Botao, BotaoLigacao } from '../../components/clinico/campos';
import ClinicoLayout from '../../layouts/clinico-layout';

type Permissao = { nome: string; descricao: string };
type Grupo = { rotulo: string; permissoes: Permissao[] };
type Perfil = { nome: string; utilizadores: number; bloqueado: boolean; permissoes: string[] };

type Props = { grupos: Grupo[]; perfis: Perfil[]; semEfeito: string[] };

/** «show X» da mesma área da permissão, ex.: «update internamento» -> «show internamento». */
const verDaArea = (permissao: string) => `show ${permissao.split(' ')[1]}`;

export default function Perfis({ grupos, perfis, semEfeito }: Props) {
    const inicial = () => Object.fromEntries(perfis.map((p) => [p.nome, new Set(p.permissoes)]));
    const [escolhas, setEscolhas] = useState<Record<string, Set<string>>>(inicial);
    const [aGuardar, setAGuardar] = useState(false);

    const alterado = perfis.some((p) => {
        const atual = escolhas[p.nome];
        return atual.size !== p.permissoes.length || p.permissoes.some((x) => !atual.has(x));
    });

    const alternar = (perfil: string, permissao: string) =>
        setEscolhas((antes) => {
            const conjunto = new Set(antes[perfil]);
            if (conjunto.has(permissao)) {
                conjunto.delete(permissao);
                // Sem «ver», nada mais naquela área.
                if (permissao.startsWith('show ')) {
                    const area = permissao.split(' ')[1];
                    [...conjunto].filter((x) => x.endsWith(` ${area}`)).forEach((x) => conjunto.delete(x));
                }
            } else {
                conjunto.add(permissao);
                conjunto.add(verDaArea(permissao));
            }
            return { ...antes, [perfil]: conjunto };
        });

    const guardar = () => {
        const corpo = Object.fromEntries(perfis.filter((p) => !p.bloqueado).map((p) => [p.nome, [...escolhas[p.nome]]]));
        router.put(
            '/admin/perfis',
            { perfis: corpo },
            { preserveScroll: true, onStart: () => setAGuardar(true), onFinish: () => setAGuardar(false) },
        );
    };

    return (
        <ClinicoLayout
            titulo="Administração: perfis e permissões"
            subtitulo="O que cada perfil pode fazer. As alterações aplicam-se a todos os utilizadores do perfil."
            acoes={
                <BotaoLigacao href="/admin/utilizadores" variante="secundario">
                    Utilizadores
                </BotaoLigacao>
            }
            largura="larga"
        >
            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-600">
                        <tr>
                            <th className="px-4 py-2.5 font-medium">Permissão</th>
                            {perfis.map((p) => (
                                <th key={p.nome} className="px-3 py-2.5 text-center font-medium">
                                    <span className="block text-slate-900">{p.nome}</span>
                                    <span className="block font-normal tabular-nums">
                                        {p.utilizadores} utilizador{p.utilizadores === 1 ? '' : 'es'}
                                    </span>
                                    {p.bloqueado && <span className="block font-normal text-slate-500">bloqueado</span>}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {grupos.map((g) => (
                            <FragmentoGrupo key={g.rotulo} grupo={g} perfis={perfis} escolhas={escolhas} alternar={alternar} />
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="mt-4 flex flex-wrap items-center gap-3">
                <Botao type="button" onClick={guardar} disabled={!alterado || aGuardar}>
                    {aGuardar ? 'A guardar…' : 'Guardar alterações'}
                </Botao>
                {alterado && (
                    <Botao type="button" variante="secundario" onClick={() => setEscolhas(inicial())}>
                        Desfazer
                    </Botao>
                )}
                <p className="text-xs text-slate-600">
                    Quem pode fazer algo numa área precisa de «ver» essa área: ao marcar uma permissão, «ver» é marcado também. Os perfis de
                    administração têm sempre tudo e não se alteram aqui.
                </p>
            </div>

            {semEfeito.length > 0 && (
                <p className="mt-6 max-w-3xl text-xs text-slate-500">
                    Permissões que a aplicação já não verifica e por isso não têm efeito: {semEfeito.join(', ')}.
                </p>
            )}
        </ClinicoLayout>
    );
}

function FragmentoGrupo({
    grupo,
    perfis,
    escolhas,
    alternar,
}: {
    grupo: Grupo;
    perfis: Perfil[];
    escolhas: Record<string, Set<string>>;
    alternar: (perfil: string, permissao: string) => void;
}) {
    return (
        <>
            <tr className="border-y border-slate-200 bg-slate-50">
                <th colSpan={perfis.length + 1} className="px-4 py-1.5 text-xs font-semibold tracking-wide text-slate-600 uppercase">
                    {grupo.rotulo}
                </th>
            </tr>
            {grupo.permissoes.map((perm) => (
                <tr key={perm.nome} className="border-b border-slate-100">
                    <td className="px-4 py-2">
                        <span className="font-medium">{perm.nome}</span>
                        <span className="block max-w-md text-xs text-slate-600">{perm.descricao}</span>
                    </td>
                    {perfis.map((p) => (
                        <td key={p.nome} className="px-3 py-2 text-center">
                            <input
                                type="checkbox"
                                checked={p.bloqueado || escolhas[p.nome].has(perm.nome)}
                                disabled={p.bloqueado}
                                onChange={() => alternar(p.nome, perm.nome)}
                                aria-label={`${perm.nome} para ${p.nome}`}
                            />
                        </td>
                    ))}
                </tr>
            ))}
        </>
    );
}

Perfis.layout = (pagina: React.ReactNode) => pagina;
