import { router } from '@inertiajs/react';

type Props = { url: string; de: string | null; ate: string | null; aoMudar?: (aCarregar: boolean) => void };

/** Intervalo de datas livre (De / até) das páginas de análise; sobrepõe-se aos períodos predefinidos. */
export function FiltroDatas({ url, de, ate, aoMudar }: Props) {
    const ir = (novo: { de?: string; ate?: string }) => {
        const params = { de: de ?? '', ate: ate ?? '', ...novo };
        router.get(url, Object.fromEntries(Object.entries(params).filter(([, v]) => v)), {
            preserveScroll: true,
            preserveState: true,
            onStart: () => aoMudar?.(true),
            onFinish: () => aoMudar?.(false),
        });
    };
    const entrada = 'rounded-md border border-slate-300 bg-white px-2 py-1 text-sm dark:border-slate-600 dark:bg-transparent';

    return (
        <div className="flex flex-wrap items-center gap-2 text-sm" role="group" aria-label="Intervalo de datas">
            <label className="flex items-center gap-1.5">
                De
                <input type="date" className={entrada} value={de ?? ''} max={ate || undefined} onChange={(e) => ir({ de: e.target.value })} />
            </label>
            <label className="flex items-center gap-1.5">
                até
                <input type="date" className={entrada} value={ate ?? ''} min={de || undefined} onChange={(e) => ir({ ate: e.target.value })} />
            </label>
        </div>
    );
}
