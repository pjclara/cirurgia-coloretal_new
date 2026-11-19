import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { VersaoDaApp } from '@/components/versao-da-app';
import { type NavGroup } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BarChart3,
    BedDouble,
    CalendarDays,
    ClipboardList,
    DatabaseZap,
    KeyRound,
    LayoutGrid,
    Map,
    Network,
    Scissors,
    ShieldCheck,
    Stethoscope,
} from 'lucide-react';
import AppLogo from './app-logo';

const footerNavItems: never[] = [];

export function AppSidebar() {
    const clinico = usePage<{ clinico?: { administrador?: boolean; permissoes?: string[]; utilizador?: { roles?: string[] } | null } }>().props
        .clinico;
    const superAdmin = !!clinico?.utilizador?.roles?.includes('superAdmin');
    const pode = (permissao: string) => !!clinico?.administrador || !!clinico?.permissoes?.includes(permissao);

    const grupos: NavGroup[] = [
        ...(pode('show internamento')
            ? [
                  {
                      title: 'Clínica',
                      items: [
                          { title: 'Quadro', url: '/quadro', icon: LayoutGrid },
                          { title: 'Revisão de internamento', url: '/internamentos', icon: BedDouble },
                      ],
                  },
              ]
            : []),
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
        ...(pode('show internamento')
            ? [
                  {
                      title: 'Análise',
                      items: [
                          { title: 'Painel de análise', url: '/dashboard', icon: BarChart3, alias: ['/referenciacao/analise'] },
                          ...(pode('show referenciacao')
                              ? [
                                    { title: 'Cancro retal', url: '/analise/cancro-retal', icon: Activity },
                                    { title: 'Qualidade dos dados', url: '/analise/cancro-retal/qualidade', icon: DatabaseZap },
                                ]
                              : []),
                      ],
                  },
              ]
            : []),
        ...(clinico?.administrador
            ? [
                  {
                      title: 'Sistema',
                      items: [
                          { title: 'Administração', url: '/admin/utilizadores', icon: ShieldCheck },
                          { title: 'Diagnósticos', url: '/admin/catalogos/diagnosticos', icon: Stethoscope },
                          { title: 'Intervenções', url: '/admin/catalogos/intervencoes', icon: Scissors },
                          ...(superAdmin ? [{ title: 'Perfis e permissões', url: '/admin/perfis', icon: KeyRound }] : []),
                      ],
                  },
              ]
            : []),
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
                <VersaoDaApp className="px-2 group-data-[collapsible=icon]:hidden" />
            </SidebarFooter>
        </Sidebar>
    );
}
