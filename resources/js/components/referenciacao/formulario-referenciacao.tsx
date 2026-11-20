import { DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { formatarData } from '../../clinico/formato';
import type { Opcao, OpcaoAgrupada, Responsavel } from '../../clinico/tipos';
import { Botao, Campo, Seccao, Selecao, SelecaoResponsavel, estiloEntrada } from '../clinico/campos';
import { NumeroProcesso } from '../clinico/numero-processo';

type Linha = { diagnostico_id: number | ''; modo_id: number | ''; histologia_id: number | ''; data_diagnostico: string };

type InternamentoDoUtente = {
    id: number;
    data_de_entrada: string | null;
    data_de_alta: string | null;
    motivo: string | null;
    destino: string | null;
    diagnosticos: { nome: string; cr: boolean }[];
    cirurgias: { id: number; data: string; tipo: string | null; intervencoes: string[] }[];
};

export type PropsReferenciacao = {
    referenciacao: {
        id: number;
        data_de_diagnostico: string | null;
        data_de_referenciacao: string | null;
        origem_id: number;
        data_de_entrada: string | null;
        data_de_saida: string | null;
        destino_id: number | null;
        responsavel_id: number;
        comentarios: string;
        diagnosticos: { diagnostico_id: number; modo_id: number | null; histologia_id: number | null; data_diagnostico: string }[];
    } | null;
    utente: { id: number; nome: string; numero_processo: number };
    /** Internamentos do utente (null sem permissão para os ver). */
    internamentos?: InternamentoDoUtente[] | null;
    /** Diagnósticos CR já registados nos internamentos do utente e que a referenciação ainda não tem. */
    diagnosticos_do_utente: { id: number; nome: string }[];
    dominios: {
        origens: Opcao[];
        destinos: Opcao[];
        modos: Opcao[];
        histologias: Opcao[];
        diagnosticos: OpcaoAgrupada[];
        responsaveis: Responsavel[];
    };
    hoje: string;
    fechar_url: string;
};

type Separador = 'dados' | 'diagnosticos' | 'internamentos';

/** Escolha de diagnóstico agrupada por grupo de diagnóstico. */
function SelecaoDiagnostico({
    opcoes,
    valor,
    aoMudar,
    invalido,
    id,
}: {
    opcoes: OpcaoAgrupada[];
    valor: number | '';
    aoMudar: (v: number | '') => void;
    invalido: boolean;
    id: string;
}) {
    const grupos = [...new Set(opcoes.map((o) => o.grupo))];
    return (
        <select
            id={id}
            className={estiloEntrada}
            value={valor === '' ? '' : String(valor)}
            onChange={(e) => aoMudar(e.target.value === '' ? '' : Number(e.target.value))}
            aria-invalid={invalido || undefined}
        >
            <option value="">Escolher diagnóstico…</option>
            {grupos.map((g) => (
                <optgroup key={g} label={g}>
                    {opcoes
                        .filter((o) => o.grupo === g)
                        .map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.nome}
                            </option>
                        ))}
                </optgroup>
            ))}
        </select>
    );
}

/** O modo de diagnóstico «Outro» (o modo só se conhece quando se completa a referenciação). */
const MODO_OUTRO = 99;

const linhaDoUtente = (diagnostico_id: number): Linha => ({ diagnostico_id, modo_id: MODO_OUTRO, histologia_id: '', data_diagnostico: '' });

