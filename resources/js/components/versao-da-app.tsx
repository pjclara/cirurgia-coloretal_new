import { usePage } from '@inertiajs/react';

/** Versão instalada (commit e data da construção), para confirmar que o servidor já atualizou. */
export function VersaoDaApp({ className = '' }: { className?: string }) {
    const { versao } = usePage<{ versao?: { codigo: string; data: string | null } }>().props;
    if (!versao) return null;

    return (
        <p className={`text-muted-foreground text-xs tabular-nums ${className}`} title={versao.data ? `Instalada em ${versao.data}` : undefined}>
            Versão {versao.codigo}
            {versao.data && ` · ${versao.data}`}
        </p>
    );
}
