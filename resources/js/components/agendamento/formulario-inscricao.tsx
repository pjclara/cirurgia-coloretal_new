import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { COR_ESTADO, nomeEstado } from '../../clinico/agendamento';
import { formatarData } from '../../clinico/formato';
import { useResponsavelPorOmissao } from '../../clinico/responsavel';
import type { Opcao, OpcaoAgrupada, Responsavel } from '../../clinico/tipos';
import { Botao, BotaoLigacao, Campo, Seccao, Selecao, SelecaoResponsavel, estiloEntrada } from '../clinico/campos';
import { EscolhaMultipla } from '../clinico/escolha-multipla';
import { NumeroProcesso } from '../clinico/numero-processo';
import { PerguntaCentroReferencia, type DadosSinalizacao } from '../referenciacao/pergunta-centro';

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
        pode_agendar: boolean;
        /** Doente operado: só se consulta (um administrador pode alterar). */
        somente_consulta?: boolean;
        agendamentos: { id: number; data: string | null; estado: number | null }[];
    } | null;
    utente: { id: number; nome: string; numero_processo: number; sinalizado?: boolean };
    sinalizacao?: DadosSinalizacao | null;
    /** Inscrições do utente ainda por operar: avisa-se para não se duplicar. */
    inscricoes_ativas?: {
        id: number;
        data: string | null;
        estado: number;
        intervencoes: string[];
        agendamento: string | null;
        pode_agendar: boolean;
    }[];
    /** Diagnósticos CR que o utente já tem registados: a inscrição nova já os traz. */
    diagnosticos_cr_do_utente?: number[];
    dominios: { prioridades: Opcao[]; responsaveis: Responsavel[]; diagnosticos: OpcaoAgrupada[]; intervencoes: OpcaoAgrupada[] };
    hoje: string;
    estados: Opcao[];
    /** Aberta por cima da agenda: ao gravar ou cancelar volta a ela (ao endereço `retorno`). */
    origem?: 'agenda' | null;
    retorno?: string | null;
};

type Props = PropsInscricao & { fechar: () => void };