/** Registo de uma referenciação ao centro de referência, em dois separadores: dados e diagnósticos. */
export function FormularioReferenciacao({
    referenciacao,
    utente,
    internamentos,
    diagnosticos_do_utente,
    dominios,
    hoje,
    fechar,
}: PropsReferenciacao & { fechar: () => void }) {
    const paginaAtual = usePage().url; // a referenciação aberta: é para onde se volta ao fechar o internamento
    const [separador, setSeparador] = useState<Separador>('dados');
    const [confirmarApagar, setConfirmarApagar] = useState(false);

    const form = useForm({
        data_de_referenciacao: referenciacao?.data_de_referenciacao ?? hoje,
        data_de_diagnostico: referenciacao?.data_de_diagnostico ?? '',
        origem_id: (referenciacao?.origem_id ?? '') as number | '',
        data_de_entrada: referenciacao?.data_de_entrada ?? '',
        data_de_saida: referenciacao?.data_de_saida ?? '',
        destino_id: (referenciacao?.destino_id ?? '') as number | '',
        responsavel_id: (referenciacao?.responsavel_id ?? '') as number | '',
        comentarios: referenciacao?.comentarios ?? '',
        diagnosticos: (referenciacao
            ? referenciacao.diagnosticos.map((d) => ({
                  diagnostico_id: d.diagnostico_id,
                  modo_id: d.modo_id ?? '',
                  histologia_id: d.histologia_id ?? '',
                  data_diagnostico: d.data_diagnostico ?? '',
              }))
            : // Referenciação nova: já traz os diagnósticos CR que o utente tem nos internamentos.
              diagnosticos_do_utente.map((d) => linhaDoUtente(d.id))) as Linha[],
    });
    const { data, setData, processing } = form;
    const erros = form.errors as Record<string, string | undefined>;
    const erroDe = (campo: string) => Object.keys(erros).some((k) => k === campo || k.startsWith(`${campo}.`));

    const guardar = (e: FormEvent) => {
        e.preventDefault();
        const opcoes = {
            preserveScroll: true,
            onError: (er: Record<string, string>) => {
                const chaves = Object.keys(er);
                if (chaves.length > 0 && !chaves.some((c) => (c.startsWith('diagnosticos') ? 'diagnosticos' : 'dados') === separador))
                    setSeparador(chaves[0].startsWith('diagnosticos') ? 'diagnosticos' : 'dados');
            },
        };
        if (referenciacao) form.put(`/referenciacao/${referenciacao.id}`, opcoes);
        else form.post(`/referenciacao/utentes/${utente.id}`, opcoes);
    };

    // Diagnósticos CR do utente que ainda não estão na lista desta referenciação.
    const sugestoes = diagnosticos_do_utente.filter((d) => !data.diagnosticos.some((l) => l.diagnostico_id === d.id));
    const adicionarDoUtente = (ids: number[]) => setData('diagnosticos', [...data.diagnosticos, ...ids.map(linhaDoUtente)]);

    const mudarLinha = (i: number, alteracoes: Partial<Linha>) =>
        setData(
            'diagnosticos',
            data.diagnosticos.map((l, k) => (k === i ? { ...l, ...alteracoes } : l)),
        );

    return (
        <>
            <DialogHeader>
                <DialogTitle>{referenciacao ? 'Referenciação ao centro de referência' : 'Referenciar ao centro de referência'}</DialogTitle>
                <DialogDescription className="tabular-nums">
                    {utente.nome} · <NumeroProcesso valor={utente.numero_processo} />
                </DialogDescription>
            </DialogHeader>

            <div role="tablist" aria-label="Secções da referenciação" className="flex gap-1 border-b border-slate-200">
                {(
                    [
                        ['dados', 'Referenciação'],
                        ['diagnosticos', `Diagnósticos${data.diagnosticos.length > 0 ? ` (${data.diagnosticos.length})` : ''}`],
                        ...(internamentos ? [['internamentos', `Internamentos (${internamentos.length})`] as [Separador, string]] : []),
                    ] as [Separador, string][]
                ).map(([id, rotulo]) => (
                    <button
                        key={id}
                        type="button"
                        role="tab"
                        aria-selected={separador === id}
                        onClick={() => setSeparador(id)}
                        className={`-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2.5 text-sm ${separador === id ? 'border-[#a3213e] font-semibold text-slate-900' : 'border-transparent text-slate-600 hover:text-slate-900'}`}
                    >
                        {rotulo}
                        {Object.keys(erros).some((k) => (k.startsWith('diagnosticos') ? 'diagnosticos' : 'dados') === id) && (
                            <span className="text-rose-700">!</span>
                        )}
                    </button>
                ))}
            </div>

            <form onSubmit={guardar} noValidate className="space-y-5">
                {separador === 'dados' && (
                    <>
                        <Seccao titulo="Referenciação">
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Campo rotulo="Data de referenciação" para="data_ref" erro={erros.data_de_referenciacao}>
                                    <input
                                        id="data_ref"
                                        type="date"
                                        max={hoje}
                                        value={data.data_de_referenciacao}
                                        onChange={(e) => setData('data_de_referenciacao', e.target.value)}
                                        className={estiloEntrada}
                                        aria-invalid={!!erros.data_de_referenciacao}
                                    />
                                </Campo>
                                <Campo rotulo="Data do diagnóstico" para="data_diag" erro={erros.data_de_diagnostico} opcional>
                                    <input
                                        id="data_diag"
                                        type="date"
                                        max={data.data_de_referenciacao || hoje}
                                        value={data.data_de_diagnostico}
                                        onChange={(e) => setData('data_de_diagnostico', e.target.value)}
                                        className={estiloEntrada}
                                        aria-invalid={!!erros.data_de_diagnostico}
                                    />
                                </Campo>
                                <Campo rotulo="Origem" para="origem" erro={erros.origem_id}>
                                    <Selecao
                                        id="origem"
                                        opcoes={dominios.origens}
                                        valor={data.origem_id}
                                        aoMudar={(v) => setData('origem_id', v)}
                                        invalido={!!erros.origem_id}
                                    />
                                </Campo>
                                <Campo rotulo="Responsável" para="responsavel" erro={erros.responsavel_id} className="sm:col-span-3">
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

                        <Seccao
                            titulo="No centro de referência"
                            descricao="Preencha a entrada quando o utente for recebido e a saída, com o destino, quando terminar."
                        >
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Campo rotulo="Data de entrada" para="entrada" erro={erros.data_de_entrada} opcional>
                                    <input
                                        id="entrada"
                                        type="date"
                                        min={data.data_de_referenciacao}
                                        max={hoje}
                                        value={data.data_de_entrada}
                                        onChange={(e) => setData('data_de_entrada', e.target.value)}
                                        className={estiloEntrada}
                                        aria-invalid={!!erros.data_de_entrada}
                                    />
                                </Campo>
                                <Campo rotulo="Data de saída" para="saida" erro={erros.data_de_saida} opcional>
                                    <input
                                        id="saida"
                                        type="date"
                                        min={data.data_de_entrada || data.data_de_referenciacao}
                                        max={hoje}
                                        value={data.data_de_saida}
                                        disabled={!data.data_de_entrada}
                                        onChange={(e) => setData('data_de_saida', e.target.value)}
                                        className={estiloEntrada}
                                        aria-invalid={!!erros.data_de_saida}
                                    />
                                </Campo>
                                <Campo rotulo="Destino" para="destino" erro={erros.destino_id} opcional>
                                    <Selecao
                                        id="destino"
                                        opcoes={dominios.destinos}
                                        valor={data.destino_id}
                                        aoMudar={(v) => setData('destino_id', v)}
                                        vazio="Ainda no centro"
                                        invalido={!!erros.destino_id}
                                    />
                                </Campo>
                            </div>
                        </Seccao>

                        <Seccao titulo="Notas">
                            <Campo rotulo="Comentários" para="comentarios" erro={erros.comentarios} opcional>
                                <textarea
                                    id="comentarios"
                                    rows={3}
                                    value={data.comentarios}
                                    onChange={(e) => setData('comentarios', e.target.value)}
                                    className={estiloEntrada}
                                />
                            </Campo>
                        </Seccao>
                    </>
                )}

                {separador === 'internamentos' && internamentos && (
                    <Seccao
                        titulo="Internamentos do utente"
                        descricao="Do mais recente para o mais antigo. Os diagnósticos do centro de referência estão destacados."
                    >
                        {internamentos.length === 0 ? (
                            <p className="text-sm text-slate-600">Este utente não tem internamentos registados.</p>
                        ) : (
                            <ul className="space-y-3">
                                {internamentos.map((i) => (
                                    <li key={i.id} className="rounded-md border border-slate-200 p-3 text-sm">
                                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                                            <p className="font-medium text-slate-900 tabular-nums">
                                                {formatarData(i.data_de_entrada)} → {i.data_de_alta ? formatarData(i.data_de_alta) : 'internado'}
                                                {i.destino && <span className="ml-2 font-normal text-slate-600">{i.destino}</span>}
                                            </p>
                                            <Link
                                                href={`/referenciacao/internamentos/${i.id}?retorno=${encodeURIComponent(paginaAtual)}`}
                                                className="text-xs font-medium text-[#7d1830] hover:underline"
                                            >
                                                Abrir internamento
                                            </Link>
                                        </div>
                                        {i.motivo && <p className="mt-1 text-slate-700">{i.motivo}</p>}
                                        {i.diagnosticos.length > 0 && (
                                            <ul className="mt-2 flex flex-wrap gap-1.5">
                                                {i.diagnosticos.map((d) => (
                                                    <li
                                                        key={d.nome}
                                                        className={`rounded px-1.5 py-0.5 text-xs ${d.cr ? 'bg-violet-100 font-semibold text-violet-900' : 'bg-slate-100 text-slate-700'}`}
                                                    >
                                                        {d.nome}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                        {i.cirurgias.length > 0 && (
                                            <ul className="mt-2 space-y-0.5 text-xs text-slate-600">
                                                {i.cirurgias.map((c) => (
                                                    <li key={c.id} className="tabular-nums">
                                                        Cirurgia {formatarData(c.data)}
                                                        {c.tipo ? ` · ${c.tipo}` : ''}
                                                        {c.intervencoes.length > 0 ? ` · ${c.intervencoes.join(', ')}` : ''}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Seccao>
                )}

                {separador === 'diagnosticos' && (
                    <Seccao titulo="Diagnósticos" descricao="Cada diagnóstico com o modo como foi feito, a histologia e a data.">
                        {sugestoes.length > 0 && (
                            <div className="mb-4 rounded-md border border-violet-200 bg-violet-50/60 p-3 text-sm">
                                <p className="font-medium text-slate-900">Já registados nos internamentos deste utente</p>
                                <ul className="mt-2 flex flex-wrap items-center gap-2">
                                    {sugestoes.map((d) => (
                                        <li key={d.id}>
                                            <button
                                                type="button"
                                                onClick={() => adicionarDoUtente([d.id])}
                                                className="rounded border border-violet-300 bg-white px-2 py-1 text-xs font-medium text-violet-900 hover:bg-violet-100 focus-visible:outline-2 focus-visible:outline-teal-700"
                                            >
                                                + {d.nome}
                                            </button>
                                        </li>
                                    ))}
                                    {sugestoes.length > 1 && (
                                        <li>
                                            <button
                                                type="button"
                                                onClick={() => adicionarDoUtente(sugestoes.map((d) => d.id))}
                                                className="rounded px-2 py-1 text-xs text-violet-900 underline underline-offset-2 hover:bg-violet-100"
                                            >
                                                Adicionar todos
                                            </button>
                                        </li>
                                    )}
                                </ul>
                                <p className="mt-2 text-xs text-slate-600">Entram com o modo «Outro»; complete o modo e a histologia.</p>
                            </div>
                        )}
                        {data.diagnosticos.length === 0 && <p className="mb-3 text-sm text-slate-600">Ainda sem diagnósticos.</p>}
                        <div className="space-y-3">
                            {data.diagnosticos.map((l, i) => (
                                <div key={i} className="rounded-md border border-slate-200 p-3">
                                    <div className="grid gap-3 sm:grid-cols-6">
                                        <Campo
                                            rotulo="Diagnóstico"
                                            para={`diag-${i}`}
                                            erro={erros[`diagnosticos.${i}.diagnostico_id`]}
                                            className="sm:col-span-6"
                                        >
                                            <SelecaoDiagnostico
                                                id={`diag-${i}`}
                                                opcoes={dominios.diagnosticos}
                                                valor={l.diagnostico_id}
                                                aoMudar={(v) => mudarLinha(i, { diagnostico_id: v })}
                                                invalido={!!erros[`diagnosticos.${i}.diagnostico_id`]}
                                            />
                                        </Campo>
                                        <Campo rotulo="Modo" para={`modo-${i}`} erro={erros[`diagnosticos.${i}.modo_id`]} className="sm:col-span-2">
                                            <Selecao
                                                id={`modo-${i}`}
                                                opcoes={dominios.modos}
                                                valor={l.modo_id}
                                                aoMudar={(v) => mudarLinha(i, { modo_id: v })}
                                                invalido={!!erros[`diagnosticos.${i}.modo_id`]}
                                            />
                                        </Campo>
                                        <Campo
                                            rotulo="Histologia"
                                            para={`hist-${i}`}
                                            erro={erros[`diagnosticos.${i}.histologia_id`]}
                                            opcional
                                            className="sm:col-span-2"
                                        >
                                            <Selecao
                                                id={`hist-${i}`}
                                                opcoes={dominios.histologias}
                                                valor={l.histologia_id}
                                                aoMudar={(v) => mudarLinha(i, { histologia_id: v })}
                                                vazio="Sem histologia"
                                            />
                                        </Campo>
                                        <Campo
                                            rotulo="Data"
                                            para={`dat-${i}`}
                                            erro={erros[`diagnosticos.${i}.data_diagnostico`]}
                                            opcional
                                            className="sm:col-span-2"
                                        >
                                            <input
                                                id={`dat-${i}`}
                                                type="date"
                                                max={hoje}
                                                value={l.data_diagnostico}
                                                onChange={(e) => mudarLinha(i, { data_diagnostico: e.target.value })}
                                                className={estiloEntrada}
                                            />
                                        </Campo>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setData(
                                                'diagnosticos',
                                                data.diagnosticos.filter((_, k) => k !== i),
                                            )
                                        }
                                        className="mt-2 text-sm text-rose-700 hover:underline"
                                    >
                                        Remover diagnóstico
                                    </button>
                                </div>
                            ))}
                        </div>
                        <Botao
                            variante="secundario"
                            className="mt-3"
                            onClick={() =>
                                setData('diagnosticos', [
                                    ...data.diagnosticos,
                                    { diagnostico_id: '', modo_id: '', histologia_id: '', data_diagnostico: '' },
                                ])
                            }
                        >
                            Adicionar diagnóstico
                        </Botao>
                        {erroDe('diagnosticos') && !Object.keys(erros).some((k) => k.startsWith('diagnosticos.')) && (
                            <p className="mt-2 text-sm text-rose-700">{erros.diagnosticos}</p>
                        )}
                    </Seccao>
                )}

                <div className="sticky bottom-0 flex flex-wrap items-center gap-2 border-t border-slate-200 bg-slate-50 py-3">
                    <Botao type="submit" disabled={processing}>
                        {referenciacao ? 'Guardar alterações' : 'Referenciar'}
                    </Botao>
                    <Botao variante="secundario" onClick={fechar}>
                        Cancelar
                    </Botao>
                    {referenciacao && (
                        <div className="ml-auto flex items-center gap-2">
                            {confirmarApagar ? (
                                <>
                                    <span className="text-sm text-slate-700">Apagar esta referenciação?</span>
                                    <Botao variante="perigo" onClick={() => router.delete(`/referenciacao/${referenciacao.id}`)}>
                                        Apagar
                                    </Botao>
                                    <Botao variante="secundario" onClick={() => setConfirmarApagar(false)}>
                                        Manter
                                    </Botao>
                                </>
                            ) : (
                                <Botao variante="perigo" onClick={() => setConfirmarApagar(true)}>
                                    Apagar referenciação
                                </Botao>
                            )}
                        </div>
                    )}
                </div>
            </form>
        </>
    );
}
