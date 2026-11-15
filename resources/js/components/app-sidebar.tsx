import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavGroup } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BarChart3, BedDouble, CalendarDays, ClipboardList, HeartPulse, LayoutGrid, Map, Network, ShieldCheck, Users } from 'lucide-react';
import AppLogo from './app-logo';

const footerNavItems: never[] = [];

export function AppSidebar() {
    const clinico = usePage<{ clinico?: { administrador?: boolean; permissoes?: string[] } }>().props.clinico;
    const pode = (permissao: string) => !!clinico?.administrador || !!clinico?.permissoes?.includes(permissao);

    const grupos: NavGroup[] = [
        {
            title: 'Clínica',
            items: [
                { title: 'Quadro', url: '/quadro', icon: LayoutGrid },
                {
                    title: 'Internamentos',
                    url: '/internamentos',
                    icon: BedDouble,
                    filhos: [{ title: 'Seguimento aos 30 dias', url: '/internamentos/seguimentos', icon: HeartPulse }],
                },
                { title: 'Utentes', url: '/utentes', icon: Users },
            ],
        },
        ...(pode('show agendamento') || pode('show referenciacao')
            ? [
                  {
                      title: 'Planeamento',
                      items: [
                          ...(pode('show agendamento')
                              ? [
                                    {
                                        title: 'Agendamento',
                                        url: '/agendamento',
                                        icon: CalendarDays,
                                        filhos: [
                                            { title: 'Lista de espera', url: '/agendamento/lista-de-espera', icon: ClipboardList },
                                            { title: 'Mapa operatório', url: '/agendamento/mapa', icon: Map },
                                        ],
                                    },
                                ]
                              : []),
                          ...(pode('show referenciacao') ? [{ title: 'Referenciação', url: '/referenciacao', icon: Network }] : []),
                      ],
                  },
              ]
            : []),
        { title: 'Análise', items: [{ title: 'Painel de análise', url: '/dashboard', icon: BarChart3 }] },
        ...(clinico?.administrador ? [{ title: 'Sistema', items: [{ title: 'Administração', url: '/admin/utilizadores', icon: ShieldCheck }] }] : []),
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
                <NavMain grupos={grupos} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
