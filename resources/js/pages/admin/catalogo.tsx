import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import type { Opcao, Paginado } from '../../clinico/tipos';
import { CaixaPesquisa } from '../../components/clinico/caixa-pesquisa';
import { Botao, Campo, Selecao, estiloEntrada } from '../../components/clinico/campos';
import { Paginacao } from '../../components/clinico/paginacao';
import ClinicoLayout from '../../layouts/clinico-layout';

type Item = {
    id: number;
    nome: string;
    abrev: string;
    grupo_id: number | null;
    grupo: string | null;
    tipo_id: number | null;
    centro_de_referencia: boolean;
    cirurgia_de_ressecao: boolean;
    usos: number;
    protegido: boolean;
};

type Props = {
    catalogo: {
        chave: string;
        titulo: string;
        singular: string;
        temTipo: boolean;
        temRessecao: boolean;
        temAbrev: boolean;
        temCr: boolean;
        rotuloGrupo: string;
        grupoOpcional: boolean;
        aviso: string | null;
    };
    itens: Paginado<Item>;
    filtros: { q: string; grupo: number | null; cr: boolean };
    grupos: Opcao[];
    tipos: Opcao[];
};

/** Formulário de um registo do catálogo, em modal (novo ou a editar). */
function FormularioItem({
    catalogo,
    item,
    grupos,
    tipos,
    fechar,
}: {
    catalogo: Props['catalogo'];
    item: Item | null;
    grupos: Opcao[];
    tipos: Opcao[];
    fechar: () => void;
}) {
    const form = useForm({
        nome: item?.nome ?? '',
        abrev: item?.abrev ?? '',
        grupo_id: (item?.grupo_id ?? '') as number | '',
        tipo_id: (item?.tipo_id ?? '') as number | '',
        centro_de_referencia: item?.centro_de_referencia ?? false,
        cirurgia_de_ressecao: item?.cirurgia_de_ressecao ?? false,
    });
    const { data, setData, errors, processing } = form;
    const url = `/admin/catalogos/${catalogo.chave}${item ? `/${item.id}` : ''}`;

    const guardar = (e: FormEvent) => {
        e.preventDefault();
        const opcoes = { preserveScroll: true, onSuccess: fechar };
        if (item) form.put(url, opcoes);
        else form.post(url, opcoes);
    };

    return (
        <form onSubmit={guardar} className="space-y-4" noValidate>
            <Campo rotulo="Nome" para="nome" erro={errors.nome}>
                <input
                    id="nome"
                    className={estiloEntrada}
                    value={data.nome}
                    onChange={(e) => setData('nome', e.target.value)}
                    aria-invalid={!!errors.nome}
                    autoFocus
                />
            </Campo>
            <div className="grid gap-4 sm:grid-cols-2">
                {catalogo.temAbrev && (
                    <Campo rotulo="Abreviatura" para="abrev" erro={errors.abrev} opcional>
                        <input id="abrev" className={estiloEntrada} value={data.abrev} onChange={(e) => setData('abrev', e.target.value)} />
                    </Campo>
                )}
                <Campo rotulo={catalogo.rotuloGrupo} para="grupo" erro={errors.grupo_id} opcional={catalogo.grupoOpcional}>
                    <Selecao
                        id="grupo"
                        opcoes={grupos}
                        valor={data.grupo_id}
                        aoMudar={(v) => setData('grupo_id', v)}
                        invalido={!!errors.grupo_id}
                        vazio={catalogo.grupoOpcional ? 'Sem grau' : 'Escolher…'}
                    />
                </Campo>
            </div>
            {catalogo.temTipo && (
                <Campo rotulo="Tipo" para="tipo" erro={errors.tipo_id}>
                    <Selecao id="tipo" opcoes={tipos} valor={data.tipo_id} aoMudar={(v) => setData('tipo_id', v)} invalido={!!errors.tipo_id} />
                </Campo>
            )}
            <div className="space-y-2 text-sm">
                {catalogo.temCr && (
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={data.centro_de_referencia}
                            onChange={(e) => setData('centro_de_referencia', e.target.checked)}
                        />
                        Do centro de referência
                    </label>
                )}
                {catalogo.temRessecao && (
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={data.cirurgia_de_ressecao}
                            onChange={(e) => setData('cirurgia_de_ressecao', e.target.checked)}
                        />
                        Cirurgia de ressecção
                    </label>
                )}
            </div>
            <div className="flex gap-2 border-t border-slate-200 pt-3">
                <Botao type="submit" disabled={processing}>
                    {item ? 'Guardar alterações' : `Criar ${catalogo.singular}`}
                </Botao>
                <Botao variante="secundario" onClick={fechar}>
                    Cancelar
                </Botao>
            </div>
        </form>
    );
}

