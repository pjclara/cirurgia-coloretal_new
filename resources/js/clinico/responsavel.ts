import { usePage } from '@inertiajs/react';

/** O utilizador com sessão, se estiver entre os responsáveis possíveis: é o responsável por omissão nos registos novos. */
export function useResponsavelPorOmissao(responsaveis: { id: number }[]): number | '' {
    const eu = usePage<{ auth: { user: { id: number } | null } }>().props.auth.user?.id;

    return eu !== undefined && responsaveis.some((r) => r.id === eu) ? eu : '';
}
