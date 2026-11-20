import { Link } from '@inertiajs/react';
import type { ButtonHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react';
import type { Opcao } from '../../clinico/tipos';

export const estiloEntrada =
    'block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-xs ' +
    'placeholder:text-slate-400 focus:border-teal-700 focus:outline-2 focus:outline-offset-1 focus:outline-teal-700 ' +
    'disabled:bg-slate-100 disabled:text-slate-600 aria-[invalid=true]:border-rose-600';

type PropsCampo = {
    rotulo: string;
    para?: string;
    erro?: string;
    ajuda?: string;
    opcional?: boolean;
    className?: string;
    children: ReactNode;
};

export function Campo({ rotulo, para, erro, ajuda, opcional, className = '', children }: PropsCampo) {
    return (
        <div className={className}>
            <label htmlFor={para} className="mb-1 block text-sm font-medium text-slate-800">
                {rotulo}
                {opcional && <span className="ml-1 font-normal text-slate-500">(opcional)</span>}
            </label>
            {children}
            {ajuda && !erro && <p className="mt-1 text-xs text-slate-500">{ajuda}</p>}
            {erro && (
                <p className="mt-1 text-sm text-rose-700" role="alert">
                    {erro}
                </p>
            )}
        </div>
    );
}

type PropsSelecao = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'value' | 'onChange'> & {
    opcoes: Opcao[];
    valor: number | '';
    aoMudar: (valor: number | '') => void;
    vazio?: string;
    invalido?: boolean;
};

export function Selecao({ opcoes, valor, aoMudar, vazio = 'Escolher…', invalido, className = '', ...resto }: PropsSelecao) {
    return (
        <select
            {...resto}
            className={`${estiloEntrada} ${className}`}
            aria-invalid={invalido || undefined}
            value={valor === '' ? '' : String(valor)}
            onChange={(e) => aoMudar(e.target.value === '' ? '' : Number(e.target.value))}
        >
            <option value="">{vazio}</option>
            {opcoes.map((o) => (
                <option key={o.id} value={o.id}>
                    {o.nome}
                </option>
            ))}
        </select>
    );
}

const variantes = {
    primario: 'bg-teal-800 text-white hover:bg-teal-900 disabled:bg-teal-800/50',
    secundario: 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50 disabled:text-slate-400',
    perigo: 'border border-rose-300 bg-white text-rose-700 hover:bg-rose-50',
};

const estiloBotao =
    'inline-flex items-center justify-center gap-2 rounded-md px-3.5 py-2 text-sm font-medium ' +
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:cursor-not-allowed';

type Variante = keyof typeof variantes;

export function Botao({
    variante = 'primario',
    className = '',
    type = 'button',
    ...resto
}: ButtonHTMLAttributes<HTMLButtonElement> & { variante?: Variante }) {
    return <button type={type} className={`${estiloBotao} ${variantes[variante]} ${className}`} {...resto} />;
}

export function BotaoLigacao({
    href,
    variante = 'primario',
    className = '',
    novoSeparador = false,
    children,
}: {
    href: string;
    variante?: Variante;
    className?: string;
    /** Abre a página noutro separador do browser (por exemplo, páginas para imprimir). */
    novoSeparador?: boolean;
    children: ReactNode;
}) {
    const classes = `${estiloBotao} ${variantes[variante]} ${className}`;
    if (novoSeparador) {
        return (
            <a href={href} target="_blank" rel="noopener noreferrer" className={classes}>
                {children}
            </a>
        );
    }
    return (
        <Link href={href} className={classes}>
            {children}
        </Link>
    );
}

export function Seccao({ titulo, descricao, tour, children }: { titulo: string; descricao?: string; tour?: string; children: ReactNode }) {
    return (
        <section data-tour={tour} className="rounded-lg border border-slate-200 bg-white">
            <header className="border-b border-slate-200 px-5 py-3">
                <h2 className="text-base font-semibold text-slate-900">{titulo}</h2>
                {descricao && <p className="mt-0.5 text-sm text-slate-600">{descricao}</p>}
            </header>
            <div className="px-5 py-4">{children}</div>
        </section>
    );
}

type OpcaoResponsavel = { id: number; nome: string; equipa: string | null; perfil?: string | null };

/** Equipas primeiro (A, B) e, a seguir, quem não tem equipa agrupado por perfil. */
function gruposResponsaveis(lista: OpcaoResponsavel[]): [string, OpcaoResponsavel[]][] {
    const grupos = new Map<string, OpcaoResponsavel[]>();
    const add = (g: string, r: OpcaoResponsavel) => grupos.set(g, [...(grupos.get(g) ?? []), r]);
    for (const e of ['A', 'B']) lista.filter((r) => r.equipa === e).forEach((r) => add(`Equipa ${e}`, r));
    lista.filter((r) => r.equipa !== 'A' && r.equipa !== 'B').forEach((r) => add(r.perfil ? `Perfil: ${r.perfil}` : 'Sem equipa nem perfil', r));
    return [...grupos.entries()];
}

type PropsResponsavel = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'value' | 'onChange'> & {
    responsaveis: OpcaoResponsavel[];
    valor: number | '';
    aoMudar: (valor: number | '') => void;
    vazio?: string;
    invalido?: boolean;
};

/** Lista de responsáveis agrupada por equipa e, depois, por perfil. */
export function SelecaoResponsavel({ responsaveis, valor, aoMudar, vazio = 'Escolher…', invalido, className = '', ...resto }: PropsResponsavel) {
    return (
        <select
            {...resto}
            className={`${estiloEntrada} ${className}`}
            aria-invalid={invalido || undefined}
            value={valor === '' ? '' : String(valor)}
            onChange={(e) => aoMudar(e.target.value === '' ? '' : Number(e.target.value))}
        >
            <option value="">{vazio}</option>
            {gruposResponsaveis(responsaveis).map(([grupo, lista]) => (
                <optgroup key={grupo} label={grupo}>
                    {lista.map((r) => (
                        <option key={r.id} value={r.id}>
                            {r.nome}
                        </option>
                    ))}
                </optgroup>
            ))}
        </select>
    );
}
