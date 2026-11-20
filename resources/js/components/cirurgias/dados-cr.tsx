import type { Opcao } from '../../clinico/tipos';
import { Campo, Selecao, estiloEntrada } from '../clinico/campos';

/** Valores 1 = Não, 2 = Sim, 99 = Não aplicado, como nas tabelas de origem. */
export type SimNao = '' | '1' | '2' | '99';

export type DadosCr = {
    asa: string;
    intencao_id: number | '';
    paleativa_causa: string;
    duracao: string;
    estoma_de_protecao_id: number | '';
    local_extracao_peca_id: number | '';
    tipo_de_dreno_id: number | '';
    resseccao_multi_orgao: SimNao;
    resseccao_multi_orgao_quais: string;
    aderencia_id: number | '';
    tipo_de_resseccao_id: number | '';
    neoplasia_residual_id: number | '';
    perdas_hematica_id: number | '';
    transfusao_intra_operatoria: SimNao;
    unidades_globulos: string;
    protector_de_parede: SimNao;
    complicacoes: SimNao;
    complicacoes_quais: string;
};

export type DescricaoIntervencao = {
    anastemose_modo_id: number | '';
    anastemose_via_id: number | '';
    anastemose_sentido_id: number | '';
    tipo_de_reconstrucao_id: number | '';
    reforco_anastemose: SimNao;
    localizacao_anastemose_id: number | '';
    confirmacao_anastemose_id: number | '';
    libertacao_angulo: SimNao;
    numero_cargas: string;
    distancia_linha_pectinea_anastemose: string;
    distancia_linha_pectinea_tumor: string;
    qualidade_peca_operatoria_id: number | '';
    comentarios: string;
};

export type PropsCr = {
    referenciado_desde: string | null;
    resseccoes: number[];
    dados: Partial<Record<string, string | number | null>> | null;
    descricoes: Record<string, Partial<Record<string, string | number | null>>>;
    listas: Record<keyof DadosCr, Opcao[]>;
    listas_descricao: Record<keyof DescricaoIntervencao, Opcao[]>;
};

const texto = (v: unknown) => (v === null || v === undefined ? '' : String(v));
const lista = (v: unknown): number | '' => (v === null || v === undefined || v === '' ? '' : Number(v));
const simNao = (v: unknown): SimNao => (v === null || v === undefined ? '' : (String(v) as SimNao));

export const crInicial = (d: PropsCr['dados']): DadosCr => ({
    asa: texto(d?.asa),
    intencao_id: lista(d?.intencao_id),
    paleativa_causa: texto(d?.paleativa_causa),
    duracao: texto(d?.duracao),
    estoma_de_protecao_id: lista(d?.estoma_de_protecao_id),
    local_extracao_peca_id: lista(d?.local_extracao_peca_id),
    tipo_de_dreno_id: lista(d?.tipo_de_dreno_id),
    resseccao_multi_orgao: simNao(d?.resseccao_multi_orgao),
    resseccao_multi_orgao_quais: texto(d?.resseccao_multi_orgao_quais),
    aderencia_id: lista(d?.aderencia_id),
    tipo_de_resseccao_id: lista(d?.tipo_de_resseccao_id),
    neoplasia_residual_id: lista(d?.neoplasia_residual_id),
    perdas_hematica_id: lista(d?.perdas_hematica_id),
    transfusao_intra_operatoria: simNao(d?.transfusao_intra_operatoria),
    unidades_globulos: texto(d?.unidades_globulos),
    protector_de_parede: simNao(d?.protector_de_parede),
    complicacoes: simNao(d?.complicacoes),
    complicacoes_quais: texto(d?.complicacoes_quais),
});

