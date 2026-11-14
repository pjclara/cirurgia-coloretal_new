import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { formatarData } from '../../clinico/formato';
import type { Opcao, OpcaoAgrupada } from '../../clinico/tipos';
import { Botao, BotaoLigacao, Campo, Seccao, Selecao, estiloEntrada } from '../../components/clinico/campos';
import { EscolhaMultipla } from '../../components/clinico/escolha-multipla';
import ClinicoLayout from '../../layouts/clinico-layout';

type Cirurgia = {
    id: number;
    data_de_inicio: string | null;
    tipo_de_cirurgia_id: number | null;
    tipo_de_abordagem_id: number | null;
    causa_de_conversao: string;
    resseccao_de_orgao: number;
    re_intervencao_nao_programada_id: number | null;
    comentarios: string;
    intervencoes: number[];
};

type Props = {
    cirurgia: Cirurgia | null;
    internamento: { id: number; entrada: string | null; motivo: string | null };
    utente: { id: number; nome: string | null; numero_processo: number | null };
    dominios: { tipos: Opcao[]; abordagens: Opcao[]; reintervencoes: Opcao[]; intervencoes: OpcaoAgrupada[] };
    conversao: number;
    hoje: string;
    podeEditar: boolean;
    podeApagar: boolean;
};

const RESSECCAO: Opcao[] = [
    { id: 2, nome: 'Sim' },
    { id: 1, nome: 'Não' },
    { id: 99, nome: 'Não aplicável' },
];

export default function FormularioCirurgia({ cirurgia, internamento, utente, dominios, conversao, hoje, podeEditar, podeApagar }: Props) {
    const form = useForm({
        data_de_inicio: cirurgia?.data_de_inicio ?? hoje,
        tipo_de_cirurgia_id: (cirurgia?.tipo_de_cirurgia_id ?? '') as number | '',
        tipo_de_abordagem_id: (cirurgia?.tipo_de_abordagem_id ?? '') as number | '',
        causa_de_conversao: cirurgia?.causa_de_conversao ?? '',
        resseccao_de_orgao: (cirurgia?.resseccao_de_orgao ?? 1) as number | '',
        re_intervencao_nao_programada_id: (cirurgia?.re_intervencao_nao_programada_id ?? 1) as number | '',
        comentarios: cirurgia?.comentarios ?? '',
        intervencoes: cirurgia?.intervencoes ?? ([] as number[]),
    });
    const { data, setData, processing } = form;
    const erros = form.errors as Record<string, string | undefined>;

    const guardar = (evento: FormEvent) => {
        evento.preventDefault();
        form.transform((d) => ({ ...d, causa_de_conversao: d.tipo_de_abordagem_id === conversao ? d.causa_de_conversao : '' }));
        if (cirurgia) form.put(`/cirurgias/${cirurgia.id}`, { preserveScroll: true });
        else form.post(`/internamentos/${internamento.id}/cirurgias`, { preserveScroll: true });
    };

    const apagar = () => {
        if (cirurgia && window.confirm('Apagar esta cirurgia?')) router.delete(`/cirurgias/${cirurgia.id}`);
    };

    return (
        <ClinicoLayout
            titulo={cirurgia ? 'Cirurgia' : 'Nova cirurgia'}
            subtitulo={
                <span className="tabular-nums">
                    {utente.nome} · Processo {utente.numero_processo} · Internamento de {formatarData(internamento.entrada)}
                </span>
            }
        >
            <form onSubmit={guardar} noValidate>
                <fieldset disabled={!podeEditar} className="max-w-4xl space-y-5">
                    <Seccao titulo="Cirurgia">
                        <div className="grid gap-4 sm:grid-cols-3">
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
                            <Campo rotulo="Causa da conversão" para="conversao" erro={erros.causa_de_conversao} opcional className="mt-4">
                                <input
                                    id="conversao"
                                    value={data.causa_de_conversao}
                                    maxLength={255}
                                    onChange={(e) => setData('causa_de_conversao', e.target.value)}
                                    className={estiloEntrada}
                                />
                            </Campo>
                        )}
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <Campo rotulo="Resseção de órgão" para="resseccao" erro={erros.resseccao_de_orgao}>
                                <Selecao
                                    id="resseccao"
                                    opcoes={RESSECCAO}
                                    valor={data.resseccao_de_orgao}
                                    aoMudar={(v) => setData('resseccao_de_orgao', v)}
                                    invalido={!!erros.resseccao_de_orgao}
                                />
                            </Campo>
                            <Campo rotulo="Reintervenção não programada" para="reint" erro={erros.re_intervencao_nao_programada_id}>
                                <Selecao
                                    id="reint"
                                    opcoes={dominios.reintervencoes}
                                    valor={data.re_intervencao_nao_programada_id}
                                    aoMudar={(v) => setData('re_intervencao_nao_programada_id', v)}
                                    invalido={!!erros.re_intervencao_nao_programada_id}
                                />
                            </Campo>
                        </div>
                    </Seccao>

                    <Seccao titulo="Intervenções" descricao="Escolha pelo menos uma.">
                        <EscolhaMultipla
                            opcoes={dominios.intervencoes}
                            valor={data.intervencoes}
                            aoMudar={(v) => setData('intervencoes', v)}
                            procurar="Procurar intervenção"
                            desativado={!podeEditar}
                            vazio="Nenhuma intervenção escolhida."
                        />
                        {erros.intervencoes && (
                            <p className="mt-2 text-sm text-rose-700" role="alert">
                                {erros.intervencoes}
                            </p>
                        )}
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
                </fieldset>

                <div className="mt-5 flex max-w-4xl flex-wrap items-center gap-3">
                    {podeEditar && (
                        <Botao type="submit" disabled={processing}>
                            {cirurgia ? 'Guardar alterações' : 'Registar cirurgia'}
                        </Botao>
                    )}
                    <BotaoLigacao href={`/utentes/${utente.id}/cirurgias`} variante="secundario">
                        {podeEditar ? 'Cancelar' : 'Voltar à lista'}
                    </BotaoLigacao>
                    {podeApagar && (
                        <Botao variante="perigo" className="ml-auto" onClick={apagar}>
                            Apagar cirurgia
                        </Botao>
                    )}
                </div>
            </form>
        </ClinicoLayout>
    );
}

FormularioCirurgia.layout = (pagina: React.ReactNode) => pagina;
