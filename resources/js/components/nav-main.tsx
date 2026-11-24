import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { type NavGroup, type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';

const caminho = (url: string) => url.split('?')[0].replace(/\/+$/, '') || '/';

/** Caminho mais longo, entre os da ligação, que corresponde à página (igual ou sub-caminho); 0 se nenhum. */
const comprimentoDaCorrespondencia = (item: NavItem, atual: string) =>
    [item.url, ...(item.alias ?? [])]
        .map(caminho)
        .filter((base) => atual === base || atual.startsWith(base + '/'))
        .reduce((maior, base) => Math.max(maior, base.length), 0);

const corresponde = (item: NavItem, atual: string) => comprimentoDaCorrespondencia(item, atual) > 0;

const CHAVE_ARMAZEM = 'menu.subitens';

/** O que a pessoa abriu ou fechou à mão (por ligação): lembra-se neste browser. */
function lerEscolhas(): Record<string, boolean> {
    try {
        return JSON.parse(localStorage.getItem(CHAVE_ARMAZEM) ?? '{}') ?? {};
    } catch {
        return {};
    }
}

export function NavMain({ grupos = [] }: { grupos: NavGroup[] }) {
    const atual = caminho(usePage().url);
    // Com o menu reduzido a ícones não se escondem grupos: só se recolhem com o menu aberto.
    const reduzido = useSidebar().state === 'collapsed';
    const [escolhas, setEscolhas] = useState<Record<string, boolean>>(lerEscolhas);
    const alternar = (url: string, abertoAgora: boolean) =>
        setEscolhas((anteriores) => {
            const novas = { ...anteriores, [url]: !abertoAgora };
            try {
                localStorage.setItem(CHAVE_ARMAZEM, JSON.stringify(novas));
            } catch {
                // Sem armazenamento: a escolha vale só nesta página.
            }
            return novas;
        });

    // A ligação mais específica (caminho mais longo) é a activa; evita realçar "Internamentos" em "/internamentos/seguimentos".
    const todas = grupos.flatMap((g) => g.items.flatMap((i) => [i, ...(i.filhos ?? [])]));
    const activa = todas
        .filter((i) => corresponde(i, atual))
        .sort((a, b) => comprimentoDaCorrespondencia(b, atual) - comprimentoDaCorrespondencia(a, atual))[0];

    return (
        <>
            {grupos.map((grupo) => {
                // Os grupos começam abertos; depois vale o que a pessoa escolheu.
                const grupoAberto = escolhas[`grupo:${grupo.title}`] ?? true;
                return (
                    <SidebarGroup key={grupo.title} className="px-2 py-1">
                        <SidebarGroupLabel asChild>
                            <button
                                type="button"
                                onClick={() => alternar(`grupo:${grupo.title}`, grupoAberto)}
                                aria-expanded={grupoAberto}
                                title={grupoAberto ? 'Recolher' : 'Expandir'}
                                className="hover:text-sidebar-foreground w-full justify-between"
                            >
                                {grupo.title}
                                <ChevronRight
                                    className={`transition-transform group-data-[collapsible=icon]:hidden ${grupoAberto ? 'rotate-90' : ''}`}
                                />
                            </button>
                        </SidebarGroupLabel>
                        <SidebarMenu className={grupoAberto || reduzido ? undefined : 'hidden'}>
                            {grupo.items.map((item) => {
                                // Por omissão a secção da página atual abre-se; depois vale o que a pessoa escolheu (abrir ou fechar).
                                const naPagina = corresponde(item, atual) || item.filhos?.some((f) => f === activa);
                                const seccaoAberta = escolhas[item.url] ?? naPagina;
                                return (
                                    <SidebarMenuItem key={item.url}>
                                        <SidebarMenuButton asChild isActive={item === activa} tooltip={item.title}>
                                            <Link href={item.url} prefetch aria-current={item === activa ? 'page' : undefined}>
                                                {item.icon && <item.icon />}
                                                <span>{item.title}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                        {item.filhos && (
                                            <SidebarMenuAction
                                                onClick={() => alternar(item.url, !!seccaoAberta)}
                                                aria-expanded={!!seccaoAberta}
                                                aria-label={`${seccaoAberta ? 'Fechar' : 'Abrir'} ${item.title}`}
                                                title={seccaoAberta ? 'Fechar' : 'Abrir'}
                                            >
                                                <ChevronRight className={`transition-transform ${seccaoAberta ? 'rotate-90' : ''}`} />
                                            </SidebarMenuAction>
                                        )}
                                        {item.filhos && seccaoAberta && (
                                            <SidebarMenuSub>
                                                {item.filhos.map((filho) => (
                                                    <SidebarMenuSubItem key={filho.url}>
                                                        <SidebarMenuSubButton asChild isActive={filho === activa}>
                                                            {filho.novoSeparador ? (
                                                                <a href={filho.url} target="_blank" rel="noopener noreferrer">
                                                                    {filho.icon && <filho.icon />}
                                                                    <span>{filho.title}</span>
                                                                </a>
                                                            ) : (
                                                                <Link href={filho.url} prefetch aria-current={filho === activa ? 'page' : undefined}>
                                                                    {filho.icon && <filho.icon />}
                                                                    <span>{filho.title}</span>
                                                                </Link>
                                                            )}
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
                );
            })}
        </>
    );
}
