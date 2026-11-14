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

export const nomeEstado = (estados: Opcao[], id: number | null) => estados.find((e) => e.id === id)?.nome ?? '—';

export const NOMES_DIA = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
