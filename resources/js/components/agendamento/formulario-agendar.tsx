import { DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { Opcao, Responsavel } from '../../clinico/tipos';
import { Botao, BotaoLigacao, Campo, Seccao, Selecao, SelecaoResponsavel, estiloEntrada } from '../clinico/campos';

export type PropsAgendar = {
    agendamento: {
        id: number;
        data: string | null;
        periodo_id: number | null;
        hora: string;
        local_id: number | null;
        sala_id: number | null;
        tipo_id: number | null;
        responsavel_id: number | null;
        estado: number;
        reserva_sangue: boolean;
        comentarios: string;
    } | null;
    espera: {
        id: number;
        utente: { id: number | null; nome: string | null; numero_processo: number | null };
        intervencoes: string[];
        responsavel_id: number | null;
    };
    dominios: { periodos: Opcao[]; locais: Opcao[]; salas: Opcao[]; tipos: Opcao[]; estados: Opcao[]; responsaveis: Responsavel[] };
    hoje: string;
    dataSugerida: string | null;
};

type Props = PropsAgendar & { fechar: () => void };

export function FormularioAgendar({ agendamento, espera, dominios, hoje, dataSugerida, fechar }: Props) {
    const form = useForm({
        data: agendamento?.data ?? dataSugerida ?? '',
        hora: agendamento?.hora ?? '',
        periodo_id: (agendamento?.periodo_id ?? 1) as number | '',
        local_id: (agendamento?.local_id ?? 1) as number | '',
        sala_id: (agendamento?.sala_id ?? '') as number | '',
        tipo_id: (agendamento?.tipo_id ?? 1) as number | '',
        responsavel_id: (agendamento?.responsavel_id ?? espera.responsavel_id ?? '') as number | '',
        estado: (agendamento?.estado ?? 3) as number | '',
        reserva_sangue: agendamento?.reserva_sangue ?? false,
        comentarios: agendamento?.comentarios ?? '',
    });
    const { data, setData, processing } = form;
    const erros = form.errors as Record<string, string | undefined>;

    const enviar = (forcar: boolean) => {
        form.transform((atual) => ({ ...atual, forcar }));
        if (agendamento) form.put(`/agendamento/${agendamento.id}`, { preserveScroll: true });
        else form.post(`/agendamento/lista-de-espera/${espera.id}/agendar`, { preserveScroll: true });
    };

    const guardar = (e: FormEvent) => {
        e.preventDefault();
        enviar(false);
    };

    const apagar = () => {
        if (agendamento && window.confirm('Apagar este agendamento?')) router.delete(`/agendamento/${agendamento.id}`);
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle>{agendamento ? 'Agendamento' : 'Agendar cirurgia'}</DialogTitle>
                <DialogDescription>
                    {espera.utente.nome} · Processo {espera.utente.numero_processo} · {espera.intervencoes.join(', ') || 'sem intervenção indicada'}
                </DialogDescription>
            </DialogHeader>
            <form onSubmit={guardar} noValidate className="space-y-5">
                {erros.conflito && (
                    <div
                        role="alert"
                        className="flex flex-wrap items-center gap-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                    >
                        <p className="min-w-0 flex-1">{erros.conflito}</p>
                        <Botao variante="secundario" onClick={() => enviar(true)} disabled={processing}>
                            Agendar mesmo assim
                        </Botao>
                    </div>
                )}
                <Seccao titulo="Data e local">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Campo rotulo="Data" para="data" erro={erros.data}>
                            <input
                                id="data"
                                type="date"
                                min={agendamento ? undefined : hoje}
                                value={data.data}
                                onChange={(e) => setData('data', e.target.value)}
                                className={estiloEntrada}
                                aria-invalid={!!erros.data}
                            />
                        </Campo>
                        <Campo rotulo="Hora" para="hora" erro={erros.hora} opcional>
                            <input
                                id="hora"
                                type="time"
                                value={data.hora}
                                onChange={(e) => setData('hora', e.target.value)}
                                className={estiloEntrada}
                                aria-invalid={!!erros.hora}
                            />
                        </Campo>
                        <Campo rotulo="Período" para="periodo" erro={erros.periodo_id}>
                            <Selecao id="periodo" opcoes={dominios.periodos} valor={data.periodo_id} aoMudar={(v) => setData('periodo_id', v)} />
                        </Campo>
                        <Campo rotulo="Tipo" para="tipo" erro={erros.tipo_id}>
                            <Selecao id="tipo" opcoes={dominios.tipos} valor={data.tipo_id} aoMudar={(v) => setData('tipo_id', v)} />
                        </Campo>
                        <Campo rotulo="Local" para="local" erro={erros.local_id}>
                            <Selecao id="local" opcoes={dominios.locais} valor={data.local_id} aoMudar={(v) => setData('local_id', v)} />
                        </Campo>
                        <Campo rotulo="Sala" para="sala" erro={erros.sala_id}>
                            <Selecao
                                id="sala"
                                opcoes={dominios.salas}
                                valor={data.sala_id}
                                aoMudar={(v) => setData('sala_id', v)}
                                invalido={!!erros.sala_id}
                            />
                        </Campo>
                        <Campo rotulo="Responsável" para="responsavel" erro={erros.responsavel_id}>
                            <SelecaoResponsavel
                                id="responsavel"
                                responsaveis={dominios.responsaveis}
                                valor={data.responsavel_id}
                                aoMudar={(v) => setData('responsavel_id', v)}
                                invalido={!!erros.responsavel_id}
                            />
                        </Campo>
                    </div>
                </Seccao>

                <Seccao titulo="Estado">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Campo rotulo="Estado" para="estado" erro={erros.estado}>
                            <Selecao id="estado" opcoes={dominios.estados} valor={data.estado} aoMudar={(v) => setData('estado', v)} />
                        </Campo>
                    </div>
                    <label className="mt-4 flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={data.reserva_sangue} onChange={(e) => setData('reserva_sangue', e.target.checked)} />
                        Reserva de sangue
                    </label>
                    <Campo rotulo="Comentários" para="comentarios" erro={erros.comentarios} opcional className="mt-4">
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

                <div className="flex flex-wrap gap-3">
                    <Botao type="submit" disabled={processing}>
                        {agendamento ? 'Guardar alterações' : 'Agendar'}
                    </Botao>
                    <Botao variante="secundario" onClick={fechar}>
                        Cancelar
                    </Botao>
                    <BotaoLigacao href={`/agendamento/lista-de-espera/${espera.id}`} variante="secundario">
                        Ver inscrição
                    </BotaoLigacao>
                    {agendamento && (
                        <Botao variante="perigo" className="ml-auto" onClick={apagar}>
                            Apagar agendamento
                        </Botao>
                    )}
                </div>
            </form>
        </>
    );
}
