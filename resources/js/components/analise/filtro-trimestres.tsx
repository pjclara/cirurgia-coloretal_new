import { router } from '@inertiajs/react';

type Props = {
    url: string;
    trimestres: string[];
    de: string | null;
    ate: string | null;
    aoMudar?: (aCarregar: boolean) => void;
    extra?: Record<string, string>;
};

/** Seletores De / até por trimestre, partilhados pelas páginas de análise do cancro retal. */
export function FiltroTrimestres({ url, trimestres, de, ate, aoMudar, extra }: Props) {
    const mudar = (novo: { de?: string; ate?: string }) =>
        router.get(
            url,
            { ...extra, de: de ?? '', ate: ate ?? '', ...novo },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => aoMudar?.(true),
                onFinish: () => aoMudar?.(false),
            },
        );
    const anos = [...new Set(trimestres.map((t) => t.slice(0, 4)))];
    const anoSelecionado =
        anos.find((a) => {
            const doAno = trimestres.filter((t) => t.startsWith(a));
            return de === doAno[0] && ate === doAno[doAno.length - 1];
        }) ?? '';
    const escolherAno = (ano: string) => {
        const doAno = ano === '' ? trimestres : trimestres.filter((t) => t.startsWith(ano));
        mudar({ de: doAno[0], ate: doAno[doAno.length - 1] });
    };
    const selecao = 'rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-transparent';
    // Com um ano escolhido, só se mostram os trimestres desse ano.
    const opcoes = trimestres
        .filter((t) => anoSelecionado === '' || t.startsWith(anoSelecionado))
        .map((t) => (
            <option key={t} value={t}>
                {t}
            </option>
        ));

    return (
        <div data-sem-exportar className="flex flex-wrap items-center gap-3 text-sm" role="group" aria-label="Período por trimestre">
            <label className="flex items-center gap-2">
                Ano
                <select className={selecao} value={anoSelecionado} onChange={(e) => escolherAno(e.target.value)}>
                    <option value="">Todos</option>
                    {anos.map((a) => (
                        <option key={a} value={a}>
                            {a}
                        </option>
                    ))}
                </select>
            </label>
            <label className="flex items-center gap-2">
                De
                <select className={selecao} value={de ?? ''} onChange={(e) => mudar({ de: e.target.value })}>
                    {opcoes}
                </select>
            </label>
            <label className="flex items-center gap-2">
                até
                <select className={selecao} value={ate ?? ''} onChange={(e) => mudar({ ate: e.target.value })}>
                    {opcoes}
                </select>
            </label>
            <button
                type="button"
                onClick={() => mudar({ de: trimestres[0], ate: trimestres[trimestres.length - 1] })}
                className="rounded px-2 py-1.5 text-slate-700 underline underline-offset-2 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10"
            >
                Todo o período
            </button>
        </div>
    );
}
