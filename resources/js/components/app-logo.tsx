import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <AppLogoIcon className="size-8 shrink-0 object-contain" />
            <div className="ml-1 grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-semibold">Cirurgia Colorectal</span>
                <span className="text-muted-foreground truncate text-xs">ULS Região de Leiria</span>
            </div>
        </>
    );
}
