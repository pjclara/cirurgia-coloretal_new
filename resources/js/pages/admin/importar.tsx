import { router, useForm } from '@inertiajs/react';
import { formatarData } from '../../clinico/formato';
import { Botao, Campo, Seccao } from '../../components/clinico/campos';
import { NumeroProcesso } from '../../components/clinico/numero-processo';
import ClinicoLayout from '../../layouts/clinico-layout';

type Tipo = { chave: string; titulo: string; subtitulo: string; colunas: string; resultado: string };

type Previa = {
    token: string;
    ficheiro: string;
    linhas: number;
    a_criar: number;
    novos_utentes: number;
    saltados: { rotulo: string; n: number }[];
    sem_mapeamento: { titulo: string; itens: Record<string, number> }[];
    saltados_detalhe: { nome: string; processo: string; data: string; motivo: string }[];
    colunas: { chave: string; rotulo: string }[];
    amostra: ({ processo: number | null; nome: string; utente_novo: boolean } & Record<string, string | number | boolean | null>)[];
};

function SemMapeamento({ titulo, itens }: { titulo: string; itens: Record<string, number> }) {
    const lista = Object.entries(itens);
    if (lista.length === 0) return null;
    return (
        <div>
            <h3 className="text-sm font-semibold text-slate-900">{titulo}</h3>
            <ul className="mt-1 space-y-0.5 text-sm text-slate-700">
                {lista.map(([nome, n]) => (
                    <li key={nome} className="tabular-nums">
                        {n} × {nome}
                    </li>
                ))}
            </ul>
        </div>
    );
}

const eData = (chave: string) => chave === 'data';

/** Importação por Excel: carregar a folha, ver o que seria criado e confirmar. Serve a lista de espera e as referenciações. */
export default function Importar({ tipo, previa }: { tipo: Tipo; previa: Previa | null }) {
    const form = useForm<{ ficheiro: File | null }>({ ficheiro: null });
    const erro = (form.errors as Record<string, string | undefined>).ficheiro;
    const base = `/admin/importar/${tipo.chave}`;

    const carregar = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(base, { forceFormData: true, preserveScroll: true });
    };
    const confirmar = () => {
        if (!previa) return;
        if (!window.confirm(`Importar ${previa.a_criar} registos (${previa.novos_utentes} utentes novos)? Só se desfaz repondo um backup.`)) return;
        router.post(`${base}/confirmar`, { token: previa.token }, { preserveScroll: true });
    };
    const descartar = () => previa && router.post(`${base}/cancelar`, { token: previa.token });

    const saltadas = previa ? previa.saltados.reduce((soma, s) => soma + s.n, 0) : 0;

    return (
        <ClinicoLayout titulo={tipo.titulo} subtitulo={tipo.subtitulo}>
            <div className="space-y-5">
                <Seccao titulo="1. Folha">
                    <form onSubmit={carregar} className="flex flex-wrap items-end gap-3" noValidate>
                        <Campo rotulo="Ficheiro (.xlsx ou .csv)" para="ficheiro" erro={erro}>
                            <input
                                id="ficheiro"
                                type="file"
                                accept=".xlsx,.csv"
                                onChange={(e) => form.setData('ficheiro', e.target.files?.[0] ?? null)}
                                className="block text-sm"
                            />
                        </Campo>
                        <Botao type="submit" disabled={!form.data.ficheiro || form.processing}>
                            {form.processing ? 'A analisar…' : 'Ver o que seria importado'}
                        </Botao>
                    </form>
                    <p className="mt-2 text-xs text-slate-600">Colunas: {tipo.colunas} Nada é gravado neste passo.</p>
                </Seccao>

                {previa && (
                    <Seccao titulo={`2. Simulação · ${previa.ficheiro}`}>
                        <div className="space-y-4 text-sm">
                            <p>
                                <strong className="tabular-nums">{previa.a_criar}</strong> {tipo.resultado}, das quais{' '}
                                <strong className="tabular-nums">{previa.novos_utentes}</strong> com utente novo. Linhas lidas:{' '}
                                <span className="tabular-nums">{previa.linhas}</span>.
                            </p>
                            {saltadas > 0 && (
                                <p className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-slate-800">
                                    Saltadas: <span className="tabular-nums">{saltadas}</span> (
                                    {previa.saltados.map((s) => `${s.n} ${s.rotulo}`).join(' · ')})
                                </p>
                            )}
                            {previa.saltados_detalhe.length > 0 && (
                                <details className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-950">
                                    <summary className="cursor-pointer font-medium">
                                        {previa.saltados_detalhe.length} linhas que não se importam por falta de dados (ver quais)
                                    </summary>
                                    <ul className="mt-2 space-y-0.5 text-xs">
                                        {previa.saltados_detalhe.map((s, i) => (
                                            <li key={i} className="tabular-nums">
                                                {s.nome} · {s.processo || 'sem processo'} · {s.data ? formatarData(s.data) : '—'} — {s.motivo}
                                            </li>
                                        ))}
                                    </ul>
                                </details>
                            )}
                            <div className="grid gap-4 md:grid-cols-2">
                                {previa.sem_mapeamento.map((m) => (
                                    <SemMapeamento key={m.titulo} titulo={m.titulo} itens={m.itens} />
                                ))}
                            </div>

                            {previa.amostra.length > 0 && (
                                <div className="overflow-x-auto rounded-md border border-slate-200">
                                    <table className="w-full text-left text-xs">
                                        <caption className="bg-slate-50 px-3 py-1.5 text-left text-slate-600">
                                            Primeiras {previa.amostra.length} de {previa.a_criar}
                                        </caption>
                                        <thead className="border-y border-slate-200 text-slate-600">
                                            <tr>
                                                <th className="px-3 py-1.5 font-medium">Utente</th>
                                                {previa.colunas.map((c) => (
                                                    <th key={c.chave} className="px-3 py-1.5 font-medium">
                                                        {c.rotulo}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {previa.amostra.map((a, i) => (
                                                <tr key={`${a.processo}-${i}`}>
                                                    <td className="px-3 py-1.5">
                                                        {a.nome}
                                                        {a.utente_novo && (
                                                            <span className="ml-1.5 rounded bg-amber-100 px-1 text-amber-900">novo</span>
                                                        )}
                                                        <br />
                                                        <span className="text-slate-600 tabular-nums">
                                                            <NumeroProcesso valor={a.processo} />
                                                        </span>
                                                    </td>
                                                    {previa.colunas.map((c) => {
                                                        const v = a[c.chave];
                                                        return (
                                                            <td key={c.chave} className="px-3 py-1.5 tabular-nums">
                                                                {v ? (
                                                                    eData(c.chave) ? (
                                                                        formatarData(String(v))
                                                                    ) : (
                                                                        String(v)
                                                                    )
                                                                ) : (
                                                                    <span className="text-slate-400">só nos comentários</span>
                                                                )}
                                                            </td>
                                                        );
                                                    })}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            <div className="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3">
                                <Botao onClick={confirmar} disabled={previa.a_criar === 0}>
                                    Importar {previa.a_criar} registos
                                </Botao>
                                <Botao variante="secundario" onClick={descartar}>
                                    Descartar
                                </Botao>
                                <span className="text-xs text-slate-600">Faça um backup da base antes de importar.</span>
                            </div>
                        </div>
                    </Seccao>
                )}
            </div>
        </ClinicoLayout>
    );
}
