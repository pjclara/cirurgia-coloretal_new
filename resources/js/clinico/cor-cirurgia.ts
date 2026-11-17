/** Cor de uma cirurgia conforme o tipo: programada, adicional ou urgência (as urgentes por oclusão, perfuração, hemorragia incluídas). */
export type CategoriaCirurgia = 'programada' | 'adicional' | 'urgencia' | 'outra';

export function categoriaDaCirurgia(tipo: string | null | undefined): CategoriaCirurgia {
    const t = (tipo ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    if (t.startsWith('urg')) return 'urgencia';
    if (t.startsWith('adicional')) return 'adicional';
    if (t.startsWith('programada')) return 'programada';
    return 'outra';
}

export const CORES_CIRURGIA: Record<CategoriaCirurgia, { rotulo: string; chip: string; texto: string }> = {
    programada: { rotulo: 'Programada', chip: 'bg-sky-100 text-sky-900 hover:bg-sky-200 focus-visible:outline-sky-800', texto: 'text-sky-900' },
    adicional: {
        rotulo: 'Adicional',
        chip: 'bg-amber-100 text-amber-950 hover:bg-amber-200 focus-visible:outline-amber-800',
        texto: 'text-amber-900',
    },
    urgencia: { rotulo: 'Urgência', chip: 'bg-rose-100 text-rose-900 hover:bg-rose-200 focus-visible:outline-rose-800', texto: 'text-rose-800' },
    outra: { rotulo: 'Outra', chip: 'bg-slate-100 text-slate-800 hover:bg-slate-200 focus-visible:outline-slate-700', texto: 'text-slate-800' },
};

export const corDaCirurgia = (tipo: string | null | undefined) => CORES_CIRURGIA[categoriaDaCirurgia(tipo)];
