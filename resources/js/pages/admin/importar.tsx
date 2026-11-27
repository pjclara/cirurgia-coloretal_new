import { router, useForm } from '@inertiajs/react';
import { formatarData } from '../../clinico/formato';
import { Botao, Campo, Seccao } from '../../components/clinico/campos';
import { NumeroProcesso } from '../../components/clinico/numero-processo';
import ClinicoLayout from '../../layouts/clinico-layout';

type Previa = {
    token: string;
    ficheiro: string;
    linhas: number;
    a_criar: number;
    novos_utentes: number;
    saltados: { ativa: number; repetido: number; sem_medico: number; sem_data: number };
    sem_diagnostico: Record<string, number>;
    sem_intervencao: Record<string, number>;
    amostra: {
        processo: number;
        nome: string;
        data: string;
        responsavel: string;
        diagnostico: string | null;
        intervencao: string | null;
        utente_novo: boolean;
    }[];
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

/** Importação da lista de espera por Excel: carregar a folha, ver o que seria criado e confirmar. */
export default function ImportarListaDeEspera({ previa }: { previa: Previa | null }) {
    const form = useForm<{ ficheiro: File | null }>({ ficheiro: null });
    const erro = (form.errors as Record<string, string | undefined>).ficheiro;

    const carregar = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/importar/lista-de-espera', { forceFormData: true, preserveScroll: true });
    };
    const confirmar = () => {
        if (!previa) return;
        if (!window.confirm(`Importar ${previa.a_criar} inscrições (${previa.novos_utentes} utentes novos)? Só se desfaz repondo um backup.`)) return;
        router.post('/admin/importar/lista-de-espera/confirmar', { token: previa.token }, { preserveScroll: true });
    };
    const descartar = () => previa && router.post('/admin/importar/lista-de-espera/cancelar', { token: previa.token });

    const s = previa?.saltados;
    const saltadas = s ? s.ativa + s.repetido + s.sem_medico + s.sem_data : 0;

    return (
        <ClinicoLayout titulo="Importar lista de espera" subtitulo="Carregue a folha do hospital, confira o que seria criado e só depois confirme.">
            <div className="space-y-5">
                <Seccao titulo="1. Folha da lista de espera">
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
                    <p className="mt-2 text-xs text-slate-600">
                        Colunas: DTA_INSCR, UTENTE (n.º de processo), Nome, Data de nascimento, NOME_CLINICO, DIAG PRI, DES_DIAGNOSTICO, PROC_PRINC e
                        DESIGNACAO. Nada é gravado neste passo.
                    </p>
                </Seccao>

                {previa && s && (
                    <Seccao titulo={`2. Simulação · ${previa.ficheiro}`}>
                        <div className="space-y-4 text-sm">
                            <p>
                                <strong className="tabular-nums">{previa.a_criar}</strong> inscrições a criar em estado <em>Proposto</em> (prioridade
                                normal), das quais <strong className="tabular-nums">{previa.novos_utentes}</strong> com utente novo. Linhas lidas:{' '}
                                <span className="tabular-nums">{previa.linhas}</span>.
                            </p>
                            {saltadas > 0 && (
                                <p className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-slate-800">
                                    Saltadas: <span className="tabular-nums">{saltadas}</span>
                                    {' ('}
                                    {[
                                        s.ativa > 0 && `${s.ativa} já com inscrição por operar`,
                                        s.repetido > 0 && `${s.repetido} processo repetido na folha`,
                                        s.sem_medico > 0 && `${s.sem_medico} sem médico reconhecido`,
                                        s.sem_data > 0 && `${s.sem_data} sem data`,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                    {')'}
                                </p>
                            )}
                            <div className="grid gap-4 md:grid-cols-2">
                                <SemMapeamento titulo="Diagnósticos sem correspondência (ficam nos comentários)" itens={previa.sem_diagnostico} />
                                <SemMapeamento titulo="Procedimentos sem correspondência (ficam nos comentários)" itens={previa.sem_intervencao} />
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
                                                <th className="px-3 py-1.5 font-medium">Inscrição</th>
                                                <th className="px-3 py-1.5 font-medium">Responsável</th>
                                                <th className="px-3 py-1.5 font-medium">Diagnóstico</th>
                                                <th className="px-3 py-1.5 font-medium">Intervenção</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {previa.amostra.map((a) => (
                                                <tr key={a.processo}>
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
                                                    <td className="px-3 py-1.5 tabular-nums">{formatarData(a.data)}</td>
                                                    <td className="px-3 py-1.5">{a.responsavel}</td>
                                                    <td className="px-3 py-1.5">
                                                        {a.diagnostico ?? <span className="text-slate-400">só nos comentários</span>}
                                                    </td>
                                                    <td className="px-3 py-1.5">
                                                        {a.intervencao ?? <span className="text-slate-400">só nos comentários</span>}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            <div className="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3">
                                <Botao onClick={confirmar} disabled={previa.a_criar === 0}>
                                    Importar {previa.a_criar} inscrições
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
