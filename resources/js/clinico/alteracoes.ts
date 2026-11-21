import { router } from '@inertiajs/react';
import { useEffect, useRef, type RefObject } from 'react';

let semAviso = false;

/** Navega sem perguntar por alterações por gravar (depois de gravar com sucesso). */
export function navegarSemAviso(url: string, opcoes: Parameters<typeof router.visit>[1] = {}) {
    semAviso = true;
    try {
        router.visit(url, opcoes);
    } finally {
        semAviso = false;
    }
}

/**
 * Formulários com alterações por gravar: pergunta antes de sair (fechar a modal, abrir outra página ou fechar o separador)
 * e deixa gravar com Ctrl+S (ou ⌘+S). As gravações (pedidos que não são GET) nunca perguntam.
 */
export function useAlteracoes(sujo: boolean, formulario: RefObject<HTMLFormElement | null>, podeGravar: boolean) {
    const estado = useRef({ sujo, podeGravar });
    estado.current = { sujo, podeGravar };

    useEffect(() => {
        const removerAntes = router.on('before', (evento) => {
            if (semAviso || !estado.current.sujo || evento.detail.visit.method !== 'get') return;
            if (!window.confirm('Tem alterações por gravar. Sair sem gravar?')) return false;
        });
        const aoSair = (e: BeforeUnloadEvent) => {
            if (!estado.current.sujo) return;
            e.preventDefault();
            e.returnValue = '';
        };
        const aoTeclar = (e: KeyboardEvent) => {
            if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 's') return;
            e.preventDefault();
            if (estado.current.podeGravar) formulario.current?.requestSubmit();
        };
        window.addEventListener('beforeunload', aoSair);
        window.addEventListener('keydown', aoTeclar);
        return () => {
            removerAntes();
            window.removeEventListener('beforeunload', aoSair);
            window.removeEventListener('keydown', aoTeclar);
        };
    }, [formulario]);
}
