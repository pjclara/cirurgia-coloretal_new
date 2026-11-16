import { CLAVIEN_GRAU_5, SEM_COMPLICACOES, type Opcao, type OpcaoAgrupada } from '../../clinico/tipos';
import { Campo, Selecao, estiloEntrada } from './campos';
import { EscolhaMultipla } from './escolha-multipla';

export type ComplicacaoEscolhida = { id: number; resolucoes: number[] };
export type BlocoMorbilidade = { complicacoes: ComplicacaoEscolhida[]; clavien_dindo_id: number | ''; data_registo: string };
export type Momento = 'alta' | '30_dias';

/** Resolução com o grau de Clavien-Dindo associado (null = sem grau automático). */
export type ResolucaoComGrau = Opcao & { clavien_dindo_id?: number | null };

type Props = {
    bloco: BlocoMorbilidade;
    aoMudar: (bloco: BlocoMorbilidade) => void;
    dominios: { complicacoes: OpcaoAgrupada[]; resolucoes: ResolucaoComGrau[]; clavien: Opcao[] };
    desativado: boolean;
    /** Óbito com complicação na alta: o grau fica fixo em 5. */
    clavienFixo?: boolean;
    /** Pede a data do registo (seguimento aos 30 dias). */
    dataDoRegisto?: { rotulo: string; min: string; max: string };
    erros: { complicacoes?: string; clavien?: string; data?: string };
    id: string;
};

/** Complicações, resolução e grau de Clavien-Dindo de um dos momentos de registo da morbilidade. */
export function BlocoMorbilidadeCampos({ bloco, aoMudar, dominios, desativado, clavienFixo = false, dataDoRegisto, erros, id }: Props) {
    const ids = bloco.complicacoes.map((c) => c.id);
    const semComplicacoes = ids.includes(SEM_COMPLICACOES);
    const temReal = ids.some((i) => i !== SEM_COMPLICACOES);
    const nome = (i: number) => dominios.complicacoes.find((c) => c.id === i)?.nome ?? `Complicação ${i}`;

    /** Grau mais alto entre as resoluções escolhidas (null se nenhuma tem grau associado). */
    const grauAutomatico = (complicacoes: ComplicacaoEscolhida[]): number | null => {
        const graus = complicacoes
            .filter((c) => c.id !== SEM_COMPLICACOES)
            .flatMap((c) => c.resolucoes)
            .map((r) => dominios.resolucoes.find((x) => x.id === r)?.clavien_dindo_id ?? null)
            .filter((g): g is number => g !== null);
        return graus.length > 0 ? Math.max(...graus) : null;
    };
    const nomeGrau = (id: number | '' | null) => dominios.clavien.find((g) => g.id === id)?.nome;
    const sugerido = grauAutomatico(bloco.complicacoes);

    /** "Sem complicações" substitui tudo; escolher outra complicação retira-a. */
    const mudarComplicacoes = (novosIds: number[]) => {
        const acabouDeEscolherSem = novosIds.includes(SEM_COMPLICACOES) && !semComplicacoes;
        const finais = acabouDeEscolherSem ? [SEM_COMPLICACOES] : novosIds.filter((i) => i !== SEM_COMPLICACOES || novosIds.length === 1);
        const complicacoes = finais.map((i) => bloco.complicacoes.find((c) => c.id === i) ?? { id: i, resolucoes: [] });
        const continuaReal = finais.some((i) => i !== SEM_COMPLICACOES);
        aoMudar({ ...bloco, complicacoes, clavien_dindo_id: !continuaReal ? '' : (grauAutomatico(complicacoes) ?? bloco.clavien_dindo_id) });
    };

    /** Escolher ou retirar uma resolução recalcula o grau de Clavien-Dindo (pode ser ajustado à mão a seguir). */
    const alternarResolucao = (complicacaoId: number, resolucaoId: number) => {
        const complicacoes = bloco.complicacoes.map((c) =>
            c.id !== complicacaoId
                ? c
                : {
                      ...c,
                      resolucoes: c.resolucoes.includes(resolucaoId) ? c.resolucoes.filter((r) => r !== resolucaoId) : [...c.resolucoes, resolucaoId],
                  },
        );
        aoMudar({ ...bloco, complicacoes, clavien_dindo_id: grauAutomatico(complicacoes) ?? bloco.clavien_dindo_id });
    };

    return (
        <div>
            {dataDoRegisto && (
                <Campo rotulo={dataDoRegisto.rotulo} para={`${id}-data`} erro={erros.data} opcional className="mb-4 max-w-xs">
                    <input
                        id={`${id}-data`}
                        type="date"
                        min={dataDoRegisto.min}
                        max={dataDoRegisto.max}
                        value={bloco.data_registo}
                        onChange={(e) => aoMudar({ ...bloco, data_registo: e.target.value })}
                        disabled={desativado}
                        className={estiloEntrada}
                    />
                </Campo>
            )}

            <EscolhaMultipla
                opcoes={dominios.complicacoes}
                valor={ids}
                aoMudar={mudarComplicacoes}
                procurar="Procurar complicação"
                desativado={desativado}
                vazio="Nenhuma complicação registada."
            />
            {erros.complicacoes && (
                <p className="mt-2 text-sm text-rose-700" role="alert">
                    {erros.complicacoes}
                </p>
            )}

            {temReal && (
                <div className="mt-5 space-y-4 border-t border-slate-200 pt-4">
                    {bloco.complicacoes
                        .filter((c) => c.id !== SEM_COMPLICACOES)
                        .map((c) => (
                            <fieldset key={c.id}>
                                <legend className="text-sm font-medium text-slate-800">Como foi resolvida: {nome(c.id)}</legend>
                                <div className="mt-1.5 grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                                    {dominios.resolucoes.map((r) => (
                                        <label key={r.id} className="flex cursor-pointer items-center gap-1.5 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                className="size-4 accent-teal-800"
                                                checked={c.resolucoes.includes(r.id)}
                                                onChange={() => alternarResolucao(c.id, r.id)}
                                                disabled={desativado}
                                            />
                                            {r.nome}
                                            {r.clavien_dindo_id && <span className="text-xs text-slate-500">({nomeGrau(r.clavien_dindo_id)})</span>}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                        ))}

                    <Campo
                        rotulo="Grau de Clavien-Dindo"
                        para={`${id}-clavien`}
                        erro={erros.clavien}
                        ajuda={
                            clavienFixo
                                ? 'Óbito com complicação: fica registado como grau 5.'
                                : sugerido !== null
                                  ? `Calculado pelas resoluções (${nomeGrau(sugerido)}, o mais alto). Pode ajustar.`
                                  : 'Sem resolução com grau associado: escolha o grau mais alto entre as complicações.'
                        }
                        className="max-w-xs"
                    >
                        <Selecao
                            id={`${id}-clavien`}
                            opcoes={dominios.clavien}
                            valor={clavienFixo ? CLAVIEN_GRAU_5 : bloco.clavien_dindo_id}
                            aoMudar={(v) => aoMudar({ ...bloco, clavien_dindo_id: v })}
                            disabled={clavienFixo || desativado}
                            invalido={!!erros.clavien}
                        />
                    </Campo>
                </div>
            )}
        </div>
    );
}
