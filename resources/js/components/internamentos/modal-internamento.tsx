import { Dialog, DialogContent } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { FormularioInternamento, type PropsFormulario } from '../../pages/internamentos/formulario';

export type ModalInternamentoDados = PropsFormulario & { fechar_url: string; origem?: 'quadro' | 'lista' | 'qualidade' | 'referenciacao' };

/** Formulário do internamento (registo ou edição) numa modal por cima da página que a abriu. */
export function ModalInternamento({ modal }: { modal: ModalInternamentoDados | null }) {
    const fechar = () => modal && router.visit(modal.fechar_url, { preserveScroll: true });

    return (
        <Dialog open={modal !== null} onOpenChange={(aberto) => !aberto && fechar()}>
            <DialogContent
                className="max-h-[92vh] w-[90vw] max-w-[90vw] overflow-y-auto bg-white text-slate-900"
                onInteractOutside={(e) => e.preventDefault()}
                aria-describedby={undefined}
            >
                {modal && (
                    <FormularioInternamento
                        key={modal.internamento?.id ?? modal.utente?.id ?? 'novo'}
                        {...modal}
                        retorno={modal.fechar_url}
                        aoFechar={fechar}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