export const descricaoInicial = (d?: Partial<Record<string, string | number | null>>): DescricaoIntervencao => ({
    anastemose_modo_id: lista(d?.anastemose_modo_id),
    anastemose_via_id: lista(d?.anastemose_via_id),
    anastemose_sentido_id: lista(d?.anastemose_sentido_id),
    tipo_de_reconstrucao_id: lista(d?.tipo_de_reconstrucao_id),
    reforco_anastemose: simNao(d?.reforco_anastemose),
    localizacao_anastemose_id: lista(d?.localizacao_anastemose_id),
    confirmacao_anastemose_id: lista(d?.confirmacao_anastemose_id),
    libertacao_angulo: simNao(d?.libertacao_angulo),
    numero_cargas: texto(d?.numero_cargas),
    distancia_linha_pectinea_anastemose: texto(d?.distancia_linha_pectinea_anastemose),
    distancia_linha_pectinea_tumor: texto(d?.distancia_linha_pectinea_tumor),
    qualidade_peca_operatoria_id: lista(d?.qualidade_peca_operatoria_id),
    comentarios: texto(d?.comentarios),
});

/** Só se envia o que o utilizador começou a preencher; o resto pode ficar para mais tarde. */
export const tocado = (valores: object) => Object.values(valores).some((v) => v !== '');

type Erros = Record<string, string | undefined>;

function SimNaoEscolha({ rotulo, valor, aoMudar, erro }: { rotulo: string; valor: SimNao; aoMudar: (v: SimNao) => void; erro?: string }) {
    const opcoes: { id: SimNao; nome: string }[] = [
        { id: '1', nome: 'Não' },
        { id: '2', nome: 'Sim' },
        { id: '99', nome: 'N/A' },
    ];
    return (
        <div role="group" aria-label={rotulo}>
            <p className="mb-1 text-sm font-medium text-slate-800">{rotulo}</p>
            <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5">
                {opcoes.map((o) => (
                    <button
                        key={o.id}
                        type="button"
                        aria-pressed={valor === o.id}
                        onClick={() => aoMudar(valor === o.id ? '' : o.id)}
                        className={`rounded px-3 py-1.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-teal-700 ${
                            valor === o.id ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'
                        }`}
                    >
                        {o.nome}
                    </button>
                ))}
            </div>
            {erro && (
                <p className="mt-1 text-sm text-rose-700" role="alert">
                    {erro}
                </p>
            )}
        </div>
    );
}

const numero = (valor: string, aoMudar: (v: string) => void, id: string, max: number) => (
    <input
        id={id}
        type="number"
        inputMode="numeric"
        min={0}
        max={max}
        value={valor}
        onChange={(e) => aoMudar(e.target.value)}
        className={estiloEntrada}
    />
);

