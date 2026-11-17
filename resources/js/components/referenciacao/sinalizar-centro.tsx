import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { hojeIso } from '../../clinico/formato';
import { Botao, Campo, Selecao, SelecaoResponsavel, estiloEntrada } from '../clinico/campos';

/** Sinalização rápida para o centro de referência: só a origem e a data; o resto preenche-se depois. */
export function SinalizarCentro({
    utenteId,
    nome,
    origens,
    responsaveis,
    aberto,
    aoFechar,
}: {
    utenteId: number;
    /** Nome do utente, para confirmar a quem se refere. */
    nome?: string;
    origens: { id: number; nome: string }[];
    /** Médicos que se podem associar; por omissão, quem está a registar. */
    responsaveis: { id: number; nome: string; equipa: string | null }[];
    aberto: boolean;
    aoFechar: () => void;
}) {
    const eu = usePage<{ auth: { user: { id: number } | null } }>().props.auth.user?.id;
    const form = useForm({
        data_de_referenciacao: hojeIso(),
        origem_id: '' as number | '',
        responsavel_id: (responsaveis.some((r) => r.id === eu) ? eu : '') as number | '',
        comentarios: '',
    });
    const erros = form.errors as Record<string, string | undefined>;
    const enviar = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/referenciacao/utentes/${utenteId}/sinalizar`, { preserveScroll: true, onSuccess: aoFechar });
    };

    return (
        <Dialog open={aberto} onOpenChange={(a) => !a && aoFechar()}>
            <DialogContent className="max-w-lg bg-white text-slate-900">
                <DialogHeader>
                    <DialogTitle>Sinalizar para o centro de referência</DialogTitle>
                    <DialogDescription>
                        {nome && <span className="block font-medium text-slate-900">{nome}</span>}
                        Fica registado que o utente foi referenciado. Os restantes dados (entrada, saída, diagnósticos) preenchem-se depois.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={enviar} noValidate className="space-y-4">
                    <Campo rotulo="Origem" para="sin-origem" erro={erros.origem_id}>
                        <Selecao
                            id="sin-origem"
                            opcoes={origens}
                            valor={form.data.origem_id}
                            aoMudar={(v) => form.setData('origem_id', v)}
                            invalido={!!erros.origem_id}
                        />
                    </Campo>
                    <Campo rotulo="Médico responsável" para="sin-medico" erro={erros.responsavel_id}>
                        <SelecaoResponsavel
                            id="sin-medico"
                            responsaveis={responsaveis}
                            valor={form.data.responsavel_id}
                            aoMudar={(v) => form.setData('responsavel_id', v)}
                            vazio="Eu (quem está a registar)"
                            invalido={!!erros.responsavel_id}
                        />
                    </Campo>
                    <Campo rotulo="Data de referenciação" para="sin-data" erro={erros.data_de_referenciacao}>
                        <input
                            id="sin-data"
                            type="date"
                            max={hojeIso()}
                            value={form.data.data_de_referenciacao}
                            onChange={(e) => form.setData('data_de_referenciacao', e.target.value)}
                            className={estiloEntrada}
                            aria-invalid={!!erros.data_de_referenciacao}
                        />
                    </Campo>
                    <Campo rotulo="Comentários" para="sin-coment" erro={erros.comentarios} opcional>
                        <textarea
                            id="sin-coment"
                            rows={2}
                            value={form.data.comentarios}
                            onChange={(e) => form.setData('comentarios', e.target.value)}
                            className={estiloEntrada}
                        />
                    </Campo>
                    <div className="flex gap-2">
                        <Botao type="submit" disabled={form.processing}>
                            Sinalizar
                        </Botao>
                        <Botao variante="secundario" onClick={aoFechar}>
                            Cancelar
                        </Botao>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
