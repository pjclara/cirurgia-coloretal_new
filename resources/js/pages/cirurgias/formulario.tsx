import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { formatarData } from '../../clinico/formato';
import type { Opcao, OpcaoAgrupada } from '../../clinico/tipos';
import { Botao, BotaoLigacao, Campo, Selecao, estiloEntrada } from '../../components/clinico/campos';
import { EscolhaMultipla } from '../../components/clinico/escolha-multipla';
import ClinicoLayout from '../../layouts/clinico-layout';

type Cirurgia = {
    id: number;
    data_de_inicio: string | null;
    tipo_de_cirurgia_id: number | null;
    tipo_de_abordagem_id: number | null;
    causa_de_conversao: string;
    re_intervencao_nao_programada_id: number | null;
    comentarios: string;
    intervencoes: number[];
};

export type PropsCirurgia = {
    cirurgia: Cirurgia | null;
    internamento: { id: number; entrada: string | null; motivo: string | null };
    utente: { id: number; nome: string | null; numero_processo: number | null };
    dominios: { tipos: Opcao[]; abordagens: Opcao[]; reintervencoes: Opcao[]; intervencoes: OpcaoAgrupada[] };
    conversao: number;
    hoje: string;
    podeEditar: boolean;
    podeApagar: boolean;
};

/** Reintervenção não programada: a tabela de referência tem 1 = Não, 2 = Sim (e 3 = Outro, só em registos antigos). */
const REINTERVENCAO_NAO = 1;
const REINTERVENCAO_SIM = 2;

type Props = PropsCirurgia;

