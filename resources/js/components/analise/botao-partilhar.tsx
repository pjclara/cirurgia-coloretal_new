import { Download } from 'lucide-react';

type Props = {
    /** Nome do ficheiro, sem extensão (ex.: "analise-global"). */
    ficheiro: string;
    /** Período escolhido, mostrado no topo do ficheiro (os seletores não vão para o ficheiro). */
    periodo?: string | null;
};

const textoDasRegras = () => {
    const css: string[] = [];
    for (const folha of Array.from(document.styleSheets)) {
        try {
            for (const regra of Array.from(folha.cssRules)) css.push(regra.cssText);
        } catch {
            // folha de outra origem: não é legível
        }
    }
    return css.join('\n');
};

const escapar = (t: string) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/**
 * Guarda a página de análise como um único ficheiro HTML, sem dependências, para partilhar por email ou mensagem.
 * O ficheiro é uma fotografia dos números e gráficos mostrados: sem filtros, menus nem ligações à aplicação.
 */
export function BotaoPartilhar({ ficheiro, periodo }: Props) {
    const guardar = () => {
        const origem = document.getElementById('conteudo');
        if (!origem) return;

        const copia = origem.cloneNode(true) as HTMLElement;
        copia.querySelectorAll('[data-sem-exportar], script, [role="status"], [role="alert"]').forEach((e) => e.remove());
        // Ligações e botões da aplicação não fazem sentido fora dela.
        // Os valores que a página só mostra ao passar o rato, ou na vista de tabela, ficam visíveis: gráfico e tabela.
        copia.querySelectorAll('[data-grafico], [data-tabela]').forEach((e) => {
            e.classList.remove('hidden', 'max-h-64', 'overflow-auto');
            if (e.hasAttribute('data-tabela')) e.classList.add('mt-3');
        });
        copia.querySelectorAll('[role="status"]').forEach((e) => e.remove());
        copia.querySelectorAll('a').forEach((a) => a.replaceWith(...Array.from(a.childNodes)));
        copia.querySelectorAll('button').forEach((b) => b.removeAttribute('onclick'));
        copia.querySelectorAll('[class*="transition-opacity"]').forEach((e) => e.classList.remove('opacity-50'));

        if (periodo) {
            const titulo = copia.querySelector('h1');
            const nota = document.createElement('p');
            nota.className = 'text-muted-foreground mt-1 text-sm font-medium';
            nota.textContent = `Período: ${periodo}`;
            titulo?.parentElement?.appendChild(nota);
        }

        const titulo = origem.querySelector('h1')?.textContent ?? 'Análise';
        const html = `<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escapar(titulo)}</title>
<style>
${textoDasRegras()}
body { margin: 0; background: #f5f5f3; color: #1a1a1a; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
#conteudo { padding-top: 2rem; padding-bottom: 2rem; }
</style>
</head>
<body>
${copia.outerHTML}
</body>
</html>`;

        const ligacao = document.createElement('a');
        ligacao.href = URL.createObjectURL(new Blob([html], { type: 'text/html;charset=utf-8' }));
        ligacao.download = `${ficheiro}${periodo ? `-${periodo.replace(/[^\p{L}\p{N}]+/gu, '-')}` : ''}.html`;
        document.body.appendChild(ligacao);
        ligacao.click();
        ligacao.remove();
        setTimeout(() => URL.revokeObjectURL(ligacao.href), 1000);
    };

    return (
        <button
            type="button"
            data-sem-exportar
            onClick={guardar}
            title="Guarda esta página num ficheiro HTML para partilhar"
            className="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-teal-700 dark:border-slate-600 dark:bg-transparent dark:text-slate-100 dark:hover:bg-white/10"
        >
            <Download className="size-4" aria-hidden="true" />
            Partilhar (HTML)
        </button>
    );
}
