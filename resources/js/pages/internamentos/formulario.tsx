import { DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { corDaCirurgia } from '../../clinico/cor-cirurgia';
import { diasEntre, formatarData, idade, plural } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import {
    ALTA_CONCLUIDA,
    ALTA_PENDENTE,
    SEM_COMPLICACOES,
    type Opcao,
    type OpcaoAgrupada,
    type Responsavel,
    type ResumoUtente,
} from '../../clinico/tipos';
import { type BlocoMorbilidade, type Momento } from '../../components/clinico/bloco-morbilidade';
import { Botao, BotaoLigacao, Campo, Seccao, Selecao, SelecaoResponsavel, estiloEntrada } from '../../components/clinico/campos';
import { EscolhaMultipla } from '../../components/clinico/escolha-multipla';
import { MomentoMorbilidade } from '../../components/clinico/momento-morbilidade';
import { NumeroProcesso } from '../../components/clinico/numero-processo';
import { PesquisaUtente } from '../../components/clinico/pesquisa-utente';
import { PerguntaCentroReferencia } from '../../components/referenciacao/pergunta-centro';
import ClinicoLayout from '../../layouts/clinico-layout';

type RegistoMorbilidade = Record<
    Momento,
    { complicacoes: { id: number; resolucoes: number[] }[]; clavien_dindo_id: number | null; data_registo: string | null }
>;

const MOMENTOS: { id: Momento; rotulo: string; ajuda: string }[] = [
    { id: 'alta', rotulo: 'Na alta', ajuda: 'Obrigatório para dar alta.' },
    { id: '30_dias', rotulo: 'Aos 30 dias', ajuda: 'Seguimento 30 dias depois da alta.' },
];

type Separador = 'dados' | 'notas' | 'cirurgias' | 'morbilidade' | 'alta';

/** Separador onde está o campo com erro (para o abrir quando a gravação falha). */
const separadorDoErro = (campo: string): Separador =>
    campo.startsWith('morbilidade')
        ? 'morbilidade'
        : ['caso_social_id', 'comentarios'].includes(campo)
          ? 'notas'
          : ['data_de_alta', 'data_de_saida', 'estado_da_alta_id', 'destino_id'].includes(campo)
            ? 'alta'
            : 'dados';

const somarDias = (data: string, dias: number) => {
    const d = new Date(data + 'T00:00:00');
    d.setDate(d.getDate() + dias);
    return d.toISOString().slice(0, 10);
};

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
    diagnosticos: number[];
    morbilidade: Record<Momento, BlocoMorbilidade>;
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
    morbilidade: RegistoMorbilidade;
    data_de_alta: string | null;
    data_de_saida: string | null;
    estado_da_alta_id: number;
    destino_id: number | null;
};

export type PropsFormulario = {
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
    listaCirurgias: { id?: number; data: string; tipo: string | null; intervencoes: string[] }[];
    podeEditar: boolean;
    podeDesbloquear: boolean;
    podeApagar: boolean;
    limites: { data_minima: string; hoje: string };
    /** Só ao registar e com permissão: dados para sinalizar o utente para o centro de referência. */
    referenciacao?: { origens: { id: number; nome: string }[]; responsaveis: Responsavel[] } | null;
};

type Props = PropsFormulario & { aoFechar?: () => void; origem?: 'quadro' | 'lista' };

/** Página completa ou, com `modal`, apenas o cabeçalho e o conteúdo para uma janela modal. */
function Quadro({
    modal,
    titulo,
    subtitulo,
    acoes,
    children,
}: {
    modal: boolean;
    titulo: string;
    subtitulo?: ReactNode;
    acoes?: ReactNode;
    children: ReactNode;
}) {
    if (!modal) {
        return (
            <ClinicoLayout titulo={titulo} subtitulo={subtitulo} acoes={acoes}>
                {children}
            </ClinicoLayout>
        );
    }
    return (
        <>
            <DialogHeader>
                <DialogTitle>{titulo}</DialogTitle>
                <DialogDescription asChild>
                    <div>{subtitulo ?? 'Registe a entrada. Morbilidade, alta e cirurgias preenchem-se depois, na página do internamento.'}</div>
                </DialogDescription>
                {acoes && <div className="flex flex-wrap gap-2 pt-1">{acoes}</div>}
            </DialogHeader>
            {children}
        </>
    );
}

