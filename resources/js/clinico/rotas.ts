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
        novo: '/utentes/novo',
        ficha: (id: number) => `/utentes/${id}`,
        editar: (id: number) => `/utentes/${id}/editar`,
    },
    internamentos: {
        lista: '/internamentos',
        novo: (utenteId?: number) => (utenteId ? `/internamentos/novo?utente=${utenteId}` : '/internamentos/novo'),
        registo: (id: number) => `/internamentos/${id}`,
    },
};
