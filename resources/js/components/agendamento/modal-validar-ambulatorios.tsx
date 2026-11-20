import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { formatarData } from '../../clinico/formato';
import { Botao } from '../clinico/campos';
import { NumeroProcesso } from '../clinico/numero-processo';

export type AmbulatorioPorValidar = {
    id: number;
    data: string;
    utente: { nome: string | null; numero_processo: number | null };
    intervencoes: string[];
    hora: string | null;
    periodo: string | null;
    estado: number | null;
};

/** Ambulatórios já passados que não estão como operados, para validar de uma vez (não têm internamento nem cirurgia registada). */
export function ModalValidarAmbulatorios({ lista, aoFechar }: { lista: AmbulatorioPorValidar[]; aoFechar: () => void }) {
    const [escolhidos, setEscolhidos] = useState<Set<number>>(() => new Set());
    const [a_gravar, setAGravar] = useState(false);

    const alternar = (id: number) =>
        setEscolhidos((atual) => {
            const novo = new Set(atual);
            if (!novo.delete(id)) novo.add(id);
            return novo;
        });
    const todos = escolhidos.size === lista.length;

    /** Por data (a lista já vem da mais recente para a mais antiga). */
    const porDia = [...Map.groupBy(lista, (a) => a.data)];
    const alternarDia = (itens: AmbulatorioPorValidar[]) =>
        setEscolhidos((atual) => {
            const novo = new Set(atual);
            const diaTodo = itens.every((a) => novo.has(a.id));
            itens.forEach((a) => (diaTodo ? novo.delete(a.id) : novo.add(a.id)));
            return novo;
        });

    const validar = () => {
        setAGravar(true);
        router.post(
            '/agendamento/ambulatorio/operados',
            { ids: [...escolhidos] },
            { preserveScroll: true, onSuccess: aoFechar, onFinish: () => setAGravar(false) },
        );
    };

    return (
        <Dialog open onOpenChange={(aberto) => !aberto && aoFechar()}>
            <DialogContent
                className="flex max-h-[90vh] max-w-3xl flex-col gap-3 bg-white text-slate-900"
                onInteractOutside={(e) => e.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>Validar ambulatórios</DialogTitle>
                    <DialogDescription>
                        Ambulatórios já passados que não estão como operados. Marque os que foram realizados e confirme.
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-3 overflow-y-auto pr-1">
                    {lista.length === 0 ? (
                        <p className="text-sm text-slate-600">Não há ambulatórios por validar.</p>
                    ) : (
                        <>
                            <label className="flex items-center gap-2 text-sm font-medium">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={todos}
                                    onChange={() => setEscolhidos(todos ? new Set() : new Set(lista.map((a) => a.id)))}
                                />
                                Selecionar todos ({lista.length})
                            </label>
                            <div className="space-y-4">
                                {porDia.map(([dia, itens]) => {
                                    const diaTodo = itens.every((a) => escolhidos.has(a.id));
                                    return (
                                        <section key={dia} aria-label={formatarData(dia)}>
                                            <label className="mb-1 flex items-center gap-2 text-sm font-semibold text-slate-900">
                                                <input type="checkbox" className="size-4" checked={diaTodo} onChange={() => alternarDia(itens)} />
                                                {formatarData(dia)}
                                                <span className="font-normal text-slate-600">({itens.length})</span>
                                            </label>
                                            <ul className="divide-y divide-slate-200 rounded-md border border-slate-200">
                                                {itens.map((a) => (
                                                    <li key={a.id}>
                                                        <label className="flex cursor-pointer items-start gap-3 px-3 py-2 text-sm hover:bg-slate-50">
                                                            <input
                                                                type="checkbox"
                                                                className="mt-0.5 size-4"
                                                                checked={escolhidos.has(a.id)}
                                                                onChange={() => alternar(a.id)}
                                                            />
                                                            <span className="flex-1">
                                                                <span className="block font-medium text-slate-900">
                                                                    {a.utente.nome ?? 'Utente sem ficha'}
                                                                </span>
                                                                <span className="block text-xs text-slate-600 tabular-nums">
                                                                    <NumeroProcesso valor={a.utente.numero_processo} />
                                                                    {a.intervencoes.length > 0 && ` · ${a.intervencoes.join(', ')}`}
                                                                </span>
                                                            </span>
                                                            <span className="text-xs text-slate-600 tabular-nums">{a.hora ?? a.periodo ?? ''}</span>
                                                        </label>
                                                    </li>
                                                ))}
                                            </ul>
                                        </section>
                                    );
                                })}
                            </div>
                        </>
                    )}
                </div>

                <div className="flex justify-end gap-2 border-t border-slate-200 pt-3">
                    <Botao variante="secundario" onClick={aoFechar}>
                        Fechar
                    </Botao>
                    {lista.length > 0 && (
                        <Botao onClick={validar} disabled={escolhidos.size === 0 || a_gravar}>
                            Marcar {escolhidos.size} como operados
                        </Botao>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
