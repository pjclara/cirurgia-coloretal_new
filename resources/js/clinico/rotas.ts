/**
 * Endereços da aplicação clínica (espelham routes/clinico.php).
 * Ficam aqui para não depender do gerador de rotas do starter kit.
 */
export const rotas = {
    quadro: '/quadro',
    sair: '/logout',
    password: '/conta/password',
    utentes: {
        lista: '/utentes',
        pesquisa: '/utentes/pesquisa',
        novo: '/quadro/novo-utente',
        ficha: (id: number) => `/utentes/${id}`,
        editar: (id: number) => `/utentes/${id}/editar`,
        dados: (id: number) => `/utentes/${id}/dados`,
    },
    internamentos: {
        lista: '/internamentos',
        seguimentos: '/internamentos/seguimentos',
        novo: (utenteId?: number) => (utenteId ? `/quadro/novo?utente=${utenteId}` : '/quadro/novo'),
        registo: (id: number) => `/internamentos/${id}`,
        /** Abre o internamento numa modal por cima da lista. */
        naLista: (id: number) => `/internamentos/${id}/editar`,
    },
};
