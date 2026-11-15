import { useId, useMemo, useState } from 'react';
import type { OpcaoAgrupada } from '../../clinico/tipos';
import { estiloEntrada } from './campos';

type Props = {
    opcoes: OpcaoAgrupada[];
    valor: number[];
    aoMudar: (valor: number[]) => void;
    procurar: string;
    desativado?: boolean;
    vazio?: string;
    /** Classe de altura máxima da lista (por omissão `max-h-56`). */
    alturaLista?: string;
};

/**
 * Lista pesquisável com caixas de verificação, agrupada (ex.: diagnósticos por grupo).
 * As opções escolhidas ficam sempre visíveis no topo.
 */
export function EscolhaMultipla({ opcoes, valor, aoMudar, procurar, desativado, vazio = 'Nada escolhido.', alturaLista = 'max-h-56' }: Props) {
    const [termo, setTermo] = useState('');
    const idPesquisa = useId();

    const semAcentos = (texto: string) => texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    const grupos = useMemo(() => {
        const procurado = semAcentos(termo.trim());
        const filtradas = procurado ? opcoes.filter((o) => semAcentos(o.nome).includes(procurado)) : opcoes;
        const mapa = new Map<string, OpcaoAgrupada[]>();
        for (const opcao of filtradas) {
            mapa.set(opcao.grupo, [...(mapa.get(opcao.grupo) ?? []), opcao]);
        }
        return [...mapa.entries()];
    }, [opcoes, termo]);

    const escolhidas = opcoes.filter((o) => valor.includes(o.id));

    const alternar = (id: number) => aoMudar(valor.includes(id) ? valor.filter((v) => v !== id) : [...valor, id]);

    return (
        <div>
            {escolhidas.length === 0 ? (
                <p className="mb-2 text-sm text-slate-500">{vazio}</p>
            ) : (
                <ul className="mb-2 flex flex-wrap gap-1.5">
                    {escolhidas.map((o) => (
                        <li
                            key={o.id}
                            className="inline-flex items-center gap-1 rounded-md bg-teal-50 py-1 pr-1 pl-2.5 text-sm text-teal-900 ring-1 ring-teal-200"
                        >
                            {o.nome}
                            {!desativado && (
                                <button
                                    type="button"
                                    onClick={() => alternar(o.id)}
                                    className="rounded px-1 text-teal-800 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-teal-700"
                                    aria-label={`Retirar ${o.nome}`}
                                >
                                    ×
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {!desativado && (
                <>
                    <label htmlFor={idPesquisa} className="sr-only">
                        {procurar}
                    </label>
                    <input
                        id={idPesquisa}
                        type="search"
                        value={termo}
                        onChange={(e) => setTermo(e.target.value)}
                        placeholder={procurar}
                        className={estiloEntrada}
                    />
                    <div className={`mt-2 ${alturaLista} overflow-y-auto rounded-md border border-slate-200`}>
                        {grupos.length === 0 && <p className="px-3 py-2 text-sm text-slate-500">Nenhuma opção com esse nome.</p>}
                        {grupos.map(([grupo, lista]) => (
                            <fieldset key={grupo} className="border-b border-slate-100 last:border-b-0">
                                <legend className="w-full bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">{grupo}</legend>
                                {lista.map((o) => (
                                    <label
                                        key={o.id}
                                        className="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm text-slate-800 hover:bg-slate-50"
                                    >
                                        <input
                                            type="checkbox"
                                            className="size-4 accent-teal-800"
                                            checked={valor.includes(o.id)}
                                            onChange={() => alternar(o.id)}
                                        />
                                        {o.nome}
                                    </label>
                                ))}
                            </fieldset>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}