/** Dados da cirurgia dos doentes do CR com ressecção (tabela bloco_operatorio_c_r_s). */
export function SeccaoCr({
    valor,
    aoMudar,
    listas,
    erros,
}: {
    valor: DadosCr;
    aoMudar: (v: DadosCr) => void;
    listas: PropsCr['listas'];
    erros: Erros;
}) {
    const mudar = <K extends keyof DadosCr>(campo: K, v: DadosCr[K]) => aoMudar({ ...valor, [campo]: v });
    const e = (campo: string) => erros[`cr.${campo}`];
    const sel = (
        campo: Exclude<
            keyof DadosCr,
            | 'asa'
            | 'paleativa_causa'
            | 'duracao'
            | 'resseccao_multi_orgao_quais'
            | 'unidades_globulos'
            | 'complicacoes_quais'
            | 'resseccao_multi_orgao'
            | 'transfusao_intra_operatoria'
            | 'protector_de_parede'
            | 'complicacoes'
        >,
        rotulo: string,
    ) => (
        <Campo rotulo={rotulo} para={`cr-${campo}`} erro={e(campo)}>
            <Selecao id={`cr-${campo}`} opcoes={listas[campo]} valor={valor[campo]} aoMudar={(v) => mudar(campo, v)} invalido={!!e(campo)} />
        </Campo>
    );

    return (
        <section aria-label="Dados da cirurgia do centro de referência" className="mt-4 rounded-lg border border-violet-200 bg-violet-50/40 p-4">
            <h3 className="text-sm font-semibold text-slate-900">Dados da cirurgia · centro de referência</h3>
            <p className="mb-3 text-xs text-slate-600">
                Doente referenciado ao centro de referência com cirurgia de ressecção. Pode preencher mais tarde; se começar, preencha tudo o que é
                obrigatório.
            </p>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {sel('intencao_id', 'Intenção')}
                <Campo rotulo="Duração (minutos)" para="cr-duracao" erro={e('duracao')}>
                    {numero(valor.duracao, (v) => mudar('duracao', v), 'cr-duracao', 1440)}
                </Campo>
                <Campo rotulo="ASA" para="cr-asa" erro={e('asa')} opcional>
                    {numero(valor.asa, (v) => mudar('asa', v), 'cr-asa', 5)}
                </Campo>
                {sel('tipo_de_resseccao_id', 'Tipo de ressecção')}
                {sel('neoplasia_residual_id', 'Neoplasia residual')}
                {sel('estoma_de_protecao_id', 'Estoma de proteção')}
                {sel('local_extracao_peca_id', 'Local de extração da peça')}
                {sel('tipo_de_dreno_id', 'Tipo de dreno')}
                {sel('aderencia_id', 'Aderências')}
                {sel('perdas_hematica_id', 'Perdas hemáticas')}
            </div>

            {valor.intencao_id === 2 && (
                <Campo rotulo="Causa da intenção paliativa" para="cr-paleativa" erro={e('paleativa_causa')} opcional className="mt-3">
                    <textarea
                        id="cr-paleativa"
                        rows={2}
                        maxLength={2000}
                        value={valor.paleativa_causa}
                        onChange={(ev) => mudar('paleativa_causa', ev.target.value)}
                        className={`${estiloEntrada} resize-none`}
                    />
                </Campo>
            )}

            <div className="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
                <SimNaoEscolha
                    rotulo="Ressecção multiorgão"
                    valor={valor.resseccao_multi_orgao}
                    aoMudar={(v) => mudar('resseccao_multi_orgao', v)}
                    erro={e('resseccao_multi_orgao')}
                />
                <SimNaoEscolha
                    rotulo="Transfusão intra-operatória"
                    valor={valor.transfusao_intra_operatoria}
                    aoMudar={(v) => mudar('transfusao_intra_operatoria', v)}
                    erro={e('transfusao_intra_operatoria')}
                />
                <SimNaoEscolha
                    rotulo="Protetor de parede"
                    valor={valor.protector_de_parede}
                    aoMudar={(v) => mudar('protector_de_parede', v)}
                    erro={e('protector_de_parede')}
                />
                <SimNaoEscolha
                    rotulo="Complicações intra-operatórias"
                    valor={valor.complicacoes}
                    aoMudar={(v) => mudar('complicacoes', v)}
                    erro={e('complicacoes')}
                />
            </div>

            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                {valor.resseccao_multi_orgao === '2' && (
                    <Campo rotulo="Órgãos ressecados" para="cr-orgaos" erro={e('resseccao_multi_orgao_quais')} opcional>
                        <input
                            id="cr-orgaos"
                            value={valor.resseccao_multi_orgao_quais}
                            maxLength={2000}
                            onChange={(ev) => mudar('resseccao_multi_orgao_quais', ev.target.value)}
                            className={estiloEntrada}
                        />
                    </Campo>
                )}
                {valor.transfusao_intra_operatoria === '2' && (
                    <Campo rotulo="Unidades de glóbulos" para="cr-globulos" erro={e('unidades_globulos')} opcional>
                        {numero(valor.unidades_globulos, (v) => mudar('unidades_globulos', v), 'cr-globulos', 50)}
                    </Campo>
                )}
                {valor.complicacoes === '2' && (
                    <Campo rotulo="Quais as complicações" para="cr-complicacoes" erro={e('complicacoes_quais')} opcional>
                        <input
                            id="cr-complicacoes"
                            value={valor.complicacoes_quais}
                            maxLength={2000}
                            onChange={(ev) => mudar('complicacoes_quais', ev.target.value)}
                            className={estiloEntrada}
                        />
                    </Campo>
                )}
            </div>
        </section>
    );
}

