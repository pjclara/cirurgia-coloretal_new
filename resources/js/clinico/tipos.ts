export type Opcao = { id: number; nome: string };
export type OpcaoAgrupada = Opcao & { grupo: string };
export type Responsavel = { id: number; nome: string; abrev: string | null; equipa: string | null };

/** Dados partilhados pelo ClinicoServiceProvider em todas as páginas. */
export type Clinico = {
    utilizador: { id: number; nome: string; abrev: string | null; equipa: string | null; roles: string[] } | null;
    permissoes: string[];
    administrador: boolean;
    flash: { sucesso: string | null; erro: string | null };
};

export type PropsPartilhadas = { clinico: Clinico };

/** Formato do paginador do Laravel depois de serializado. */
export type Paginado<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type ResumoUtente = {
    id: number;
    nome: string;
    numero_processo: number;
    data_nascimento: string | null;
    sexo?: string | null;
};

export type ResultadoPesquisa = ResumoUtente & { internamento_aberto_id: number | null };

export const SEM_COMPLICACOES = 1;
export const ALTA_PENDENTE = 1;
export const ALTA_CONCLUIDA = 2;
export const DESTINO_FALECIDO = 6;
export const CLAVIEN_GRAU_5 = 7;
