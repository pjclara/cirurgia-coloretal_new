import AppLogoIcon from '@/components/app-logo-icon';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Welcome() {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Cirurgia Colorectal" />
            <div className="bg-background flex min-h-screen flex-col items-center justify-center gap-8 p-6">
                <AppLogoIcon className="size-40 object-contain sm:size-52" />

                <div className="space-y-2 text-center">
                    <h1 className="text-2xl font-semibold sm:text-3xl">Unidade Funcional de Cirurgia Colorectal</h1>
                    <p className="text-muted-foreground text-sm tracking-widest uppercase">ULS Região de Leiria</p>
                </div>

                <nav className="flex items-center gap-3">
                    {auth.user ? (
                        <Link
                            href={route('dashboard')}
                            className="bg-primary text-primary-foreground hover:bg-primary/90 rounded-md px-6 py-2 text-sm font-medium"
                        >
                            Entrar na aplicação
                        </Link>
                    ) : (
                        <Link
                            href={route('login')}
                            className="bg-primary text-primary-foreground hover:bg-primary/90 rounded-md px-6 py-2 text-sm font-medium"
                        >
                            Iniciar sessão
                        </Link>
                    )}
                </nav>
            </div>
        </>
    );
}