/** O formulário da cirurgia, sem página à volta; usado na página própria e na modal do quadro. */
export function CorpoFormularioCirurgia({
    cirurgia,
    internamento,
    dominios,
    conversao,
    hoje,
    podeEditar,
    podeApagar,
    utente,
    origem,
    aoFechar,
}: PropsCirurgia & { origem?: 'quadro'; aoFechar?: () => void }) {
    const form = useForm({
        data_de_inicio: cirurgia?.data_de_inicio ?? hoje,
        tipo_de_cirurgia_id: (cirurgia?.tipo_de_cirurgia_id ?? '') as number | '',
        tipo_de_abordagem_id: (cirurgia?.tipo_de_abordagem_id ?? '') as number | '',
        causa_de_conversao: cirurgia?.causa_de_conversao ?? '',
        re_intervencao_nao_programada_id: (cirurgia?.re_intervencao_nao_programada_id ?? REINTERVENCAO_NAO) as number | '',
        comentarios: cirurgia?.comentarios ?? '',
        intervencoes: cirurgia?.intervencoes ?? ([] as number[]),
    });
    const { data, setData, processing } = form;
    const erros = form.errors as Record<string, string | undefined>;

    const guardar = (evento: FormEvent) => {
        evento.preventDefault();
        form.transform((d) => ({
            ...d,
            causa_de_conversao: d.tipo_de_abordagem_id === conversao ? d.causa_de_conversao : '',
            ...(origem ? { origem } : {}),
        }));
        if (cirurgia) form.put(`/cirurgias/${cirurgia.id}`, { preserveScroll: true });
        else form.post(`/internamentos/${internamento.id}/cirurgias`, { preserveScroll: true });
    };

    const apagar = () => {
        if (cirurgia && window.confirm('Apagar esta cirurgia?')) router.delete(`/cirurgias/${cirurgia.id}${origem ? `?origem=${origem}` : ''}`);
    };

    return (
        <form onSubmit={guardar} noValidate>
            <fieldset disabled={!podeEditar} className="grid gap-x-6 gap-y-3 lg:grid-cols-2">
                <div className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Campo rotulo="Data" para="data" erro={erros.data_de_inicio}>
                            <input
                                id="data"
                                type="date"
                                max={hoje}
                                value={data.data_de_inicio}
                                onChange={(e) => setData('data_de_inicio', e.target.value)}
                                className={estiloEntrada}
                                aria-invalid={!!erros.data_de_inicio}
                            />
                        </Campo>
                        <Campo rotulo="Tipo de cirurgia" para="tipo" erro={erros.tipo_de_cirurgia_id}>
                            <Selecao
                                id="tipo"
                                opcoes={dominios.tipos}
                                valor={data.tipo_de_cirurgia_id}
                                aoMudar={(v) => setData('tipo_de_cirurgia_id', v)}
                                invalido={!!erros.tipo_de_cirurgia_id}
                            />
                        </Campo>
                        <Campo rotulo="Abordagem" para="abordagem" erro={erros.tipo_de_abordagem_id}>
                            <Selecao
                                id="abordagem"
                                opcoes={dominios.abordagens}
                                valor={data.tipo_de_abordagem_id}
                                aoMudar={(v) => setData('tipo_de_abordagem_id', v)}
                                invalido={!!erros.tipo_de_abordagem_id}
                            />
                        </Campo>
                    </div>

                    {data.tipo_de_abordagem_id === conversao && (
                        <Campo rotulo="Causa da conversão" para="conversao" erro={erros.causa_de_conversao} opcional>
                            <input
                                id="conversao"
                                value={data.causa_de_conversao}
                                maxLength={255}
                                onChange={(e) => setData('causa_de_conversao', e.target.value)}
                                className={estiloEntrada}
                            />
                        </Campo>
                    )}

                    <div className="space-y-3">
                        <div role="group" aria-labelledby="reint-rotulo">
                            <p id="reint-rotulo" className="mb-1 text-sm font-medium text-slate-800">
                                Reintervenção não programada
                            </p>
                            <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5">
                                {[
                                    { id: REINTERVENCAO_NAO, rotulo: 'Não' },
                                    { id: REINTERVENCAO_SIM, rotulo: 'Sim' },
                                    ...(data.re_intervencao_nao_programada_id !== REINTERVENCAO_NAO &&
                                    data.re_intervencao_nao_programada_id !== REINTERVENCAO_SIM
                                        ? [{ id: Number(data.re_intervencao_nao_programada_id), rotulo: 'Outro (registo antigo)' }]
                                        : []),
                                ].map((o) => (
                                    <button
                                        key={o.id}
                                        type="button"
                                        aria-pressed={data.re_intervencao_nao_programada_id === o.id}
                                        onClick={() => setData('re_intervencao_nao_programada_id', o.id)}
                                        className={`rounded px-4 py-1.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-teal-700 ${
                                            data.re_intervencao_nao_programada_id === o.id
                                                ? 'bg-slate-900 text-white'
                                                : 'text-slate-700 hover:bg-slate-100'
                                        }`}
                                    >
                                        {o.rotulo}
                                    </button>
                                ))}
                            </div>
                            {erros.re_intervencao_nao_programada_id && (
                                <p className="mt-1 text-sm text-rose-700" role="alert">
                                    {erros.re_intervencao_nao_programada_id}
                                </p>
                            )}
                        </div>
                        <Campo rotulo="Comentários" para="comentarios" erro={erros.comentarios} opcional>
                            <textarea
                                id="comentarios"
                                rows={2}
                                maxLength={2000}
                                value={data.comentarios}
                                onChange={(e) => setData('comentarios', e.target.value)}
                                className={`${estiloEntrada} resize-none`}
                            />
                        </Campo>
                    </div>
                </div>

                <div>
                    <p className="mb-1 text-sm font-medium text-slate-800">
                        Intervenções <span className="font-normal text-slate-600">(pelo menos uma)</span>
                    </p>
                    <EscolhaMultipla
                        opcoes={dominios.intervencoes}
                        valor={data.intervencoes}
                        aoMudar={(v) => setData('intervencoes', v)}
                        procurar="Procurar intervenção"
                        desativado={!podeEditar}
                        vazio="Nenhuma intervenção escolhida."
                        alturaLista="max-h-64"
                    />
                    {erros.intervencoes && (
                        <p className="mt-2 text-sm text-rose-700" role="alert">
                            {erros.intervencoes}
                        </p>
                    )}
                </div>
            </fieldset>

            <div className="mt-3 flex flex-wrap items-center gap-3">
                {podeEditar && (
                    <Botao type="submit" disabled={processing}>
                        {cirurgia ? 'Guardar alterações' : 'Registar cirurgia'}
                    </Botao>
                )}
                {aoFechar ? (
                    <Botao variante="secundario" onClick={aoFechar}>
                        {podeEditar ? 'Cancelar' : 'Fechar'}
                    </Botao>
                ) : (
                    <BotaoLigacao href={`/utentes/${utente.id}/cirurgias`} variante="secundario">
                        {podeEditar ? 'Cancelar' : 'Voltar à lista'}
                    </BotaoLigacao>
                )}
                {podeApagar && (
                    <Botao variante="perigo" className="ml-auto" onClick={apagar}>
                        Apagar cirurgia
                    </Botao>
                )}
            </div>
        </form>
    );
}

export default function FormularioCirurgia(props: Props) {
    const { cirurgia, utente, internamento } = props;
    return (
        <ClinicoLayout
            titulo={cirurgia ? 'Cirurgia' : 'Nova cirurgia'}
            subtitulo={
                <span className="tabular-nums">
                    {utente.nome} · Processo {utente.numero_processo} · Internamento de {formatarData(internamento.entrada)}
                </span>
            }
        >
            <CorpoFormularioCirurgia {...props} />
        </ClinicoLayout>
    );
}

FormularioCirurgia.layout = (pagina: React.ReactNode) => pagina;
