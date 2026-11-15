import { useState } from 'react';
import { SEM_COMPLICACOES } from '../../clinico/tipos';
import { BlocoMorbilidadeCampos, type BlocoMorbilidade } from './bloco-morbilidade';
import { Campo, estiloEntrada } from './campos';

type Dominios = Parameters<typeof BlocoMorbilidadeCampos>[0]['dominios'];

type Props = {
    id: string;
    titulo: string;
    ajuda?: string;
    bloco: BlocoMorbilidade;
    aoMudar: (bloco: BlocoMorbilidade) => void;
    dominios: Dominios;
    desativado: boolean;
    /** Motivo pelo qual o registo ainda não está disponível. */
    indisponivel?: string;
    clavienFixo?: boolean;
    dataDoRegisto?: { rotulo: string; min: string; max: string };
    erros: { complicacoes?: string; clavien?: string; data?: string };
};

type Modo = 'sem' | 'com' | null;

const modoDe = (bloco: BlocoMorbilidade): Modo =>
    bloco.complicacoes.length === 0 ? null : bloco.complicacoes.every((c) => c.id === SEM_COMPLICACOES) ? 'sem' : 'com';

/** Um momento da morbilidade, no formato simples do seguimento: "Sem complicações" ou "Com complicações" e, só nesse caso, os detalhes. */
export function MomentoMorbilidade({
    id,
    titulo,
    ajuda,
    bloco,
    aoMudar,
    dominios,
    desativado,
    indisponivel,
    clavienFixo,
    dataDoRegisto,
    erros,
}: Props) {
    const [modo, setModo] = useState<Modo>(() => modoDe(bloco));

    const escolher = (novo: Exclude<Modo, null>) => {
        if (novo === modo) return;
        setModo(novo);
        aoMudar({ ...bloco, complicacoes: novo === 'sem' ? [{ id: SEM_COMPLICACOES, resolucoes: [] }] : [], clavien_dindo_id: '' });
    };

    const limpar = () => {
        setModo(null);
        aoMudar({ ...bloco, complicacoes: [], clavien_dindo_id: '' });
    };

    const cartao = (valor: 'sem' | 'com', rotulo: string) => (
        <button
            key={valor}
            type="button"
            role="radio"
            aria-checked={modo === valor}
            disabled={desativado || !!indisponivel}
            onClick={() => escolher(valor)}
            className={`flex-1 rounded-lg border px-4 py-2.5 text-left text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50 ${
                modo === valor
                    ? 'border-slate-900 bg-white text-slate-900 ring-1 ring-slate-900'
                    : 'border-slate-300 bg-white text-slate-700 hover:border-slate-500'
            }`}
        >
            {rotulo}
        </button>
    );

    return (
        <section aria-label={titulo} className="rounded-lg border border-slate-200 bg-white p-4">
            <div className="mb-3 flex items-baseline justify-between gap-2">
                <h3 className="text-sm font-semibold text-slate-900">{titulo}</h3>
                {modo !== null && !desativado && !indisponivel && (
                    <button type="button" onClick={limpar} className="text-xs text-slate-600 underline underline-offset-2 hover:text-slate-900">
                        Limpar
                    </button>
                )}
            </div>
            {ajuda && <p className="mb-3 text-xs text-slate-600">{ajuda}</p>}
            {indisponivel ? (
                <p className="text-sm text-slate-500">{indisponivel}</p>
            ) : (
                <>
                    <div role="radiogroup" aria-label={titulo} className="flex gap-2">
                        {cartao('sem', 'Sem complicações')}
                        {cartao('com', 'Com complicações')}
                    </div>
                    {erros.complicacoes && modo !== 'com' && (
                        <p className="mt-2 text-sm text-rose-700" role="alert">
                            {erros.complicacoes}
                        </p>
                    )}
                    {modo === 'com' && (
                        <div className="mt-3">
                            <BlocoMorbilidadeCampos
                                id={id}
                                bloco={bloco}
                                aoMudar={aoMudar}
                                dominios={{ ...dominios, complicacoes: dominios.complicacoes.filter((c) => c.id !== SEM_COMPLICACOES) }}
                                desativado={desativado}
                                clavienFixo={clavienFixo}
                                dataDoRegisto={dataDoRegisto}
                                erros={erros}
                            />
                        </div>
                    )}
                    {modo === 'sem' && dataDoRegisto && (
                        <Campo rotulo={dataDoRegisto.rotulo} para={`${id}-data`} erro={erros.data} opcional className="mt-3 max-w-xs">
                            <input
                                id={`${id}-data`}
                                type="date"
                                min={dataDoRegisto.min}
                                max={dataDoRegisto.max}
                                value={bloco.data_registo}
                                onChange={(e) => aoMudar({ ...bloco, data_registo: e.target.value })}
                                disabled={desativado}
                                className={estiloEntrada}
                            />
                        </Campo>
                    )}
                </>
            )}
        </section>
    );
}
