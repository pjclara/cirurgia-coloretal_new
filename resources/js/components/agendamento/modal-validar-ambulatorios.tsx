import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { formatarData } from '../../clinico/formato';
import type { Opcao } from '../../clinico/tipos';
import { Botao, estiloEntrada } from '../clinico/campos';
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

/** Decisão de cada ambulatório: operado, ou cancelado com o seu motivo (o número é o código do motivo). */
type Decisao = 'operado' | number;

/** Ambulatórios já passados que não estão como operados: cada um é operado ou cancelado (com o seu motivo), de uma vez. */
export function ModalValidarAmbulatorios({ lista, motivos, aoFechar }: { lista: AmbulatorioPorValidar[]; motivos: Opcao[]; aoFechar: () => void }) {
    const [decisoes, setDecisoes] = useState<Map<number, Decisao>>(() => new Map());
    const [a_gravar, setAGravar] = useState(false);

    const decidir = (id: number, valor: string) =>
        setDecisoes((atual) => {
            const novo = new Map(atual);
            if (valor === '') novo.delete(id);
            else novo.set(id, valor === 'operado' ? 'operado' : Number(valor));
            return novo;
        });

    /** Por data (a lista já vem da mais recente para a mais antiga). */
    const porDia = [...Map.groupBy(lista, (a) => a.data)];
    /** Marca todos os de um dia (ou todos) como operados; só toca nos que ainda não têm decisão. */
    const operadosEm = (itens: AmbulatorioPorValidar[]) =>
        setDecisoes((atual) => {
            const novo = new Map(atual);
            itens.forEach((a) => !novo.has(a.id) && novo.set(a.id, 'operado'));
            return novo;
        });
    const limpar = () => setDecisoes(new Map());

    const operados = [...decisoes.entries()].filter(([, d]) => d === 'operado').map(([id]) => id);
    const cancelados = [...decisoes.entries()].filter(([, d]) => d !== 'operado').map(([id, d]) => ({ id, motivo: d as number }));

    const gravar = () => {
        setAGravar(true);
        router.post(
            '/agendamento/ambulatorio/decisoes',
            { operados, cancelados },
            { preserveScroll: true, onSuccess: aoFechar, onFinish: () => setAGravar(false) },
        );
    };

    const resumo = [
        operados.length > 0 ? `${operados.length} operado${operados.length === 1 ? '' : 's'}` : null,
        cancelados.length > 0 ? `${cancelados.length} cancelado${cancelados.length === 1 ? '' : 's'}` : null,
    ]
        .filter(Boolean)
        .join(' e ');

    return (
        <Dialog open onOpenChange={(aberto) => !aberto && aoFechar()}>
            <DialogContent
                className="flex max-h-[90vh] max-w-3xl flex-col gap-3 bg-white text-slate-900"
                onInteractOutside={(e) => e.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>Validar ambulatórios</DialogTitle>
                    <DialogDescription>
                        Ambulatórios já passados que não estão como operados. Diga, para cada um, se foi operado ou se foi cancelado (e porquê).
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-3 overflow-y-auto pr-1">
                    {lista.length === 0 ? (
                        <p className="text-sm text-slate-600">Não há ambulatórios por validar.</p>
                    ) : (
                        <>
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <Botao variante="secundario" onClick={() => operadosEm(lista)}>
                                    Marcar todos como operados ({lista.length})
                                </Botao>
                                {decisoes.size > 0 && (
                                    <button type="button" onClick={limpar} className="text-xs text-slate-600 underline">
                                        Limpar escolhas
                                    </button>
                                )}
                            </div>
                            <div className="space-y-4">
                                {porDia.map(([dia, itens]) => (
                                    <section key={dia} aria-label={formatarData(dia)}>
                                        <div className="mb-1 flex items-center gap-2 text-sm font-semibold text-slate-900">
                                            {formatarData(dia)}
                                            <span className="font-normal text-slate-600">({itens.length})</span>
                                            <button
                                                type="button"
                                                onClick={() => operadosEm(itens)}
                                                className="text-xs font-normal text-teal-800 underline"
                                            >
                                                todos operados
                                            </button>
                                        </div>
                                        <ul className="divide-y divide-slate-200 rounded-md border border-slate-200">
                                            {itens.map((a) => {
                                                const decisao = decisoes.get(a.id);
                                                return (
                                                    <li
                                                        key={a.id}
                                                        className={`flex flex-wrap items-center gap-3 px-3 py-2 text-sm ${
                                                            decisao === 'operado' ? 'bg-teal-50' : decisao !== undefined ? 'bg-rose-50' : ''
                                                        }`}
                                                    >
                                                        <span className="min-w-0 flex-1">
                                                            <span className="block font-medium text-slate-900">
                                                                {a.utente.nome ?? 'Utente sem ficha'}
                                                            </span>
                                                            <span className="block text-xs text-slate-600 tabular-nums">
                                                                <NumeroProcesso valor={a.utente.numero_processo} />
                                                                {a.intervencoes.length > 0 && ` · ${a.intervencoes.join(', ')}`}
                                                                {(a.hora ?? a.periodo) && ` · ${a.hora ?? a.periodo}`}
                                                            </span>
                                                        </span>
                                                        <select
                                                            aria-label={`Resultado de ${a.utente.nome ?? 'utente sem ficha'}`}
                                                            value={decisao === undefined ? '' : String(decisao)}
                                                            onChange={(e) => decidir(a.id, e.target.value)}
                                                            className={`${estiloEntrada} w-64`}
                                                        >
                                                            <option value="">Por validar</option>
                                                            <option value="operado">Operado</option>
                                                            <optgroup label="Cancelado: motivo">
                                                                {motivos.map((m) => (
                                                                    <option key={m.id} value={m.id}>
                                                                        {m.nome}
                                                                    </option>
                                                                ))}
                                                            </optgroup>
                                                        </select>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    </section>
                                ))}
                            </div>
                        </>
                    )}
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-slate-200 pt-3">
                    <Botao variante="secundario" onClick={aoFechar}>
                        Fechar
                    </Botao>
                    {lista.length > 0 && (
                        <Botao onClick={gravar} disabled={decisoes.size === 0 || a_gravar}>
                            {decisoes.size === 0 ? 'Gravar' : `Gravar: ${resumo}`}
                        </Botao>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
