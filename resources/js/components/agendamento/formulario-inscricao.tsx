import { DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { COR_ESTADO, nomeEstado } from '../../clinico/agendamento';
import { formatarData } from '../../clinico/formato';
import type { Opcao, OpcaoAgrupada, Responsavel } from '../../clinico/tipos';
import { Botao, BotaoLigacao, Campo, Seccao, Selecao, SelecaoResponsavel, estiloEntrada } from '../clinico/campos';
import { EscolhaMultipla } from '../clinico/escolha-multipla';

export type PropsInscricao = {
    espera: {
        id: number;
        prioridade_id: number;
        data_de_lista: string | null;
        responsavel_id: number | null;
        comentarios: string;
        intervencoes: number[];
        diagnosticos: number[];
        estado: number;
        agendamentos: { id: number; data: string | null; estado: number | null }[];
    } | null;
    utente: { id: number; nome: string; numero_processo: number };
    dominios: { prioridades: Opcao[]; responsaveis: Responsavel[]; diagnosticos: OpcaoAgrupada[]; intervencoes: OpcaoAgrupada[] };
    hoje: string;
    estados: Opcao[];
};

type Props = PropsInscricao & { fechar: () => void };

export function FormularioInscricao({ espera, utente, dominios, hoje, estados, fechar }: Props) {
    const form = useForm({
        prioridade_id: (espera?.prioridade_id ?? 1) as number | '',
        data_de_lista: espera?.data_de_lista ?? hoje,
        responsavel_id: (espera?.responsavel_id ?? '') as number | '',
        comentarios: espera?.comentarios ?? '',
        intervencoes: espera?.intervencoes ?? ([] as number[]),
        diagnosticos: espera?.diagnosticos ?? ([] as number[]),
    });
    const { data, setData, processing } = form;
    const erros = form.errors as Record<string, string | undefined>;

    const guardar = (e: FormEvent) => {
        e.preventDefault();
        if (espera) form.put(`/agendamento/lista-de-espera/${espera.id}`, { preserveScroll: true });
        else form.post(`/agendamento/utentes/${utente.id}/inscrever`, { preserveScroll: true });
    };

    const cancelar = () => {
        if (espera && window.confirm('Cancelar esta inscrição em lista de espera?'))
            router.post(`/agendamento/lista-de-espera/${espera.id}/cancelar`);
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle>{espera ? 'Inscrição em lista de espera' : 'Inscrever em lista de espera'}</DialogTitle>
                <DialogDescription className="tabular-nums">
                    {utente.nome} · Processo {utente.numero_processo}
                </DialogDescription>
            </DialogHeader>
            <form onSubmit={guardar} noValidate className="space-y-5">
                <Seccao titulo="Inscrição">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Campo rotulo="Data de inscrição" para="data" erro={erros.data_de_lista}>
                            <input
                                id="data"
                                type="date"
                                max={hoje}
                                value={data.data_de_lista}
                                onChange={(e) => setData('data_de_lista', e.target.value)}
                                className={estiloEntrada}
                            />
                        </Campo>
                        <Campo rotulo="Prioridade" para="prioridade" erro={erros.prioridade_id}>
                            <Selecao
                                id="prioridade"
                                opcoes={dominios.prioridades}
                                valor={data.prioridade_id}
                                aoMudar={(v) => setData('prioridade_id', v)}
                                invalido={!!erros.prioridade_id}
                            />
                        </Campo>
                        <Campo rotulo="Responsável" para="responsavel" erro={erros.responsavel_id}>
                            <SelecaoResponsavel
                                id="responsavel" responsaveis={dominios.responsaveis} valor={data.responsavel_id}
                                aoMudar={(v) => setData('responsavel_id', v)}
                                invalido={!!erros.responsavel_id}
                            />
                        </Campo>
                    </div>
                </Seccao>

                <Seccao titulo="Intervenções propostas" descricao="Escolha pelo menos uma.">
                    <EscolhaMultipla
                        opcoes={dominios.intervencoes}
                        valor={data.intervencoes}
                        aoMudar={(v) => setData('intervencoes', v)}
                        procurar="Procurar intervenção"
                        vazio="Nenhuma intervenção escolhida."
                    />
                    {erros.intervencoes && (
                        <p className="mt-2 text-sm text-rose-700" role="alert">
                            {erros.intervencoes}
                        </p>
                    )}
                </Seccao>

                <Seccao titulo="Diagnósticos">
                    <EscolhaMultipla
                        opcoes={dominios.diagnosticos}
                        valor={data.diagnosticos}
                        aoMudar={(v) => setData('diagnosticos', v)}
                        procurar="Procurar diagnóstico"
                        vazio="Nenhum diagnóstico escolhido."
                    />
                </Seccao>

                <Seccao titulo="Comentários">
                    <Campo rotulo="Comentários" para="comentarios" erro={erros.comentarios} opcional>
                        <textarea
                            id="comentarios"
                            rows={3}
                            maxLength={2000}
                            value={data.comentarios}
                            onChange={(e) => setData('comentarios', e.target.value)}
                            className={estiloEntrada}
                        />
                    </Campo>
                </Seccao>

                {espera && espera.agendamentos.length > 0 && (
                    <Seccao titulo="Agendamentos desta inscrição">
                        <ul className="space-y-1 text-sm">
                            {espera.agendamentos.map((a) => (
                                <li key={a.id} className="flex items-center gap-3">
                                    <a href={`/agendamento/${a.id}`} className="text-[#7d1830] tabular-nums hover:underline">
                                        {formatarData(a.data)}
                                    </a>
                                    <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${COR_ESTADO[a.estado ?? 6]}`}>
                                        {nomeEstado(estados, a.estado)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Seccao>
                )}

                <div className="flex flex-wrap gap-3">
                    <Botao type="submit" disabled={processing}>
                        {espera ? 'Guardar alterações' : 'Inscrever'}
                    </Botao>
                    {espera && espera.estado !== 4 && (
                        <BotaoLigacao href={`/agendamento/lista-de-espera/${espera.id}/agendar`} variante="secundario">
                            Agendar cirurgia
                        </BotaoLigacao>
                    )}
                    <Botao variante="secundario" onClick={fechar}>
                        Cancelar
                    </Botao>
                    {espera && espera.estado !== 4 && (
                        <Botao variante="perigo" className="ml-auto" onClick={cancelar}>
                            Cancelar inscrição
                        </Botao>
                    )}
                </div>
            </form>
        </>
    );
}
