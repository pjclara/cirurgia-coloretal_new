import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { formatarData } from '../../clinico/formato';
import { CorpoFormularioCirurgia, type PropsCirurgia } from '../../pages/cirurgias/formulario';
import { NumeroProcesso } from '../clinico/numero-processo';

export type ModalCirurgiaDados = PropsCirurgia & { fechar_url: string; origem?: 'quadro' | 'seguimentos' | 'lista' | 'pendentes' };

/** Registo ou edição de uma cirurgia numa modal por cima do quadro. */
export function ModalCirurgia({ modal }: { modal: ModalCirurgiaDados | null }) {
    const fechar = () => modal && router.visit(modal.fechar_url, { preserveScroll: true });

    return (
        <Dialog open={modal !== null} onOpenChange={(aberto) => !aberto && fechar()}>
            <DialogContent
                className="max-h-[92vh] w-[90vw] max-w-[90vw] overflow-y-auto bg-white text-slate-900"
                onInteractOutside={(e) => e.preventDefault()}
                aria-describedby={undefined}
            >
                {modal && (
                    <>
                        <DialogHeader>
                            <DialogTitle>{modal.cirurgia ? 'Cirurgia' : 'Nova cirurgia'}</DialogTitle>
                            <p className="text-sm text-slate-600 tabular-nums">
                                {modal.utente.nome} · <NumeroProcesso valor={modal.utente.numero_processo} /> · Internamento de{' '}
                                {formatarData(modal.internamento.entrada)}
                            </p>
                        </DialogHeader>
                        <CorpoFormularioCirurgia key={modal.cirurgia?.id ?? `nova-${modal.internamento.id}`} {...modal} aoFechar={fechar} />
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
