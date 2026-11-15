import { Link, router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { formatarData } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import type { ResultadoPesquisa } from '../../clinico/tipos';
import { estiloEntrada } from './campos';

type Props = {
    /** Sem esta função, escolher um resultado abre a ficha do utente. */
    aoEscolher?: (utente: ResultadoPesquisa) => void;
    placeholder?: string;
    podeCriar?: boolean;
    /** Destino de "Criar utente novo" (por omissão, a criação do utente). */
    criarHref?: string;
    focoInicial?: boolean;
};

/** Pesquisa de utentes por nome, n.º de processo ou n.º de utente. */
export function PesquisaUtente({ aoEscolher, placeholder = 'Procurar utente por nome ou processo', podeCriar, criarHref, focoInicial }: Props) {
    const [termo, setTermo] = useState('');
    const [resultados, setResultados] = useState<ResultadoPesquisa[] | null>(null);
    const [aberto, setAberto] = useState(false);
    const [ativo, setAtivo] = useState(0);
    const [falhou, setFalhou] = useState(false);
    const caixa = useRef<HTMLDivElement>(null);
    const idLista = useId();

    useEffect(() => {
        const procurado = termo.trim();
        if (procurado.length < 2) {
            setResultados(null);
            return;
        }

        const controlo = new AbortController();
        const espera = setTimeout(() => {
            fetch(`${rotas.utentes.pesquisa}?q=${encodeURIComponent(procurado)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: controlo.signal,
            })
                .then((resposta) => {
                    if (!resposta.ok) throw new Error(String(resposta.status));
                    return resposta.json() as Promise<ResultadoPesquisa[]>;
                })
                .then((dados) => {
                    setResultados(dados);
                    setAtivo(0);
                    setFalhou(false);
                })
                .catch((erro: unknown) => {
                    if (erro instanceof DOMException && erro.name === 'AbortError') return;
                    setFalhou(true);
                    setResultados([]);
                });
        }, 250);

        return () => {
            clearTimeout(espera);
            controlo.abort();
        };
    }, [termo]);

    useEffect(() => {
        const fechar = (evento: MouseEvent) => {
            if (caixa.current && !caixa.current.contains(evento.target as Node)) setAberto(false);
        };
        document.addEventListener('mousedown', fechar);
        return () => document.removeEventListener('mousedown', fechar);
    }, []);

    const escolher = (utente: ResultadoPesquisa) => {
        setAberto(false);
        setTermo('');
        setResultados(null);
        if (aoEscolher) aoEscolher(utente);
        else router.visit(rotas.utentes.ficha(utente.id));
    };

    const soDigitos = /^\d+$/.test(termo.trim());
    const mostrar = aberto && termo.trim().length >= 2 && resultados !== null;

    return (
        <div ref={caixa} className="relative">
            <input
                type="search"
                role="combobox"
                aria-expanded={mostrar}
                aria-controls={idLista}
                aria-label={placeholder}
                autoFocus={focoInicial}
                value={termo}
                placeholder={placeholder}
                className={estiloEntrada}
                onFocus={() => setAberto(true)}
                onChange={(e) => {
                    setTermo(e.target.value);
                    setAberto(true);
                }}
                onKeyDown={(e) => {
                    if (!resultados?.length) return;
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        setAtivo((i) => Math.min(i + 1, resultados.length - 1));
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        setAtivo((i) => Math.max(i - 1, 0));
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        escolher(resultados[ativo]);
                    } else if (e.key === 'Escape') {
                        setAberto(false);
                    }
                }}
            />

            {mostrar && (
                <div
                    id={idLista}
                    role="listbox"
                    className="absolute z-30 mt-1 max-h-80 w-full min-w-72 overflow-y-auto rounded-md border border-slate-200 bg-white shadow-lg"
                >
                    {resultados.map((utente, indice) => (
                        <button
                            key={utente.id}
                            type="button"
                            role="option"
                            aria-selected={indice === ativo}
                            onMouseEnter={() => setAtivo(indice)}
                            onClick={() => escolher(utente)}
                            className={`flex w-full items-baseline justify-between gap-3 px-3 py-2 text-left text-sm ${
                                indice === ativo ? 'bg-teal-50' : 'bg-white'
                            }`}
                        >
                            <span className="min-w-0">
                                <span className="block truncate font-medium text-slate-900">{utente.nome}</span>
                                <span className="text-xs text-slate-600 tabular-nums">
                                    Processo {utente.numero_processo}, nasc. {formatarData(utente.data_nascimento)}
                                </span>
                            </span>
                            <span className="flex shrink-0 gap-1">
                                {utente.referenciado && (
                                    <span className="rounded bg-violet-100 px-1.5 py-0.5 text-xs font-medium text-violet-900">Referenciado</span>
                                )}
                                {utente.internamento_aberto_id && (
                                    <span className="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900">Internado</span>
                                )}
                            </span>
                        </button>
                    ))}

                    {resultados.length === 0 && (
                        <p className="px-3 py-2 text-sm text-slate-600">
                            {falhou ? 'A pesquisa falhou. Tente outra vez.' : 'Nenhum utente encontrado.'}
                        </p>
                    )}

                    {podeCriar && (
                        <Link
                            href={
                                soDigitos
                                    ? `${criarHref ?? rotas.utentes.novo}${criarHref?.includes('?') ? '&' : '?'}processo=${termo.trim()}`
                                    : (criarHref ?? rotas.utentes.novo)
                            }
                            className="block border-t border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-teal-800 hover:bg-slate-100"
                        >
                            Criar utente novo
                        </Link>
                    )}
                </div>
            )}
        </div>
    );
}