export function FormularioInternamento({
    aoFechar,
    origem,
    internamento,
    utente: utenteInicial,
    dominios,
    cirurgias,
    listaCirurgias,
    referenciacao,
    podeEditar,
    podeNovaCirurgia,
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
        diagnosticos: internamento?.diagnosticos ?? [],
        morbilidade: Object.fromEntries(
            MOMENTOS.map(({ id }) => {
                const m = internamento?.morbilidade?.[id];
                return [
                    id,
                    { complicacoes: m?.complicacoes ?? [], clavien_dindo_id: m?.clavien_dindo_id ?? '', data_registo: m?.data_registo ?? '' },
                ];
            }),
        ) as Record<Momento, BlocoMorbilidade>,
        data_de_alta: internamento?.data_de_alta ?? '',
        data_de_saida: internamento?.data_de_saida ?? '',
        estado_da_alta_id: internamento?.estado_da_alta_id ?? ALTA_PENDENTE,
        destino_id: internamento?.destino_id ?? '',
    });

    const { data, setData, processing } = form;
    const erros = form.errors as Record<string, string | undefined>;

    const [separador, setSeparador] = useState<Separador>('dados');
    const [momentoAtivo, setMomentoAtivo] = useState<Momento>('alta');
    const [tentouAlta, setTentouAlta] = useState(false);
    const registado = (m: Momento) => data.morbilidade[m].complicacoes.length > 0;
    /** Com cirurgia, a alta fica pendente até à revisão aos 30 dias. */
    const aguardaRevisao = listaCirurgias.length > 0 && !registado('30_dias');
    const bloqueadoAntes = internamento?.bloquear_tabela ?? false;

    const dias = data.data_de_entrada ? diasEntre(data.data_de_entrada, data.data_de_alta || limites.hoje) : null;
    const anos = utente ? idade(utente.data_nascimento, data.data_de_entrada || limites.hoje) : null;

    /** Preencher a data de alta propõe "Concluída"; apagá-la volta a "Pendente". */
    const mudarDataDeAlta = (valor: string) => {
        setData((atual) => ({
            ...atual,
            data_de_alta: valor,
            data_de_saida: valor ? atual.data_de_saida || valor : '',
            estado_da_alta_id: valor && !aguardaRevisao ? (atual.data_de_alta ? atual.estado_da_alta_id : ALTA_CONCLUIDA) : ALTA_PENDENTE,
        }));
    };

    /** Cancelar a alta: o doente volta a estar internado. Limpa a alta e o seguimento aos 30 dias; fica por guardar. */
    const cancelarAlta = () => {
        if (
            !window.confirm(
                'Cancelar a alta? O doente volta a ficar internado e o seguimento aos 30 dias registado é retirado. Só fica guardado ao carregar em "Guardar".',
            )
        )
            return;
        setData((atual) => ({
            ...atual,
            data_de_alta: '',
            data_de_saida: '',
            destino_id: '',
            estado_da_alta_id: ALTA_PENDENTE,
            morbilidade: { ...atual.morbilidade, '30_dias': { complicacoes: [], clavien_dindo_id: '', data_registo: '' } },
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

    /** Requisitos para dar alta: o que tem de estar preenchido, e onde se preenche. */
    const requisitosDeAlta: { texto: string; feito: boolean; separador: Separador }[] = [
        { texto: 'Destino do doente escolhido', feito: data.destino_id !== '', separador: 'alta' },
        { texto: 'Pelo menos um diagnóstico', feito: data.diagnosticos.length > 0, separador: 'dados' },
        { texto: 'Motivo de internamento preenchido', feito: data.motivo_internamento.trim() !== '', separador: 'dados' },
        // Sem cirurgia não é preciso registar complicações.
        ...(listaCirurgias.length > 0
            ? [{ texto: 'Morbilidade da alta registada (ou "Sem complicações")', feito: registado('alta'), separador: 'morbilidade' as Separador }]
            : []),
    ];
    const pendenciasDeAlta = requisitosDeAlta.filter((r) => !r.feito);

    const guardar = (evento: FormEvent) => {
        evento.preventDefault();
        // Não se dá alta com campos obrigatórios por preencher: abre o separador do primeiro em falta.
        if (data.data_de_alta && pendenciasDeAlta.length > 0) {
            setTentouAlta(true);
            setSeparador(pendenciasDeAlta[0].separador);
            return;
        }
        form.transform((atual) => ({
            ...atual,
            ...(aguardaRevisao ? { estado_da_alta_id: ALTA_PENDENTE } : {}),
            ...(origem ? { origem } : {}),
        }));
        const opcoes = {
            preserveScroll: true,
            onError: (e: Record<string, string>) => {
                const chaves = Object.keys(e);
                if (chaves.length > 0 && !chaves.some((c) => separadorDoErro(c) === separador)) setSeparador(separadorDoErro(chaves[0]));
            },
        };
        if (internamento) form.put(rotas.internamentos.registo(internamento.id), opcoes);
        else form.post(rotas.internamentos.lista, opcoes);
    };

    const apagar = () => {
        if (internamento) router.delete(rotas.internamentos.registo(internamento.id) + (origem ? `?origem=${origem}` : ''));
    };

    const painelDeAlta = (
        <div
            role={tentouAlta && pendenciasDeAlta.length > 0 ? 'alert' : 'status'}
            className={`mb-5 rounded-md border px-4 py-3 text-sm ${
                !data.data_de_alta
                    ? 'border-slate-200 bg-slate-50 text-slate-800'
                    : pendenciasDeAlta.length === 0
                      ? 'border-teal-300 bg-teal-50 text-teal-950'
                      : tentouAlta
                        ? 'border-rose-300 bg-rose-50 text-rose-950'
                        : 'border-amber-300 bg-amber-50 text-amber-950'
            }`}
        >
            <p className="font-semibold">
                {!data.data_de_alta
                    ? 'Antes de dar alta, confirme que está preenchido:'
                    : pendenciasDeAlta.length === 0
                      ? 'Tudo preenchido: pode dar alta.'
                      : `Faltam ${plural(pendenciasDeAlta.length, 'campo obrigatório', 'campos obrigatórios')} para dar alta:`}
            </p>
            <ul className="mt-2 space-y-1">
                {requisitosDeAlta.map((r) => (
                    <li key={r.texto} className="flex items-start gap-2">
                        <span aria-hidden className={r.feito ? 'text-teal-700' : 'text-rose-700'}>
                            {r.feito ? '✓' : '✗'}
                        </span>
                        <span className="sr-only">{r.feito ? 'Preenchido: ' : 'Em falta: '}</span>
                        {r.feito || r.separador === 'alta' ? (
                            <span>{r.texto}</span>
                        ) : (
                            <button type="button" onClick={() => setSeparador(r.separador)} className="text-left underline underline-offset-2">
                                {r.texto}
                            </button>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );

    const numeroDeErros = Object.keys(erros).length;

    return (
        <Quadro
            modal={!!aoFechar}
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

            {utente && (internamento || separador !== 'dados') && (
                <p className="mb-3 flex flex-wrap items-baseline gap-x-4 gap-y-0.5 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 tabular-nums">
                    <span className="font-semibold text-slate-900">{utente.nome}</span>
                    <span>
                        <NumeroProcesso valor={utente.numero_processo} />
                    </span>
                    <span>
                        {anos !== null && `${anos} anos`}
                        {utente.sexo && `${anos !== null ? ', ' : ''}${utente.sexo.toLowerCase()}`}
                    </span>
                </p>
            )}

            {!internamento && <PerguntaCentroReferencia utente={utente} sinalizacao={referenciacao} />}

            <div role="tablist" aria-label="Secções do internamento" className="mb-5 flex flex-wrap gap-1 border-b border-slate-200">
                {(
                    [
                        ['dados', internamento ? 'Dados do internamento' : 'Dados'],
                        ['cirurgias', `Cirurgias${listaCirurgias.length > 0 ? ` (${listaCirurgias.length})` : ''}`],
                        ['morbilidade', 'Morbilidade'],
                        ['notas', 'Notas'],
                        ['alta', 'Alta'],
                    ] as [Separador, string][]
                )
                    .filter(([id]) => internamento || id === 'dados' || id === 'notas')
                    .map(([id, rotulo]) => (
                        <button
                            key={id}
                            type="button"
                            role="tab"
                            aria-selected={separador === id}
                            onClick={() => setSeparador(id)}
                            className={`-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2.5 text-sm ${
                                separador === id
                                    ? 'border-[#a3213e] font-semibold text-slate-900'
                                    : 'border-transparent text-slate-600 hover:text-slate-900'
                            }`}
                        >
                            {rotulo}
                            {Object.keys(erros).some((k) => separadorDoErro(k) === id) && <span className="text-rose-700">!</span>}
                        </button>
                    ))}
            </div>

            {internamento && separador === 'cirurgias' && (
                <div className="mb-5">
                    <Seccao
                        titulo="Cirurgias"
                        descricao={listaCirurgias.length > 0 ? plural(listaCirurgias.length, 'registo', 'registos') : undefined}
                    >
                        {listaCirurgias.length === 0 && <p className="text-sm text-slate-600">Sem cirurgias registadas neste internamento.</p>}
                        <ul className="space-y-1.5">
                            {listaCirurgias.map((c, n) => {
                                const texto = `${formatarData(c.data)}${c.tipo ? ` (${c.tipo})` : ''}${c.intervencoes.length > 0 ? `: ${c.intervencoes.join(', ')}` : ''}`;
                                return (
                                    <li key={n} className={`text-sm font-medium ${corDaCirurgia(c.tipo).texto}`}>
                                        {c.id ? (
                                            <Link
                                                href={origem === 'quadro' ? `/quadro/cirurgias/${c.id}` : `/cirurgias/${c.id}`}
                                                className="hover:underline"
                                            >
                                                {texto}
                                            </Link>
                                        ) : (
                                            texto
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {utente && (
                                <BotaoLigacao href={`/utentes/${utente.id}/cirurgias`} variante="secundario">
                                    Ver cirurgias do utente
                                </BotaoLigacao>
                            )}
                            {podeNovaCirurgia && (
                                <BotaoLigacao
                                    href={
                                        origem === 'quadro'
                                            ? `/quadro/${internamento.id}/cirurgias/nova`
                                            : `/internamentos/${internamento.id}/cirurgias/nova`
                                    }
                                >
                                    Nova cirurgia
                                </BotaoLigacao>
                            )}
                        </div>
                    </Seccao>
                </div>
            )}

            <form onSubmit={guardar} noValidate className={separador === 'cirurgias' ? 'hidden' : undefined}>
                <fieldset disabled={!podeEditar} className="space-y-5">
                    {separador === 'dados' && (
                        <div className="grid items-start gap-5 lg:grid-cols-2">
                            <div className="space-y-5">
                                {!internamento && (
                                    <Seccao titulo="Utente">
                                        {utente ? (
                                            <div className="flex flex-wrap items-center justify-between gap-3">
                                                <div>
                                                    <p className="text-base font-semibold text-slate-900">{utente.nome}</p>
                                                    <p className="text-sm text-slate-600 tabular-nums">
                                                        <NumeroProcesso valor={utente.numero_processo} />
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
                                                <p className="mt-1 text-xs text-slate-500">
                                                    Procure primeiro. Só crie uma ficha nova se o utente não existir.
                                                </p>
                                            </div>
                                        )}
                                        {erros.utente_id && (
                                            <p className="mt-2 text-sm text-rose-700" role="alert">
                                                {erros.utente_id}
                                            </p>
                                        )}
                                    </Seccao>
                                )}

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
                                            <SelecaoResponsavel
                                                id="responsavel"
                                                responsaveis={dominios.responsaveis}
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
                            </div>

                            <Seccao
                                titulo="Diagnósticos"
                                descricao="Escolha pelo menos um. O primeiro preenche o motivo de internamento, que pode ajustar."
                            >
                                <EscolhaMultipla
                                    opcoes={dominios.diagnosticos}
                                    valor={data.diagnosticos}
                                    aoMudar={mudarDiagnosticos}
                                    procurar="Procurar diagnóstico"
                                    desativado={!podeEditar}
                                    vazio="Nenhum diagnóstico escolhido."
                                    alturaLista="max-h-44"
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
                        </div>
                    )}

                    {separador === 'notas' && (
                        <Seccao titulo="Notas">
                            <div className="grid gap-4 sm:grid-cols-6">
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
                        </Seccao>
                    )}

                    {internamento && separador === 'morbilidade' && (
                        <Seccao titulo="Morbilidade" descricao="Escolha “Sem complicações” ou “Com complicações”.">
                            <div role="tablist" aria-label="Momento da morbilidade" className="mb-3 flex gap-1 border-b border-slate-200">
                                {MOMENTOS.map((m) => {
                                    const bloco = data.morbilidade[m.id];
                                    const resumo =
                                        bloco.complicacoes.length === 0
                                            ? 'por registar'
                                            : bloco.complicacoes.every((c) => c.id === SEM_COMPLICACOES)
                                              ? 'sem complicações'
                                              : `${bloco.complicacoes.length} complicaç${bloco.complicacoes.length === 1 ? 'ão' : 'ões'}`;
                                    const comErro = Object.keys(erros).some((k) => k.startsWith(`morbilidade.${m.id}.`));
                                    return (
                                        <button
                                            key={m.id}
                                            type="button"
                                            role="tab"
                                            aria-selected={momentoAtivo === m.id}
                                            onClick={() => setMomentoAtivo(m.id)}
                                            className={`-mb-px flex items-baseline gap-2 border-b-2 px-4 py-2 text-sm ${
                                                momentoAtivo === m.id
                                                    ? 'border-[#a3213e] font-semibold text-slate-900'
                                                    : 'border-transparent text-slate-600 hover:text-slate-900'
                                            }`}
                                        >
                                            {m.rotulo}
                                            <span className="text-xs font-normal text-slate-500">{resumo}</span>
                                            {comErro && <span className="text-rose-700">!</span>}
                                        </button>
                                    );
                                })}
                            </div>
                            <div>
                                {MOMENTOS.filter((m) => m.id === momentoAtivo).map((m) => (
                                    <MomentoMorbilidade
                                        key={m.id}
                                        id={`morbilidade-${m.id}`}
                                        titulo={m.rotulo}
                                        ajuda={
                                            m.id === 'alta'
                                                ? 'Obrigatório para dar alta.'
                                                : data.data_de_alta
                                                  ? `Previsto para ${formatarData(somarDias(data.data_de_alta, 30))}.`
                                                  : undefined
                                        }
                                        indisponivel={
                                            m.id === '30_dias' && !data.data_de_alta ? 'Disponível depois de preenchida a data de alta.' : undefined
                                        }
                                        bloco={data.morbilidade[m.id]}
                                        aoMudar={(bloco) => setData('morbilidade', { ...data.morbilidade, [m.id]: bloco })}
                                        dominios={dominios}
                                        desativado={!podeEditar}
                                        dataDoRegisto={
                                            m.id === '30_dias' && data.data_de_alta
                                                ? { rotulo: 'Data do seguimento', min: data.data_de_alta, max: limites.hoje }
                                                : undefined
                                        }
                                        erros={{
                                            complicacoes: erros[`morbilidade.${m.id}.complicacoes`],
                                            clavien: erros[`morbilidade.${m.id}.clavien_dindo_id`],
                                            data: erros[`morbilidade.${m.id}.data_registo`],
                                        }}
                                    />
                                ))}
                            </div>
                        </Seccao>
                    )}

                    {internamento && separador === 'alta' && (
                        <Seccao
                            titulo="Alta"
                            descricao="Deixe a data de alta vazia enquanto o doente estiver internado. As complicações da alta registam-se em Morbilidade."
                        >
                            {painelDeAlta}
                            {podeEditar && data.data_de_alta && (
                                <div className="mb-4 flex flex-wrap items-center gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                    <span>Pode corrigir os dados da alta aqui em baixo ou cancelá-la, se foi dada por engano.</span>
                                    <Botao variante="perigo" onClick={cancelarAlta}>
                                        Cancelar alta
                                    </Botao>
                                </div>
                            )}
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
                                <Campo
                                    rotulo="Estado da alta"
                                    para="estado"
                                    erro={erros.estado_da_alta_id}
                                    ajuda={aguardaRevisao ? 'Com cirurgia, fica pendente até à revisão aos 30 dias.' : undefined}
                                    className="sm:col-span-2"
                                >
                                    <select
                                        id="estado"
                                        value={aguardaRevisao ? ALTA_PENDENTE : data.estado_da_alta_id}
                                        disabled={aguardaRevisao}
                                        onChange={(e) => setData('estado_da_alta_id', Number(e.target.value))}
                                        className={estiloEntrada}
                                        aria-invalid={!!erros.estado_da_alta_id}
                                    >
                                        {dominios.estados_da_alta
                                            // "Outro" já não se escolhe; só aparece num registo antigo que ainda o tenha.
                                            .filter(
                                                (e) => e.id === ALTA_PENDENTE || e.id === ALTA_CONCLUIDA || e.id === internamento?.estado_da_alta_id,
                                            )
                                            .map((e) => (
                                                <option key={e.id} value={e.id} disabled={e.id === ALTA_CONCLUIDA && !data.data_de_alta}>
                                                    {e.nome}
                                                </option>
                                            ))}
                                    </select>
                                </Campo>
                                <Campo rotulo="Destino" para="destino" erro={erros.destino_id} className="sm:col-span-6">
                                    <Selecao
                                        id="destino"
                                        opcoes={dominios.destinos}
                                        valor={data.destino_id}
                                        aoMudar={(v) => setData('destino_id', v)}
                                        vazio={data.data_de_alta ? 'Escolher…' : 'Ainda internado'}
                                        invalido={!!erros.destino_id}
                                    />
                                </Campo>
                            </div>
                        </Seccao>
                    )}
                </fieldset>

                <div className="sticky bottom-0 mt-5 flex flex-wrap items-center gap-2 border-t border-slate-200 bg-slate-50 py-3">
                    {podeEditar && (
                        <Botao type="submit" disabled={processing}>
                            {internamento ? 'Guardar alterações' : 'Registar internamento'}
                        </Botao>
                    )}
                    {aoFechar ? (
                        <Botao variante="secundario" onClick={aoFechar}>
                            Cancelar
                        </Botao>
                    ) : (
                        <Link href={rotas.internamentos.lista} className="px-2 py-2 text-sm font-medium text-slate-700 hover:underline">
                            {podeEditar ? 'Cancelar' : 'Voltar à lista'}
                        </Link>
                    )}

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
        </Quadro>
    );
}

export default function PaginaInternamento(props: PropsFormulario) {
    return <FormularioInternamento {...props} />;
}

PaginaInternamento.layout = (pagina: ReactNode) => pagina;
