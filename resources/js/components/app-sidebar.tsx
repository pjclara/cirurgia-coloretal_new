import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BarChart3, BedDouble, CalendarDays, LayoutGrid, Network, ShieldCheck, Users } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    { title: 'Quadro', url: '/quadro', icon: LayoutGrid },
    { title: 'Internamentos', url: '/internamentos', icon: BedDouble },
    { title: 'Utentes', url: '/utentes', icon: Users },
    { title: 'Análise', url: '/dashboard', icon: BarChart3 },
];

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    const clinico = usePage<{ clinico?: { administrador?: boolean; permissoes?: string[] } }>().props.clinico;
    const itens: NavItem[] = [
        ...mainNavItems,
        ...(clinico?.permissoes?.includes('show referenciacao') ? [{ title: 'Referenciação', url: '/referenciacao', icon: Network }] : []),
        ...(clinico?.permissoes?.includes('show agendamento') ? [{ title: 'Agendamento', url: '/agendamento', icon: CalendarDays }] : []),
        ...(clinico?.administrador ? [{ title: 'Administração', url: '/admin/utilizadores', icon: ShieldCheck }] : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/quadro" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={itens} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