/** Descrição de uma intervenção de ressecção: anastomose, reconstrução e peça operatória (tabela intervencao_descricaos). */
export function SeccaoDescricao({
    titulo,
    id,
    valor,
    aoMudar,
    listas,
    erros,
}: {
    titulo: string;
    id: number;
    valor: DescricaoIntervencao;
    aoMudar: (v: DescricaoIntervencao) => void;
    listas: PropsCr['listas_descricao'];
    erros: Erros;
}) {
    const mudar = <K extends keyof DescricaoIntervencao>(campo: K, v: DescricaoIntervencao[K]) => aoMudar({ ...valor, [campo]: v });
    const e = (campo: string) => erros[`descricoes.${id}.${campo}`];
    type CampoLista =
        | 'anastemose_modo_id'
        | 'anastemose_via_id'
        | 'anastemose_sentido_id'
        | 'tipo_de_reconstrucao_id'
        | 'localizacao_anastemose_id'
        | 'confirmacao_anastemose_id'
        | 'qualidade_peca_operatoria_id';
    const sel = (campo: CampoLista, rotulo: string, opcional = false) => (
        <Campo rotulo={rotulo} para={`d${id}-${campo}`} erro={e(campo)} opcional={opcional}>
            <Selecao id={`d${id}-${campo}`} opcoes={listas[campo]} valor={valor[campo]} aoMudar={(v) => mudar(campo, v)} invalido={!!e(campo)} />
        </Campo>
    );

    return (
        <section aria-label={`Descrição: ${titulo}`} className="mt-4 rounded-lg border border-slate-200 bg-white p-4">
            <h3 className="text-sm font-semibold text-slate-900">Descrição da intervenção · {titulo}</h3>
            <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {sel('anastemose_modo_id', 'Anastomose: modo')}
                {sel('anastemose_via_id', 'Anastomose: via')}
                {sel('anastemose_sentido_id', 'Anastomose: sentido')}
                {sel('tipo_de_reconstrucao_id', 'Tipo de reconstrução')}
                {sel('localizacao_anastemose_id', 'Localização da anastomose')}
                {sel('confirmacao_anastemose_id', 'Confirmação da anastomose')}
                {sel('qualidade_peca_operatoria_id', 'Qualidade da peça operatória', true)}
                <Campo rotulo="Nº de cargas" para={`d${id}-cargas`} erro={e('numero_cargas')} opcional>
                    {numero(valor.numero_cargas, (v) => mudar('numero_cargas', v), `d${id}-cargas`, 20)}
                </Campo>
                <Campo
                    rotulo="Distância da anastomose à linha pectínea (mm)"
                    para={`d${id}-dist-a`}
                    erro={e('distancia_linha_pectinea_anastemose')}
                    opcional
                >
                    {numero(valor.distancia_linha_pectinea_anastemose, (v) => mudar('distancia_linha_pectinea_anastemose', v), `d${id}-dist-a`, 300)}
                </Campo>
                <Campo rotulo="Distância do tumor à linha pectínea (mm)" para={`d${id}-dist-t`} erro={e('distancia_linha_pectinea_tumor')} opcional>
                    {numero(valor.distancia_linha_pectinea_tumor, (v) => mudar('distancia_linha_pectinea_tumor', v), `d${id}-dist-t`, 300)}
                </Campo>
            </div>
            <div className="mt-3 flex flex-wrap gap-x-8 gap-y-3">
                <SimNaoEscolha
                    rotulo="Reforço da anastomose"
                    valor={valor.reforco_anastemose}
                    aoMudar={(v) => mudar('reforco_anastemose', v)}
                    erro={e('reforco_anastemose')}
                />
                <SimNaoEscolha
                    rotulo="Libertação do ângulo"
                    valor={valor.libertacao_angulo}
                    aoMudar={(v) => mudar('libertacao_angulo', v)}
                    erro={e('libertacao_angulo')}
                />
            </div>
            <Campo rotulo="Comentários" para={`d${id}-coment`} erro={e('comentarios')} opcional className="mt-3">
                <textarea
                    id={`d${id}-coment`}
                    rows={2}
                    maxLength={2000}
                    value={valor.comentarios}
                    onChange={(ev) => mudar('comentarios', ev.target.value)}
                    className={`${estiloEntrada} resize-none`}
                />
            </Campo>
        </section>
    );
}
