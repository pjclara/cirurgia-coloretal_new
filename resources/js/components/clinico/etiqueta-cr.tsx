/** Etiqueta «CR»: o doente está referenciado ao centro de referência. */
export function EtiquetaCr({ className = 'ml-2' }: { className?: string }) {
    return (
        <span
            className={`${className} rounded bg-violet-100 px-1.5 py-0.5 text-xs font-semibold text-violet-900`}
            title="Doente referenciado ao centro de referência"
        >
            CR
        </span>
    );
}
