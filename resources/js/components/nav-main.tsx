import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { type NavGroup, type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

const caminho = (url: string) => url.split('?')[0].replace(/\/+$/, '') || '/';

/** Uma ligação corresponde à página se o caminho for igual ou for um sub-caminho dela. */
const corresponde = (item: NavItem, atual: string) => {
    const base = caminho(item.url);
    return atual === base || atual.startsWith(base + '/');
};

export function NavMain({ grupos = [] }: { grupos: NavGroup[] }) {
    const atual = caminho(usePage().url);

    // A ligação mais específica (caminho mais longo) é a activa; evita realçar "Internamentos" em "/internamentos/seguimentos".
    const todas = grupos.flatMap((g) => g.items.flatMap((i) => [i, ...(i.filhos ?? [])]));
    const activa = todas.filter((i) => corresponde(i, atual)).sort((a, b) => caminho(b.url).length - caminho(a.url).length)[0];

    return (
        <>
            {grupos.map((grupo) => (
                <SidebarGroup key={grupo.title} className="px-2 py-1">
                    <SidebarGroupLabel>{grupo.title}</SidebarGroupLabel>
                    <SidebarMenu>
                        {grupo.items.map((item) => {
                            const seccaoAberta = corresponde(item, atual) || item.filhos?.some((f) => f === activa);
                            return (
                                <SidebarMenuItem key={item.url}>
                                    <SidebarMenuButton asChild isActive={item === activa} tooltip={item.title}>
                                        <Link href={item.url} prefetch aria-current={item === activa ? 'page' : undefined}>
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                    {item.filhos && seccaoAberta && (
                                        <SidebarMenuSub>
                                            {item.filhos.map((filho) => (
                                                <SidebarMenuSubItem key={filho.url}>
                                                    <SidebarMenuSubButton asChild isActive={filho === activa}>
                                                        <Link href={filho.url} prefetch aria-current={filho === activa ? 'page' : undefined}>
                                                            {filho.icon && <filho.icon />}
                                                            <span>{filho.title}</span>
                                                        </Link>
                                                    </SidebarMenuSubButton>
                                                </SidebarMenuSubItem>
                                            ))}
                                        </SidebarMenuSub>
                                    )}
                                </SidebarMenuItem>
                            );
                        })}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
