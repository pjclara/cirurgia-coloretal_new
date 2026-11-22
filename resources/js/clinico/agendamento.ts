import type { Opcao } from './tipos';

export const PRIORIDADE: Record<number, string> = { 1: 'Normal', 2: 'Prioritária', 3: 'Prioridade 3', 99: 'Não definida' };

/** Cores por estado: 1 Proposto, 2 Pendente, 3 Agendado, 4 Cancelado, 5 Operado, 6 Outro. */
export const COR_ESTADO: Record<number, string> = {
    1: 'bg-sky-100 text-sky-900',
    2: 'bg-amber-100 text-amber-900',
    3: 'bg-teal-100 text-teal-900',
    4: 'bg-slate-200 text-slate-600 line-through',
    5: 'bg-[#a3213e]/15 text-[#7d1830]',
    6: 'bg-slate-100 text-slate-700',
};

/** Sangue para a cirurgia (códigos da coluna `reserva_sangue`): 1 não é necessário, 2 apenas classificação, 3 reserva de sangue. */
export const SANGUE: Record<string, string> = { '1': 'Não é necessário', '2': 'Apenas classificação', '3': 'Reserva de sangue' };
export const OPCOES_SANGUE: Opcao[] = [1, 2, 3].map((id) => ({ id, nome: SANGUE[String(id)] }));

/** O que se mostra nas listas: só quando há algo a fazer (classificação ou reserva). */
export const notaDeSangue = (codigo: string | null) => (codigo === '2' || codigo === '3' ? SANGUE[codigo] : null);

export const nomeEstado = (estados: Opcao[], id: number | null) => estados.find((e) => e.id === id)?.nome ?? '—';

export const NOMES_DIA = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];

export type Categoria = 'programada' | 'ambulatorio' | 'adicional' | 'outra';

/** Cor do agendamento no calendário. O adicional leva também contorno tracejado, para não depender só da cor. */
export const CATEGORIAS: Record<Categoria, { rotulo: string; chip: string; ponto: string; faixa: string }> = {
    programada: {
        rotulo: 'Programada',
        chip: 'bg-sky-100 text-sky-950 border-l-[3px] border-sky-600',
        ponto: 'bg-sky-600',
        faixa: 'border-l-sky-600',
    },
    ambulatorio: {
        rotulo: 'Ambulatório',
        chip: 'bg-emerald-100 text-emerald-950 border-l-[3px] border-emerald-600',
        ponto: 'bg-emerald-600',
        faixa: 'border-l-emerald-600',
    },
    adicional: {
        rotulo: 'Adicional',
        chip: 'bg-amber-100 text-amber-950 border border-dashed border-amber-600 border-l-[3px] border-l-amber-600',
        ponto: 'bg-amber-500',
        faixa: 'border-l-amber-500',
    },
    outra: {
        rotulo: 'Urgência / outro',
        chip: 'bg-slate-100 text-slate-800 border-l-[3px] border-slate-400',
        ponto: 'bg-slate-400',
        faixa: 'border-l-slate-400',
    },
};
