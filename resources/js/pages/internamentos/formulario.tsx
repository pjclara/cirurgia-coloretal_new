import { Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { diasEntre, formatarData, idade, plural } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import {
    ALTA_CONCLUIDA,
    ALTA_PENDENTE,
    CLAVIEN_GRAU_5,
    DESTINO_FALECIDO,
    SEM_COMPLICACOES,
    type Opcao,
    type OpcaoAgrupada,
    type Responsavel,
    type ResumoUtente,
} from '../../clinico/tipos';
import { Botao, BotaoLigacao, Campo, Seccao, Selecao, estiloEntrada } from '../../components/clinico/campos';
import { EscolhaMultipla } from '../../components/clinico/escolha-multipla';
import { PesquisaUtente } from '../../components/clinico/pesquisa-utente';
import ClinicoLayout from '../../layouts/clinico-layout';

type ComplicacaoEscolhida = { id: number; resolucoes: number[] };

type Dados = {
    utente_id: number | '';
    cama: string;
    localizacao_id: number | '';
    data_de_entrada: string;
    origem_do_internamento_id: number | '';
    responsavel_id: number | '';
    motivo_internamento: string;
    caso_social_id: number | '';
    comentarios: string;
    bloquear_tabela: boolean;
    diagnosticos: number[];
    complicacoes: ComplicacaoEscolhida[];
    clavien_dindo_id: number | '';
    data_de_alta: string;
    data_de_saida: string;
    estado_da_alta_id: number;
    destino_id: number | '';
};

type Registo = {
    id: number;
    utente_id: number;
    cama: string;
    localizacao_id: number;
    data_de_entrada: string | null;
    origem_do_internamento_id: number;
    responsavel_id: number;
    motivo_internamento: string;
    caso_social_id: number | null;
    comentarios: string;
    bloquear_tabela: boolean;
    diagnosticos: number[];
    complicacoes: ComplicacaoEscolhida[];
    clavien_dindo_id: number | null;
    data_de_alta: string | null;
    data_de_saida: string | null;
    estado_da_alta_id: number;
    destino_id: number | null;
};

type Props = {
    internamento: Registo | null;
    utente: ResumoUtente | null;
    dominios: {
        localizacoes: Opcao[];
        origens: Opcao[];
        estados_da_alta: Opcao[];
        destinos: Opcao[];
        casos_sociais: Opcao[];
        clavien: Opcao[];
        resolucoes: Opcao[];
        diagnosticos: OpcaoAgrupada[];
        complicacoes: OpcaoAgrupada[];
        responsaveis: Responsavel[];
    };
    cirurgias: number;
    podeNovaCirurgia: boolean;
    listaCirurgias: { data: string; tipo: string | null; intervencoes: string[] }[];
    podeEditar: boolean;
    podeDesbloquear: boolean;
    podeApagar: boolean;
    limites: { data_minima: string; hoje: string };
};

export default function FormularioInternamento({
    internamento,
    utente: utenteInicial,
    dominios,
    cirurgias,
    listaCirurgias,
    podeEditar,
    podeNovaCirurgia,
    podeDesbloquear,
    podeApagar,
    limites,
}: Props) {
    const [utente, setUtente] = useState<ResumoUtente | null>(utenteInicial);
    const [confirmarApagar, setConfirmarApagar] = useState(false);

    const form = useForm<Dados>({
        utente_id: internamento?.utente_id ?? utenteInicial?.id ?? '',
        cama: internamento?.cama ?? '',
        localizacao_id: internamento?.localizacao_id ?? '',
        data_de_entrada: internamento?.data_de_entrada ?? limites.hoje,
        origem_do_internamento_id: internamento?.origem_do_internamento_id ?? '',
        responsavel_id: internamento?.responsavel_id ?? '',
        motivo_internamento: internamento?.motivo_internamento ?? '',
        caso_social_id: internamento?.caso_social_id ?? 1,
        comentarios: internamento?.comentarios ?? '',
        bloquear_tabela: internamento?.bloquear_tabela ?? false,
        diagnosticos: internamento?.diagnosticos ?? [],
        complicacoes: internamento?.complicacoes ?? [],
        clavien_dindo_id: internamento?.clavien_dindo_id ?? '',
        data_de_alta: internamento?.data_de_alta ?? '',
        data_de_saida: internamento?.data_de_saida ?? '',
        estado_da_alta_id: internamento?.estado_da_alta_id ?? ALTA_PENDENTE,
        destino_id: internamento?.destino_id ?? '',
    });

    const { data, setData, processing } = form;
    const erros = form.errors as Record<string, string | undefined>;

    const idsComplicacoes = data.complicacoes.map((c) => c.id);
    const semComplicacoes = idsComplicacoes.includes(SEM_COMPLICACOES);
    const temComplicacaoReal = idsComplicacoes.some((id) => id !== SEM_COMPLICACOES);
    const obito = data.destino_id === DESTINO_FALECIDO;
    const clavienFixo = temComplicacaoReal && obito;
    const bloqueadoAntes = internamento?.bloquear_tabela ?? false;

    const dias = data.data_de_entrada ? diasEntre(data.data_de_entrada, data.data_de_alta || limites.hoje) : null;
    const anos = utente ? idade(utente.data_nascimento, data.data_de_entrada || limites.hoje) : null;

    /** "Sem complicações" substitui tudo; escolher outra complicação retira-a. */
    const mudarComplicacoes = (ids: number[]) => {
        const acabouDeEscolherSem = ids.includes(SEM_COMPLICACOES) && !semComplicacoes;
        const finais = acabouDeEscolherSem ? [SEM_COMPLICACOES] : ids.filter((id) => id !== SEM_COMPLICACOES || ids.length === 1);
        const novas = finais.map((id) => data.complicacoes.find((c) => c.id === id) ?? { id, resolucoes: [] });
        const continuaComReal = finais.some((id) => id !== SEM_COMPLICACOES);

        setData((atual) => ({
            ...atual,
            complicacoes: novas,
            clavien_dindo_id: continuaComReal ? atual.clavien_dindo_id : '',
        }));
    };

    const alternarResolucao = (complicacaoId: number, resolucaoId: number) => {
        setData(
            'complicacoes',
            data.complicacoes.map((c) =>
                c.id !== complicacaoId
                    ? c
                    : {
                          ...c,
                          resolucoes: c.resolucoes.includes(resolucaoId)
                              ? c.resolucoes.filter((r) => r !== resolucaoId)
                              : [...c.resolucoes, resolucaoId],
                      },
            ),
        );
    };

    /** Preencher a data de alta propõe "Concluída"; apagá-la volta a "Pendente". */
    const mudarDataDeAlta = (valor: string) => {
        setData((atual) => ({
            ...atual,
            data_de_alta: valor,
            data_de_saida: valor ? atual.data_de_saida || valor : '',
            estado_da_alta_id: valor ? (atual.data_de_alta ? atual.estado_da_alta_id : ALTA_CONCLUIDA) : ALTA_PENDENTE,
        }));
    };

    const mudarDiagnosticos = (ids: number[]) => {
        const primeiro = dominios.diagnosticos.find((d) => d.id === ids[0]);
        setData((atual) => ({
            ...atual,
            diagnosticos: ids,
            motivo_internamento: atual.motivo_internamento.trim() === '' && primeiro ? primeiro.nome : atual.motivo_internamento,
        }));
    };

    const guardar = (evento: FormEvent) => {
        evento.preventDefault();
        form.transform((atual) => ({
            ...atual,
            clavien_dindo_id: clavienFixo ? CLAVIEN_GRAU_5 : atual.clavien_dindo_id,
        }));
        const opcoes = { preserveScroll: true };
        if (internamento) form.put(rotas.internamentos.registo(internamento.id), opcoes);
        else form.post(rotas.internamentos.lista, opcoes);
    };

    const apagar = () => {
        if (internamento) router.delete(rotas.internamentos.registo(internamento.id));
    };

    const nomeComplicacao = (id: number) => dominios.complicacoes.find((c) => c.id === id)?.nome ?? `Complicação ${id}`;
    const numeroDeErros = Object.keys(erros).length;

    return (
        <ClinicoLayout
            titulo={internamento ? 'Internamento' : 'Registar internamento'}
            subtitulo={
                internamento && data.data_de_entrada ? (
                    <span className="tabular-nums">
                        Entrada a {formatarData(data.data_de_entrada)}
                        {dias !== null && dias >= 0 && dias < 3650 && `, ${plural(dias, 'dia', 'dias')}`}
                        {cirurgias > 0 && `, ${plural(cirurgias, 'cirurgia registada', 'cirurgias registadas')}`}
                    </span>
                ) : undefined
            }
            acoes={
                internamento && (
                    <BotaoLigacao href={rotas.utentes.ficha(internamento.utente_id)} variante="secundario">
                        Ver ficha do utente
                    </BotaoLigacao>
                )
            }
        >
            {internamento && !podeEditar && (
                <p className="mb-4 rounded-md border border-slate-300 bg-slate-100 px-4 py-2.5 text-sm text-slate-800">
                    {bloqueadoAntes
                        ? 'Este registo está bloqueado. Para o alterar, peça a um administrador que o desbloqueie.'
                        : 'Pode consultar este registo, mas a sua conta não tem permissão para o alterar.'}
                </p>
            )}

            {numeroDeErros > 0 && (
                <p role="alert" className="mb-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800">
                    O registo não foi guardado. Corrija {plural(numeroDeErros, 'campo assinalado', 'campos assinalados')} em baixo.
                </p>
            )}

            {internamento && (
                <div className="mb-5 max-w-4xl">
                    <Seccao titulo="Cirurgias" descricao={listaCirurgias.length > 0 ? plural(listaCirurgias.length, 'registo', 'registos') : undefined}>
                        {listaCirurgias.length === 0 && <p className="text-sm text-slate-600">Sem cirurgias registadas neste internamento.</p>}
                        <ul className="space-y-1.5">
                            {listaCirurgias.map((c, n) => (
                                <li key={n} className="text-sm font-medium text-[#7d1830]">
                                    {formatarData(c.data)}
                                    {c.tipo ? ` (${c.tipo})` : ''}
                                    {c.intervencoes.length > 0 ? `: ${c.intervencoes.join(', ')}` : ''}
                                </li>
                            ))}
                        </ul>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {utente && (
                                <BotaoLigacao href={`/utentes/${utente.id}/cirurgias`} variante="secundario">
                                    Ver cirurgias do utente
                                </BotaoLigacao>
                            )}
                            {podeNovaCirurgia && <BotaoLigacao href={`/internamentos/${internamento.id}/cirurgias/nova`}>Nova cirurgia</BotaoLigacao>}
                        </div>
                    </Seccao>
                </div>
            )}

            <form onSubmit={guardar} noValidate>
                <fieldset disabled={!podeEditar} className="max-w-4xl space-y-5">
                    <Seccao titulo="Utente">
                        {utente ? (
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p className="text-base font-semibold text-slate-900">{utente.nome}</p>
                                    <p className="text-sm text-slate-600 tabular-nums">
                                        Processo {utente.numero_processo}
                                        {anos !== null && `, ${anos} anos`}
                                        {utente.sexo && `, ${utente.sexo.toLowerCase()}`}
                                    </p>
                                </div>
                                {!internamento && (
                                    <Botao
                                        variante="secundario"
                                        onClick={() => {
                                            setUtente(null);
                                            setData('utente_id', '');
                                        }}
                                    >
                                        Escolher outro utente
                                    </Botao>
                                )}
                            </div>
                        ) : (
                            <div className="max-w-lg">
                                <PesquisaUtente
                                    focoInicial
                                    podeCriar
                                    aoEscolher={(escolhido) => {
                                        setUtente(escolhido);
                                        setData('utente_id', escolhido.id);
                                    }}
                                />
                                <p className="mt-1 text-xs text-slate-500">Procure primeiro. Só crie uma ficha nova se o utente não existir.</p>
                            </div>
                        )}
                        {erros.utente_id && (
                            <p className="mt-2 text-sm text-rose-700" role="alert">
                                {erros.utente_id}
                            </p>
                        )}
                    </Seccao>

                    <Seccao titulo="Entrada">
                        <div className="grid gap-4 sm:grid-cols-6">
                            <Campo rotulo="Data de entrada" para="data_de_entrada" erro={erros.data_de_entrada} className="sm:col-span-2">
                                <input
                                    id="data_de_entrada"
                                    type="date"
                                    min={limites.data_minima}
                                    max={limites.hoje}
                                    value={data.data_de_entrada}
                                    onChange={(e) => setData('data_de_entrada', e.target.value)}
                                    className={estiloEntrada}
                                    aria-invalid={!!erros.data_de_entrada}
                                />
                            </Campo>
                            <Campo rotulo="Origem" para="origem" erro={erros.origem_do_internamento_id} className="sm:col-span-2">
                                <Selecao
                                    id="origem"
                                    opcoes={dominios.origens}
                                    valor={data.origem_do_internamento_id}
                                    aoMudar={(v) => setData('origem_do_internamento_id', v)}
                                    invalido={!!erros.origem_do_internamento_id}
                                />
                            </Campo>
                            <Campo rotulo="Responsável" para="responsavel" erro={erros.responsavel_id} className="sm:col-span-2">
                                <Selecao
                                    id="responsavel"
                                    opcoes={dominios.responsaveis.map((r) => ({
                                        id: r.id,
                                        nome: r.equipa ? `${r.nome} (equipa ${r.equipa})` : r.nome,
                                    }))}
                                    valor={data.responsavel_id}
                                    aoMudar={(v) => setData('responsavel_id', v)}
                                    invalido={!!erros.responsavel_id}
                                />
                            </Campo>
                            <Campo rotulo="Localização" para="localizacao" erro={erros.localizacao_id} className="sm:col-span-4">
                                <Selecao
                                    id="localizacao"
                                    opcoes={dominios.localizacoes}
                                    valor={data.localizacao_id}
                                    aoMudar={(v) => setData('localizacao_id', v)}
                                    invalido={!!erros.localizacao_id}
                                />
                            </Campo>
                            <Campo rotulo="Cama" para="cama" erro={erros.cama} className="sm:col-span-2">
                                <input
                                    id="cama"
                                    value={data.cama}
                                    maxLength={20}
                                    onChange={(e) => setData('cama', e.target.value)}
                                    className={`${estiloEntrada} tabular-nums`}
                                    aria-invalid={!!erros.cama}
                                />
                            </Campo>
                        </div>
                    </Seccao>

                    <Seccao titulo="Diagnósticos" descricao="Escolha pelo menos um. O primeiro preenche o motivo de internamento, que pode ajustar.">
                        <EscolhaMultipla
                            opcoes={dominios.diagnosticos}
                            valor={data.diagnosticos}
                            aoMudar={mudarDiagnosticos}
                            procurar="Procurar diagnóstico"
                            desativado={!podeEditar}
                            vazio="Nenhum diagnóstico escolhido."
                        />
                        {erros.diagnosticos && (
                            <p className="mt-2 text-sm text-rose-700" role="alert">
                                {erros.diagnosticos}
                            </p>
                        )}

                        <Campo rotulo="Motivo de internamento" para="motivo" erro={erros.motivo_internamento} className="mt-4">
                            <input
                                id="motivo"
                                value={data.motivo_internamento}
                                maxLength={255}
                                onChange={(e) => setData('motivo_internamento', e.target.value)}
                                className={estiloEntrada}
                                aria-invalid={!!erros.motivo_internamento}
                            />
                        </Campo>
                    </Seccao>

                    <Seccao titulo="Complicações" descricao='Obrigatório para dar alta. Se não houve nenhuma, escolha "Sem complicações".'>
                        <EscolhaMultipla
                            opcoes={dominios.complicacoes}
                            valor={idsComplicacoes}
                            aoMudar={mudarComplicacoes}
                            procurar="Procurar complicação"
                            desativado={!podeEditar}
                            vazio="Nenhuma complicação registada."
                        />
                        {erros.complicacoes && (
                            <p className="mt-2 text-sm text-rose-700" role="alert">
                                {erros.complicacoes}
                            </p>
                        )}

                        {temComplicacaoReal && (
                            <div className="mt-5 space-y-4 border-t border-slate-200 pt-4">
                                {data.complicacoes
                                    .filter((c) => c.id !== SEM_COMPLICACOES)
                                    .map((c) => (
                                        <fieldset key={c.id}>
                                            <legend className="text-sm font-medium text-slate-800">Como foi resolvida: {nomeComplicacao(c.id)}</legend>
                                            <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1.5">
                                                {dominios.resolucoes.map((r) => (
                                                    <label key={r.id} className="flex cursor-pointer items-center gap-1.5 text-sm text-slate-700">
                                                        <input
                                                            type="checkbox"
                                                            className="size-4 accent-teal-800"
                                                            checked={c.resolucoes.includes(r.id)}
                                                            onChange={() => alternarResolucao(c.id, r.id)}
                                                        />
                                                        {r.nome}
                                                    </label>
                                                ))}
                                            </div>
                                        </fieldset>
                                    ))}

                                <Campo
                                    rotulo="Grau de Clavien-Dindo"
                                    para="clavien"
                                    erro={erros.clavien_dindo_id}
                                    ajuda={clavienFixo ? 'Óbito com complicação: fica registado como grau 5.' : 'O grau mais alto entre as complicações.'}
                                    className="max-w-xs"
                                >
                                    <Selecao
                                        id="clavien"
                                        opcoes={dominios.clavien}
                                        valor={clavienFixo ? CLAVIEN_GRAU_5 : data.clavien_dindo_id}
                                        aoMudar={(v) => setData('clavien_dindo_id', v)}
                                        disabled={clavienFixo}
                                        invalido={!!erros.clavien_dindo_id}
                                    />
                                </Campo>
                            </div>
                        )}
                    </Seccao>

                    <Seccao titulo="Alta" descricao="Deixe a data de alta vazia enquanto o doente estiver internado.">
                        <div className="grid gap-4 sm:grid-cols-6">
                            <Campo rotulo="Data de alta" para="data_de_alta" erro={erros.data_de_alta} className="sm:col-span-2">
                                <input
                                    id="data_de_alta"
                                    type="date"
                                    min={data.data_de_entrada || limites.data_minima}
                                    max={limites.hoje}
                                    value={data.data_de_alta}
                                    onChange={(e) => mudarDataDeAlta(e.target.value)}
                                    className={estiloEntrada}
                                    aria-invalid={!!erros.data_de_alta}
                                />
                            </Campo>
                            <Campo
                                rotulo="Data de saída"
                                para="data_de_saida"
                                erro={erros.data_de_saida}
                                ajuda="Se for diferente da alta, por exemplo num caso social."
                                className="sm:col-span-2"
                            >
                                <input
                                    id="data_de_saida"
                                    type="date"
                                    min={data.data_de_alta || data.data_de_entrada}
                                    max={limites.hoje}
                                    value={data.data_de_saida}
                                    disabled={!data.data_de_alta}
                                    onChange={(e) => setData('data_de_saida', e.target.value)}
                                    className={estiloEntrada}
                                    aria-invalid={!!erros.data_de_saida}
                                />
                            </Campo>
                            <Campo rotulo="Estado da alta" para="estado" erro={erros.estado_da_alta_id} className="sm:col-span-2">
                                <select
                                    id="estado"
                                    value={data.estado_da_alta_id}
                                    onChange={(e) => setData('estado_da_alta_id', Number(e.target.value))}
                                    className={estiloEntrada}
                                    aria-invalid={!!erros.estado_da_alta_id}
                                >
                                    {dominios.estados_da_alta.map((e) => (
                                        <option key={e.id} value={e.id} disabled={e.id === ALTA_CONCLUIDA && !data.data_de_alta}>
                                            {e.nome}
                                        </option>
                                    ))}
                                </select>
                            </Campo>
                            <Campo rotulo="Destino" para="destino" erro={erros.destino_id} className="sm:col-span-3">
                                <Selecao
                                    id="destino"
                                    opcoes={dominios.destinos}
                                    valor={data.destino_id}
                                    aoMudar={(v) => setData('destino_id', v)}
                                    vazio={data.data_de_alta ? 'Escolher…' : 'Ainda internado'}
                                    invalido={!!erros.destino_id}
                                />
                            </Campo>
                            <Campo rotulo="Caso social" para="caso_social" erro={erros.caso_social_id} className="sm:col-span-3">
                                <Selecao
                                    id="caso_social"
                                    opcoes={dominios.casos_sociais}
                                    valor={data.caso_social_id}
                                    aoMudar={(v) => setData('caso_social_id', v)}
                                    vazio="Sem informação"
                                />
                            </Campo>
                            <Campo rotulo="Comentários" para="comentarios" erro={erros.comentarios} className="sm:col-span-6" opcional>
                                <textarea
                                    id="comentarios"
                                    rows={3}
                                    value={data.comentarios}
                                    onChange={(e) => setData('comentarios', e.target.value)}
                                    className={estiloEntrada}
                                />
                            </Campo>
                        </div>

                        <label className="mt-4 flex items-start gap-2 text-sm text-slate-800">
                            <input
                                type="checkbox"
                                className="mt-0.5 size-4 accent-teal-800"
                                checked={data.bloquear_tabela}
                                disabled={bloqueadoAntes && !podeDesbloquear}
                                onChange={(e) => setData('bloquear_tabela', e.target.checked)}
                            />
                            <span>
                                Bloquear este registo
                                <span className="block text-xs text-slate-500">Depois de bloqueado, só um administrador o pode alterar.</span>
                            </span>
                        </label>
                    </Seccao>
                </fieldset>

                <div className="sticky bottom-0 mt-5 flex max-w-4xl flex-wrap items-center gap-2 border-t border-slate-200 bg-slate-50 py-3">
                    {podeEditar && (
                        <Botao type="submit" disabled={processing}>
                            {internamento ? 'Guardar alterações' : 'Registar internamento'}
                        </Botao>
                    )}
                    <Link href={rotas.internamentos.lista} className="px-2 py-2 text-sm font-medium text-slate-700 hover:underline">
                        {podeEditar ? 'Cancelar' : 'Voltar à lista'}
                    </Link>

                    {internamento && podeApagar && (
                        <div className="ml-auto flex items-center gap-2">
                            {confirmarApagar ? (
                                <>
                                    <span className="text-sm text-slate-700">Apagar este internamento?</span>
                                    <Botao variante="perigo" onClick={apagar}>
                                        Apagar internamento
                                    </Botao>
                                    <Botao variante="secundario" onClick={() => setConfirmarApagar(false)}>
                                        Manter
                                    </Botao>
                                </>
                            ) : (
                                <Botao variante="perigo" onClick={() => setConfirmarApagar(true)}>
                                    Apagar internamento
                                </Botao>
                            )}
                        </div>
                    )}
                </div>
            </form>
        </ClinicoLayout>
    );
}

FormularioInternamento.layout = (pagina: ReactNode) => pagina;
