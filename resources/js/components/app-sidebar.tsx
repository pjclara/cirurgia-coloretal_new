import { DireitosDeAutor } from '@/components/direitos-de-autor';
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
    ChartLine,
    ClipboardCheck,
    ClipboardList,
    DatabaseZap,
    KeyRound,
    LayoutGrid,
    ListTodo,
    Map,
    Network,
    Scissors,
    ShieldCheck,
    Stethoscope,
} from 'lucide-react';
import AppLogo from './app-logo';

const footerNavItems: never[] = [];

export function AppSidebar() {
    const clinico = usePage<{
        clinico?: {
            administrador?: boolean;
            permissoes?: string[];

            utilizador?: { roles?: string[] } | null;
        };
    }>().props.clinico;
    const superAdmin = !!clinico?.utilizador?.roles?.includes('superAdmin');
    const pode = (permissao: string) => !!clinico?.administrador || !!clinico?.permissoes?.includes(permissao);

    const verInternamentos = pode('show internamento');
    const verReferenciacao = pode('show referenciacao');

    const grupos: NavGroup[] = [
        // O que se usa todos os dias.
        ...(verInternamentos
            ? [
                  {
                      title: 'Hoje',
                      items: [
                          { title: 'Quadro', url: '/quadro', icon: LayoutGrid },
                          { title: 'O que me falta fazer', url: '/pendentes', icon: ListTodo },
                      ],
                  },
              ]
            : []),
        ...(pode('show agendamento')
            ? [
                  {
                      title: 'Bloco operatório',
                      items: [
                          {
                              title: 'Agendamento',
                              url: '/agendamento',
                              icon: CalendarDays,
                              filhos: [
                                  { title: 'Lista de espera', url: '/agendamento/lista-de-espera', icon: ClipboardList },
                                  { title: 'Mapa operatório', url: '/agendamento/mapa', icon: Map, novoSeparador: true },
                              ],
                          },
                      ],
                  },
              ]
            : []),
        // O percurso do doente: referenciação, internamento e seguimento.
        ...(verInternamentos || verReferenciacao
            ? [
                  {
                      title: 'Doentes',
                      items: [
                          ...(verReferenciacao ? [{ title: 'Referenciação', url: '/referenciacao', icon: Network }] : []),
                          ...(verInternamentos
                              ? [
                                    { title: 'Histórico dos internamentos', url: '/internamentos', icon: BedDouble },
                                    {
                                        title: 'Seguimentos aos 30 dias',
                                        url: '/internamentos/seguimentos',
                                        icon: ClipboardCheck,
                                    },
                                ]
                              : []),
                      ],
                  },
              ]
            : []),
        // Indicadores gerais e, à parte, os do centro de referência e a qualidade dos dados.
        ...(verInternamentos
            ? [
                  {
                      title: 'Análise',
                      items: [
                          { title: 'Análise global', url: '/dashboard', icon: BarChart3 },
                          ...(verReferenciacao ? [{ title: 'Análise colorretal', url: '/referenciacao/analise', icon: ChartLine }] : []),
                      ],
                  },
              ]
            : []),
        ...(verInternamentos && verReferenciacao
            ? [
                  {
                      title: 'Cancro retal',
                      items: [
                          { title: 'Centro de referência', url: '/analise/cancro-retal', icon: Activity },
                          { title: 'Qualidade dos dados', url: '/analise/cancro-retal/qualidade', icon: DatabaseZap },
                      ],
                  },
              ]
            : []),
        ...(clinico?.administrador
            ? [
                  {
                      title: 'Sistema',
                      items: [
                          { title: 'Utilizadores', url: '/admin/utilizadores', icon: ShieldCheck },
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
                <div className="px-2 group-data-[collapsible=icon]:hidden">
                    <VersaoDaApp />
                    <DireitosDeAutor />
                </div>
            </SidebarFooter>
        </Sidebar>
    );
}
