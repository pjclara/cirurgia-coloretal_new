import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { formatarData, hojeIso } from '../../clinico/formato';
import { SEM_COMPLICACOES, type Opcao, type OpcaoAgrupada } from '../../clinico/tipos';
import { BlocoMorbilidadeCampos, type BlocoMorbilidade, type ResolucaoComGrau } from '../clinico/bloco-morbilidade';
import { Botao, Campo, estiloEntrada } from '../clinico/campos';
import { NumeroProcesso } from '../clinico/numero-processo';

export type LinhaSeguimento = {
    id: number;
    utente: { id: number | null; nome: string | null; numero_processo: number | null };
    motivo: string;
    data_de_alta: string;
    prevista: string;
    clavien: string | null;
};

type Props = {
    linha: LinhaSeguimento;
    dominios: { complicacoes: OpcaoAgrupada[]; resolucoes: ResolucaoComGrau[]; clavien: Opcao[] };
    /** Há outro seguimento por registar a seguir na lista. */
    temSeguinte: boolean;
    aoFechar: () => void;
    /** Chamado depois de gravar; `seguinte` indica que se quer passar logo ao próximo. */
    aoGuardar: (seguinte: boolean) => void;
};

/** Registo rápido do seguimento aos 30 dias: data, "sem complicações" ou as complicações, e pronto. */
export function ModalSeguimento({ linha, dominios, temSeguinte, aoFechar, aoGuardar }: Props) {
    const [modo, setModo] = useState<'sem' | 'com' | null>(null);
    const form = useForm<BlocoMorbilidade>({ complicacoes: [], clavien_dindo_id: '', data_registo: hojeIso() });
    const erros = form.errors as Record<string, string | undefined>;

    const escolher = (novo: 'sem' | 'com') => {
        setModo(novo);
        form.setData((atual) => ({
            ...atual,
            complicacoes: novo === 'sem' ? [{ id: SEM_COMPLICACOES, resolucoes: [] }] : [],
            clavien_dindo_id: '',
        }));
    };

    const guardar = (seguinte: boolean) => (e?: FormEvent) => {
        e?.preventDefault();
        form.post(`/internamentos/${linha.id}/seguimento`, { preserveScroll: true, onSuccess: () => aoGuardar(seguinte) });
    };

    const cartao = (id: 'sem' | 'com', titulo: string, texto: string) => (
        <button
            type="button"
            role="radio"
            aria-checked={modo === id}
            onClick={() => escolher(id)}
            className={`flex-1 rounded-lg border px-4 py-3 text-left ${modo === id ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-300 hover:border-slate-500'}`}
        >
            <span className="block text-sm font-semibold text-slate-900">{titulo}</span>
            <span className="block text-xs text-slate-600">{texto}</span>
        </button>
    );

    return (
        <Dialog open onOpenChange={(aberto) => !aberto && aoFechar()}>
            <DialogContent
                className="max-h-[90vh] max-w-2xl overflow-y-auto bg-white text-slate-900"
                onInteractOutside={(e) => e.preventDefault()}
                aria-describedby={undefined}
            >
                <DialogHeader>
                    <DialogTitle>Seguimento aos 30 dias</DialogTitle>
                    <DialogDescription asChild>
                        <div className="tabular-nums">
                            <p>
                                {linha.utente.nome} · <NumeroProcesso valor={linha.utente.numero_processo} />
                            </p>
                            <p>
                                Alta a {formatarData(linha.data_de_alta)} · seguimento previsto a {formatarData(linha.prevista)}
                            </p>
                            <p className="truncate">
                                {linha.motivo}
                                {linha.clavien && <span className="text-rose-800"> · Clavien-Dindo no internamento: {linha.clavien}</span>}
                            </p>
                        </div>
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={guardar(false)} noValidate className="space-y-4">
                    <Campo rotulo="Data do seguimento" para="seg-data" erro={erros.data_registo} className="max-w-xs">
                        <input
                            id="seg-data"
                            type="date"
                            min={linha.data_de_alta}
                            max={hojeIso()}
                            value={form.data.data_registo}
                            onChange={(e) => form.setData('data_registo', e.target.value)}
                            className={estiloEntrada}
                            aria-invalid={!!erros.data_registo}
                        />
                    </Campo>

                    <div role="radiogroup" aria-label="Resultado do seguimento" className="flex flex-col gap-2 sm:flex-row">
                        {cartao('sem', 'Sem complicações', 'O doente está bem aos 30 dias.')}
                        {cartao('com', 'Com complicações', 'Escolha as complicações e como foram resolvidas.')}
                    </div>
                    {erros.complicacoes && modo === null && (
                        <p className="text-sm text-rose-700" role="alert">
                            {erros.complicacoes}
                        </p>
                    )}

                    {modo === 'com' && (
                        <BlocoMorbilidadeCampos
                            id="seguimento"
                            bloco={form.data}
                            aoMudar={(b) => form.setData({ ...b, data_registo: form.data.data_registo })}
                            dominios={dominios}
                            desativado={false}
                            erros={{ complicacoes: erros.complicacoes, clavien: erros.clavien_dindo_id }}
                        />
                    )}

                    <div className="flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                        <Botao type="submit" disabled={form.processing || modo === null}>
                            Guardar
                        </Botao>
                        {temSeguinte && (
                            <Botao variante="secundario" disabled={form.processing || modo === null} onClick={() => guardar(true)()}>
                                Guardar e seguinte
                            </Botao>
                        )}
                        <Botao variante="secundario" onClick={aoFechar}>
                            Cancelar
                        </Botao>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
