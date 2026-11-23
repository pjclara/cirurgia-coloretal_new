import { useState, type KeyboardEvent, type MouseEvent } from 'react';

/**
 * Copia por seleção (execCommand): serve onde a área de transferência moderna não existe, como em páginas http da intranet
 * ou em browsers antigos. O campo temporário fica dentro da janela aberta (se houver), senão o foco preso dela impede a seleção.
 */
function copiarPorSelecao(texto: string, origem: HTMLElement): boolean {
    const contentor = origem.closest<HTMLElement>('[role="dialog"]') ?? document.body;
    const campo = document.createElement('textarea');
    campo.value = texto;
    campo.readOnly = true;
    campo.setAttribute('aria-hidden', 'true');
    campo.style.position = 'fixed';
    campo.style.top = '0';
    campo.style.left = '0';
    campo.style.opacity = '0';
    contentor.appendChild(campo);
    let copiado = false;
    try {
        campo.focus({ preventScroll: true });
        campo.select();
        campo.setSelectionRange(0, texto.length);
        copiado = document.execCommand('copy');
    } catch {
        copiado = false;
    }
    campo.remove();
    origem.focus({ preventScroll: true });

    return copiado;
}

/** Copia o texto; devolve false se nenhum método resultou (o número fica selecionado para Ctrl+C). */
async function copiar(texto: string, origem: HTMLElement): Promise<boolean> {
    // Sem área de transferência moderna (http, browsers antigos) copia-se logo por seleção, ainda dentro do clique.
    if (!window.isSecureContext || !navigator.clipboard) {
        return copiarPorSelecao(texto, origem);
    }
    try {
        await navigator.clipboard.writeText(texto);

        return true;
    } catch {
        return copiarPorSelecao(texto, origem);
    }
}

type Props = { valor: number | string | null | undefined; semRotulo?: boolean; vazio?: string };

/** Número de processo: ao clicar copia-o para a área de transferência, para colar noutro sítio. */
export function NumeroProcesso({ valor, semRotulo = false, vazio = '—' }: Props) {
    const [copiado, setCopiado] = useState<'sim' | 'nao' | null>(null);
    const texto = valor === null || valor === undefined || valor === '' ? null : String(valor);

    if (texto === null) return <>{semRotulo ? vazio : `Processo ${vazio}`}</>;

    const acao = async (e: MouseEvent | KeyboardEvent) => {
        e.preventDefault();
        e.stopPropagation();
        const origem = e.currentTarget as HTMLElement;
        const resultou = await copiar(texto, origem);
        if (!resultou) window.getSelection()?.selectAllChildren(origem); // fica selecionado: Ctrl+C
        setCopiado(resultou ? 'sim' : 'nao');
        setTimeout(() => setCopiado(null), resultou ? 1500 : 4000);
    };

    return (
        <span
            role="button"
            tabIndex={0}
            onClick={acao}
            onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && acao(e)}
            title={copiado === 'sim' ? 'Copiado' : 'Clique para copiar o número de processo'}
            aria-label={`${semRotulo ? '' : 'Processo '}${texto}: copiar`}
            className="cursor-copy rounded hover:bg-slate-100 hover:underline focus-visible:outline-2 focus-visible:outline-teal-700"
        >
            {semRotulo ? texto : `Processo ${texto}`}
            {copiado === 'sim' && <span className="ml-1 text-xs font-medium text-teal-800">copiado</span>}
            {copiado === 'nao' && <span className="ml-1 text-xs font-medium text-amber-800">selecionado: prima Ctrl+C</span>}
        </span>
    );
}
