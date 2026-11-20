import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { Opcao } from '../../clinico/tipos';
import { Botao, Campo, Selecao, estiloEntrada } from '../clinico/campos';

export type DecisaoTerapeutica = {
    id: number;
    data: string;
    estado: string | null;
    estado_id: number | null;
    timming: string | null;
    timming_id: number | null;
    resumo: string | null;
    recados: string | null;
    comentarios: string | null;
};

/** Estado «Realizada»: o que se propõe numa decisão nova. */
const ESTADO_REALIZADA = 3;

/** Registo ou edição de uma decisão terapêutica; grava por pedido próprio (não faz parte do formulário da referenciação). */
export function EditorDecisao({
    decisao,
    referenciacaoId,
    estados,
    timmings,
    hoje,
    aoFechar,
}: {
    decisao: DecisaoTerapeutica | null;
    referenciacaoId: number;
    estados: Opcao[];
    timmings: Opcao[];
    hoje: string;
    aoFechar: () => void;
}) {
    const [dados, setDados] = useState({
        data_de_decisao_terapeutica: decisao?.data ?? hoje,
        estado_consulta_id: (decisao?.estado_id ?? ESTADO_REALIZADA) as number | '',
        timming_id: (decisao?.timming_id ?? '') as number | '',
        resumo: decisao?.resumo ?? '',
        recados: decisao?.recados ?? '',
        comentarios: decisao?.comentarios ?? '',
    });
    const [erros, setErros] = useState<Record<string, string>>({});
    const [a_gravar, setAGravar] = useState(false);
    const mudar = <K extends keyof typeof dados>(campo: K, valor: (typeof dados)[K]) => setDados((d) => ({ ...d, [campo]: valor }));

    const gravar = () => {
        const opcoes = {
            preserveScroll: true,
            onStart: () => setAGravar(true),
            onFinish: () => setAGravar(false),
            onError: (e: Record<string, string>) => setErros(e),
            onSuccess: aoFechar,
        };
        if (decisao) router.put(`/referenciacao/decisoes/${decisao.id}`, dados, opcoes);
        else router.post(`/referenciacao/${referenciacaoId}/decisoes`, dados, opcoes);
    };

    return (
        // Não é um <form>: está dentro do formulário da referenciação. Enter não deve gravar esse formulário.
        <div
            className="space-y-3 rounded-md border border-teal-300 bg-teal-50/40 p-3"
            onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLElement).tagName !== 'TEXTAREA' && e.preventDefault()}
        >
            <p className="text-sm font-semibold text-slate-900">{decisao ? 'Editar decisão terapêutica' : 'Nova decisão terapêutica'}</p>
            <div className="grid gap-3 sm:grid-cols-3">
                <Campo rotulo="Data da decisão" para="dt_data" erro={erros.data_de_decisao_terapeutica}>
                    <input
                        id="dt_data"
                        type="date"
                        value={dados.data_de_decisao_terapeutica}
                        onChange={(e) => mudar('data_de_decisao_terapeutica', e.target.value)}
                        className={estiloEntrada}
                    />
                </Campo>
                <Campo rotulo="Estado" para="dt_estado" erro={erros.estado_consulta_id}>
                    <Selecao id="dt_estado" opcoes={estados} valor={dados.estado_consulta_id} aoMudar={(v) => mudar('estado_consulta_id', v)} />
                </Campo>
                <Campo rotulo="Timming" para="dt_timming" erro={erros.timming_id} opcional>
                    <Selecao
                        id="dt_timming"
                        opcoes={timmings}
                        valor={dados.timming_id}
                        aoMudar={(v) => mudar('timming_id', v)}
                        vazio="Sem informação"
                    />
                </Campo>
            </div>
            <Campo rotulo="Resumo / decisão" para="dt_resumo" erro={erros.resumo} opcional>
                <textarea id="dt_resumo" rows={3} value={dados.resumo} onChange={(e) => mudar('resumo', e.target.value)} className={estiloEntrada} />
            </Campo>
            <div className="grid gap-3 sm:grid-cols-2">
                <Campo rotulo="Recados" para="dt_recados" erro={erros.recados} opcional>
                    <textarea
                        id="dt_recados"
                        rows={2}
                        value={dados.recados}
                        onChange={(e) => mudar('recados', e.target.value)}
                        className={estiloEntrada}
                    />
                </Campo>
                <Campo rotulo="Comentários" para="dt_comentarios" erro={erros.comentarios} opcional>
                    <textarea
                        id="dt_comentarios"
                        rows={2}
                        value={dados.comentarios}
                        onChange={(e) => mudar('comentarios', e.target.value)}
                        className={estiloEntrada}
                    />
                </Campo>
            </div>
            <div className="flex gap-2">
                <Botao onClick={gravar} disabled={a_gravar}>
                    {decisao ? 'Guardar decisão' : 'Registar decisão'}
                </Botao>
                <Botao variante="secundario" onClick={aoFechar}>
                    Cancelar
                </Botao>
            </div>
        </div>
    );
}