export function FormularioInscricao({
    espera,
    utente,
    sinalizacao,
    inscricoes_ativas,
    diagnosticos_cr_do_utente,
    dominios,
    hoje,
    estados,
    origem,
    retorno,
    fechar,
}: Props) {
    const [avisoAberto, setAvisoAberto] = useState(true);
    const euResponsavel = useResponsavelPorOmissao(dominios.responsaveis);
    const form = useForm({
        prioridade_id: (espera?.prioridade_id ?? 1) as number | '',
        data_de_lista: espera?.data_de_lista ?? hoje,
        responsavel_id: (espera?.responsavel_id ?? euResponsavel) as number | '',
        comentarios: espera?.comentarios ?? '',
        intervencoes: espera?.intervencoes ?? ([] as number[]),
        diagnosticos: espera?.diagnosticos ?? [...(diagnosticos_cr_do_utente ?? [])],
    });
    const { data, setData, processing } = form;
    const erros = form.errors as Record<string, string | undefined>;

    const guardar = (e: FormEvent) => {
        e.preventDefault();
        if (origem === 'agenda') form.transform((atual) => ({ ...atual, origem, retorno: retorno ?? '/agendamento' }));
        if (espera) form.put(`/agendamento/lista-de-espera/${espera.id}`, { preserveScroll: true });
        else form.post(`/agendamento/utentes/${utente.id}/inscrever`, { preserveScroll: true });
    };

    const cancelar = () => {
        if (espera && window.confirm('Cancelar esta inscrição em lista de espera?'))
            router.post(
                `/agendamento/lista-de-espera/${espera.id}/cancelar`,
                origem === 'agenda' ? { origem, retorno: retorno ?? '/agendamento' } : {},
            );
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle>{espera ? 'Inscrição em lista de espera' : 'Inscrever em lista de espera'}</DialogTitle>
                <DialogDescription className="tabular-nums">
                    {utente.nome} · <NumeroProcesso valor={utente.numero_processo} />
                </DialogDescription>
            </DialogHeader>
            {!espera && inscricoes_ativas && inscricoes_ativas.length > 0 && (
                <Dialog open={avisoAberto} onOpenChange={(aberto) => !aberto && fechar()}>
                    <DialogContent className="max-w-lg" onInteractOutside={(e) => e.preventDefault()}>
                        <DialogHeader>
                            <DialogTitle>Já existe inscrição por operar</DialogTitle>
                            <DialogDescription>
                                {utente.nome} já tem {inscricoes_ativas.length === 1 ? 'uma inscrição' : `${inscricoes_ativas.length} inscrições`} em
                                lista de espera. Confirme que não é uma duplicação.
                            </DialogDescription>
                        </DialogHeader>
                        <ul className="space-y-2 text-sm">
                            {inscricoes_ativas.map((i) => (
                                <li
                                    key={i.id}
                                    className="flex flex-wrap items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-amber-950"
                                >
                                    <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${COR_ESTADO[i.estado] ?? COR_ESTADO[6]}`}>
                                        {nomeEstado(estados, i.estado)}
                                    </span>
                                    <span className="tabular-nums">
                                        Inscrito em {i.data ? formatarData(i.data) : '—'}
                                        {i.agendamento ? ` · cirurgia a ${formatarData(i.agendamento)}` : ''}
                                    </span>
                                    {i.intervencoes.length > 0 && <span>· {i.intervencoes.join(', ')}</span>}
                                    <a
                                        href={`/agendamento/lista-de-espera/${i.id}${i.pode_agendar ? '/agendar' : ''}`}
                                        className="ml-auto font-medium underline"
                                    >
                                        {i.pode_agendar ? 'Agendar' : 'Abrir'}
                                    </a>
                                </li>
                            ))}
                        </ul>
                        <DialogFooter className="gap-2">
                            <Botao variante="secundario" onClick={fechar}>
                                Cancelar
                            </Botao>
                            <Botao variante="primario" onClick={() => setAvisoAberto(false)}>
                                Inscrever mesmo assim
                            </Botao>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
            {!espera && <PerguntaCentroReferencia utente={utente} sinalizacao={sinalizacao} />}
            <form onSubmit={guardar} noValidate className="space-y-5">
                {espera?.somente_consulta && (
                    <p role="note" className="rounded-md border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-800">
                        Doente operado: a inscrição só se consulta. Apenas um administrador a pode alterar.
                    </p>
                )}
                <fieldset disabled={espera?.somente_consulta} className="space-y-5">
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
                                    id="responsavel"
                                    responsaveis={dominios.responsaveis}
                                    valor={data.responsavel_id}
                                    aoMudar={(v) => setData('responsavel_id', v)}
                                    invalido={!!erros.responsavel_id}
                                />
                            </Campo>
                        </div>
                    </Seccao>

                    <div className="grid items-start gap-5 md:grid-cols-2">
                        <Seccao titulo="Diagnósticos">
                            <EscolhaMultipla
                                opcoes={dominios.diagnosticos}
                                valor={data.diagnosticos}
                                aoMudar={(v) => setData('diagnosticos', v)}
                                procurar="Procurar diagnóstico"
                                vazio="Nenhum diagnóstico escolhido."
                            />
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
                    </div>

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
                </fieldset>

                <div className="flex flex-wrap gap-3">
                    {!espera?.somente_consulta && (
                        <Botao type="submit" disabled={processing}>
                            {espera ? 'Guardar alterações' : 'Inscrever'}
                        </Botao>
                    )}
                    {espera && espera.pode_agendar && (
                        <BotaoLigacao href={`/agendamento/lista-de-espera/${espera.id}/agendar`} variante="secundario">
                            Agendar cirurgia
                        </BotaoLigacao>
                    )}
                    <Botao variante="secundario" onClick={fechar}>
                        Cancelar
                    </Botao>
                    {espera && espera.estado !== 4 && !espera.somente_consulta && (
                        <Botao variante="perigo" className="ml-auto" onClick={cancelar}>
                            Cancelar inscrição
                        </Botao>
                    )}
                </div>
            </form>
        </>
    );
}
