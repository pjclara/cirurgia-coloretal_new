import { DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { OPCOES_SANGUE } from '../../clinico/agendamento';
import { useResponsavelPorOmissao } from '../../clinico/responsavel';
import type { Opcao, Responsavel } from '../../clinico/tipos';
import { Botao, BotaoLigacao, Campo, Seccao, Selecao, SelecaoResponsavel, estiloEntrada } from '../clinico/campos';
import { NumeroProcesso } from '../clinico/numero-processo';
import { PerguntaCentroReferencia, type DadosSinalizacao } from '../referenciacao/pergunta-centro';

export type PropsAgendar = {
    /** Endereço da agenda por trás desta janela: é a ele que a inscrição aberta daqui volta. */
    fechar_url?: string;
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
        reserva_sangue: string | null;
        comentarios: string;
    } | null;
    espera: {
        id: number;
        utente: { id: number | null; nome: string | null; numero_processo: number | null; sinalizado?: boolean };
        intervencoes: string[];
        responsavel_id: number | null;
    };
    sinalizacao?: DadosSinalizacao | null;
    /** Doente operado: só se consulta (um administrador pode alterar). */
    somente_consulta?: boolean;
    dominios: { locais: Opcao[]; salas: Opcao[]; tipos: Opcao[]; estados: Opcao[]; responsaveis: Responsavel[] };
    hoje: string;
    dataSugerida: string | null;
};

type Props = PropsAgendar & { fechar: () => void };

export function FormularioAgendar({ agendamento, espera, sinalizacao, somente_consulta, dominios, hoje, dataSugerida, fechar, fechar_url }: Props) {
    const euResponsavel = useResponsavelPorOmissao(dominios.responsaveis);
    const form = useForm({
        data: agendamento?.data ?? dataSugerida ?? '',
        hora: agendamento?.hora ?? '',
        // O período já não se escolhe: os agendamentos novos ficam de manhã e os que já existem mantêm o seu.
        periodo_id: (agendamento?.periodo_id ?? 1) as number | '',
        local_id: (agendamento?.local_id ?? 1) as number | '',
        sala_id: (agendamento?.sala_id ?? '') as number | '',
        tipo_id: (agendamento?.tipo_id ?? 1) as number | '',
        responsavel_id: (agendamento?.responsavel_id ?? espera.responsavel_id ?? euResponsavel) as number | '',
        estado: (agendamento?.estado ?? 3) as number | '',
        reserva_sangue: (agendamento?.reserva_sangue ? Number(agendamento.reserva_sangue) : 1) as number | '',
        comentarios: agendamento?.comentarios ?? '',
    });
    const { data, setData, processing } = form;
    // Só se escolhe entre Agendado, Operado e Cancelado; um agendamento que já tem outro estado (pendente…) mantém-no.
    const estadosEscolhiveis = dominios.estados.filter((e) => [3, 4, 5].includes(e.id) || e.id === agendamento?.estado);
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
                    {espera.utente.nome} · <NumeroProcesso valor={espera.utente.numero_processo} /> ·{' '}
                    {espera.intervencoes.join(', ') || 'sem intervenção indicada'}
                </DialogDescription>
            </DialogHeader>
            {!agendamento && espera.utente.id !== null && (
                <PerguntaCentroReferencia
                    utente={{ id: espera.utente.id, nome: espera.utente.nome ?? '', sinalizado: espera.utente.sinalizado }}
                    sinalizacao={sinalizacao}
                />
            )}
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
                {somente_consulta && (
                    <p role="note" className="rounded-md border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-800">
                        Doente operado: o agendamento só se consulta. Apenas um administrador o pode alterar.
                    </p>
                )}
                <fieldset disabled={somente_consulta} className="space-y-5">
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
                                <Selecao id="estado" opcoes={estadosEscolhiveis} valor={data.estado} aoMudar={(v) => setData('estado', v)} />
                            </Campo>
                            <Campo rotulo="Sangue" para="sangue" erro={erros.reserva_sangue}>
                                <Selecao
                                    id="sangue"
                                    opcoes={OPCOES_SANGUE}
                                    valor={data.reserva_sangue}
                                    aoMudar={(v) => setData('reserva_sangue', v)}
                                />
                            </Campo>
                        </div>
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
                </fieldset>

                <div className="flex flex-wrap gap-3">
                    {!somente_consulta && (
                        <Botao type="submit" disabled={processing}>
                            {agendamento ? 'Guardar alterações' : 'Agendar'}
                        </Botao>
                    )}
                    <Botao variante="secundario" onClick={fechar}>
                        {somente_consulta ? 'Fechar' : 'Cancelar'}
                    </Botao>
                    <BotaoLigacao
                        href={`/agendamento/lista-de-espera/${espera.id}?origem=agenda&retorno=${encodeURIComponent(fechar_url ?? '/agendamento')}`}
                        variante="secundario"
                    >
                        Ver inscrição
                    </BotaoLigacao>
                    {agendamento && !somente_consulta && (
                        <Botao variante="perigo" className="ml-auto" onClick={apagar}>
                            Apagar agendamento
                        </Botao>
                    )}
                </div>
            </form>
        </>
    );
}
