import AppLayout from '@/layouts/app-layout';
import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { PropsPartilhadas } from '../clinico/tipos';

type Props = {
    titulo: string;
    /** Botões ou ligações mostrados à direita do título. */
    acoes?: ReactNode;
    /** Linha de contexto por baixo do título. */
    subtitulo?: ReactNode;
    largura?: 'normal' | 'larga';
    /** Pesquisa ou filtro mostrado no topo (ex.: o quadro filtra os internados). */
    pesquisa?: ReactNode;
    children: ReactNode;
};

export default function ClinicoLayout({ titulo, acoes, subtitulo, largura = 'normal', pesquisa, children }: Props) {
    const pagina = usePage<PropsPartilhadas>();
    const { flash } = pagina.props.clinico;
    const contentor = largura === 'larga' ? 'max-w-[96rem]' : 'max-w-6xl';

    return (
        <AppLayout breadcrumbs={[{ title: titulo, href: pagina.url.split('?')[0] }]}>
            <Head title={titulo} />

            <main id="conteudo" className={`mx-auto w-full ${contentor} px-4 py-6 font-sans text-slate-900 dark:text-slate-100`}>
                {pesquisa}

                {flash.sucesso && (
                    <p role="status" className="mb-4 rounded-md border border-teal-200 bg-teal-50 px-4 py-2.5 text-sm text-teal-900">
                        {flash.sucesso}
                    </p>
                )}
                {flash.erro && (
                    <p role="alert" className="mb-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800">
                        {flash.erro}
                    </p>
                )}

                <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{titulo}</h1>
                        {subtitulo && <div className="text-muted-foreground mt-1 text-sm">{subtitulo}</div>}
                    </div>
                    {acoes && <div className="flex flex-wrap gap-2">{acoes}</div>}
                </div>

                {children}
            </main>
        </AppLayout>
    );
}
