import { Dialog, DialogContent } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { FormularioAgendar, type PropsAgendar } from './formulario-agendar';
import { FormularioInscricao, type PropsInscricao } from './formulario-inscricao';

export type Modal = ({ tipo: 'agendar' } & PropsAgendar & { fechar_url: string }) | ({ tipo: 'inscricao' } & PropsInscricao & { fechar_url: string });

/** Formulários de agendamento e inscrição em janela modal sobre a agenda / lista de espera. */
export function ModalAgendamento({ modal }: { modal: Modal | null }) {
    const fechar = () => modal && router.visit(modal.fechar_url, { preserveScroll: true });

    return (
        <Dialog open={modal !== null} onOpenChange={(aberto) => !aberto && fechar()}>
            <DialogContent
                className="max-h-[90vh] max-w-5xl overflow-y-auto bg-white text-slate-900"
                onInteractOutside={(e) => e.preventDefault()}
                aria-describedby={undefined}
            >
                {modal?.tipo === 'agendar' && (
                    <FormularioAgendar key={`a-${modal.agendamento?.id ?? 'novo'}-${modal.espera.id}`} {...modal} fechar={fechar} />
                )}
                {modal?.tipo === 'inscricao' && (
                    <FormularioInscricao key={`i-${modal.espera?.id ?? 'nova'}-${modal.utente.id}`} {...modal} fechar={fechar} />
                )}
            </DialogContent>
        </Dialog>
    );
}
