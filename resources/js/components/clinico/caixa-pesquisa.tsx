import { type FormEvent } from 'react';
import { Botao, estiloEntrada } from './campos';

type Props = {
    id: string;
    /** Texto escrito (estado da página, para poder juntar-se aos outros filtros). */
    termo: string;
    aoEscrever: (termo: string) => void;
    aoPesquisar: () => void;
    /** Rótulo para leitores de ecrã. */
    rotulo: string;
    placeholder: string;
    /** Sem botão (Enter pesquisa) e mais pequeno, para painéis estreitos. */
    compacto?: boolean;
    className?: string;
};

/** Caixa «procurar por nome ou processo» das listas: campo de pesquisa e botão Procurar. */
export function CaixaPesquisa({ id, termo, aoEscrever, aoPesquisar, rotulo, placeholder, compacto, className = '' }: Props) {
    const submeter = (e: FormEvent) => {
        e.preventDefault();
        aoPesquisar();
    };

    return (
        <form onSubmit={submeter} role="search" className={`flex gap-2 ${className}`}>
            <label htmlFor={id} className="sr-only">
                {rotulo}
            </label>
            <input
                id={id}
                type="search"
                value={termo}
                onChange={(e) => aoEscrever(e.target.value)}
                placeholder={placeholder}
                className={`${estiloEntrada} ${compacto ? 'py-1.5 text-xs' : ''}`}
            />
            {!compacto && (
                <Botao type="submit" variante="secundario">
                    Procurar
                </Botao>
            )}
        </form>
    );
}
