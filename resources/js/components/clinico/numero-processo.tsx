import { useState, type KeyboardEvent, type MouseEvent } from 'react';

async function copiar(texto: string) {
    try {
        await navigator.clipboard.writeText(texto);
    } catch {
        // Sem permissão para a área de transferência (ex.: http): copia por seleção.
        const campo = document.createElement('textarea');
        campo.value = texto;
        campo.style.position = 'fixed';
        campo.style.opacity = '0';
        document.body.appendChild(campo);
        campo.select();
        document.execCommand('copy');
        campo.remove();
    }
}

type Props = { valor: number | string | null | undefined; semRotulo?: boolean; vazio?: string };

/** Número de processo: ao clicar copia-o para a área de transferência, para colar noutro sítio. */
export function NumeroProcesso({ valor, semRotulo = false, vazio = '—' }: Props) {
    const [copiado, setCopiado] = useState(false);
    const texto = valor === null || valor === undefined || valor === '' ? null : String(valor);

    if (texto === null) return <>{semRotulo ? vazio : `Processo ${vazio}`}</>;

    const acao = async (e: MouseEvent | KeyboardEvent) => {
        e.preventDefault();
        e.stopPropagation();
        await copiar(texto);
        setCopiado(true);
        setTimeout(() => setCopiado(false), 1500);
    };

    return (
        <span
            role="button"
            tabIndex={0}
            onClick={acao}
            onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && acao(e)}
            title={copiado ? 'Copiado' : 'Clique para copiar o número de processo'}
            aria-label={`${semRotulo ? '' : 'Processo '}${texto}: copiar`}
            className="cursor-copy rounded hover:bg-slate-100 hover:underline focus-visible:outline-2 focus-visible:outline-teal-700"
        >
            {semRotulo ? texto : `Processo ${texto}`}
            {copiado && <span className="ml-1 text-xs font-medium text-teal-800">copiado</span>}
        </span>
    );
}
