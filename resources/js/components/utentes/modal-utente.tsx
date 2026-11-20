import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { CorpoFormularioUtente, type PropsUtente } from '../../pages/utentes/formulario';

export type ModalUtenteDados = PropsUtente & { fechar_url: string; origem?: 'quadro' | 'referenciacao' | 'agendamento' };

/** Criação de utente numa modal por cima da página que a abriu. */
export function ModalUtente({ modal }: { modal: ModalUtenteDados | null }) {
    const fechar = () => modal && router.visit(modal.fechar_url, { preserveScroll: true });

    return (
        <Dialog open={modal !== null} onOpenChange={(aberto) => !aberto && fechar()}>
            <DialogContent
                className="max-h-[90vh] max-w-3xl overflow-y-auto bg-white text-slate-900"
                onInteractOutside={(e) => e.preventDefault()}
                aria-describedby={undefined}
            >
                <DialogHeader>
                    <DialogTitle>Criar utente</DialogTitle>
                </DialogHeader>
                {modal && <CorpoFormularioUtente {...modal} aoCancelar={fechar} />}
            </DialogContent>
        </Dialog>
    );
}