export default function Catalogo({ catalogo, itens, filtros, grupos, tipos }: Props) {
    const [termo, setTermo] = useState(filtros.q);
    const [aEditar, setAEditar] = useState<Item | 'novo' | null>(null);
    const [aApagar, setAApagar] = useState<number | null>(null);
    const base = `/admin/catalogos/${catalogo.chave}`;

    const filtrar = (novos: Partial<Props['filtros']>) => {
        const f = { ...filtros, q: termo.trim(), ...novos };
        router.get(
            base,
            { ...(f.q ? { q: f.q } : {}), ...(f.grupo ? { grupo: f.grupo } : {}), ...(f.cr ? { cr: 1 } : {}) },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <ClinicoLayout
            titulo={`Administração: ${catalogo.titulo.toLowerCase()}`}
            subtitulo={`${itens.total} ${catalogo.titulo.toLowerCase()}`}
            acoes={<Botao onClick={() => setAEditar('novo')}>Novo {catalogo.singular}</Botao>}
            largura="larga"
        >
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <CaixaPesquisa
                    id="q"
                    termo={termo}
                    aoEscrever={setTermo}
                    aoPesquisar={() => filtrar({})}
                    rotulo={`Procurar ${catalogo.singular} por nome${catalogo.temAbrev ? ' ou abreviatura' : ''}`}
                    placeholder={catalogo.temAbrev ? 'Nome ou abreviatura' : 'Nome'}
                    className="min-w-64 sm:max-w-sm"
                />
                <div className="w-56">
                    <Selecao
                        aria-label={catalogo.rotuloGrupo}
                        opcoes={grupos}
                        valor={filtros.grupo ?? ''}
                        aoMudar={(v) => filtrar({ grupo: v === '' ? null : v })}
                        vazio={catalogo.temAbrev ? 'Todos os grupos' : 'Todos os graus'}
                    />
                </div>
                {catalogo.temCr && (
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={filtros.cr} onChange={(e) => filtrar({ cr: e.target.checked })} />
                        Só do centro de referência
                    </label>
                )}
            </div>

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-600 uppercase">
                        <tr>
                            <th className="px-4 py-2.5 font-medium">Nome</th>
                            {catalogo.temAbrev && <th className="px-4 py-2.5 font-medium">Abreviatura</th>}
                            <th className="px-4 py-2.5 font-medium">{catalogo.rotuloGrupo}</th>
                            {catalogo.temCr && <th className="px-4 py-2.5 font-medium">CR</th>}
                            {catalogo.temRessecao && <th className="px-4 py-2.5 font-medium">Ressecção</th>}
                            <th className="px-4 py-2.5 font-medium">Em uso</th>
                            <th className="px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {itens.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-8 text-center text-slate-500">
                                    Nenhum registo encontrado.
                                </td>
                            </tr>
                        )}
                        {itens.data.map((i) => (
                            <tr key={i.id}>
                                <td className="px-4 py-2 font-medium">{i.nome}</td>
                                {catalogo.temAbrev && <td className="px-4 py-2 text-slate-600">{i.abrev || '—'}</td>}
                                <td className="px-4 py-2 text-slate-600">{i.grupo ?? '—'}</td>
                                {catalogo.temCr && (
                                    <td className="px-4 py-2">
                                        {i.centro_de_referencia ? (
                                            <span className="rounded bg-violet-100 px-1.5 py-0.5 text-xs font-semibold text-violet-900">CR</span>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                )}
                                {catalogo.temRessecao && <td className="px-4 py-2">{i.cirurgia_de_ressecao ? 'Sim' : '—'}</td>}
                                <td className="px-4 py-2 tabular-nums">{i.usos > 0 ? i.usos : '—'}</td>
                                <td className="px-4 py-2 text-right whitespace-nowrap">
                                    <button type="button" onClick={() => setAEditar(i)} className="font-medium text-[#7d1830] hover:underline">
                                        Editar
                                    </button>
                                    {aApagar === i.id ? (
                                        <span className="ml-3">
                                            Apagar?{' '}
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.delete(`${base}/${i.id}`, { preserveScroll: true, onFinish: () => setAApagar(null) })
                                                }
                                                className="font-medium text-rose-800 hover:underline"
                                            >
                                                Sim
                                            </button>{' '}
                                            ·{' '}
                                            <button type="button" onClick={() => setAApagar(null)} className="hover:underline">
                                                Não
                                            </button>
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => setAApagar(i.id)}
                                            disabled={i.usos > 0 || i.protegido}
                                            title={
                                                i.protegido ? 'Usado pela própria aplicação' : i.usos > 0 ? 'Em uso: não se pode apagar' : undefined
                                            }
                                            className="ml-3 text-slate-700 hover:underline disabled:cursor-not-allowed disabled:text-slate-400 disabled:no-underline"
                                        >
                                            Apagar
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <Paginacao pagina={itens} nome={catalogo.titulo.toLowerCase()} />
            </div>

            <Dialog open={aEditar !== null} onOpenChange={(aberto) => !aberto && setAEditar(null)}>
                <DialogContent className="max-w-xl bg-white text-slate-900" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>{aEditar === 'novo' ? `Novo ${catalogo.singular}` : `Editar ${catalogo.singular}`}</DialogTitle>
                        <DialogDescription>
                            Os registos em uso não se apagam; editam-se aqui.
                            {catalogo.aviso && <span className="mt-1 block font-medium text-amber-900">{catalogo.aviso}</span>}
                        </DialogDescription>
                    </DialogHeader>
                    {aEditar !== null && (
                        <FormularioItem
                            key={aEditar === 'novo' ? 'novo' : aEditar.id}
                            catalogo={catalogo}
                            item={aEditar === 'novo' ? null : aEditar}
                            grupos={grupos}
                            tipos={tipos}
                            fechar={() => setAEditar(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </ClinicoLayout>
    );
}

Catalogo.layout = (pagina: React.ReactNode) => pagina;
