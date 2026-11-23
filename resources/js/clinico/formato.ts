/** As datas circulam como 'AAAA-MM-DD'; estas funções evitam problemas de fuso horário. */

function partes(data: string): [number, number, number] {
    const [a, m, d] = data.split('-').map(Number);
    return [a, m, d];
}

export function hojeIso(): string {
    const agora = new Date();
    const mes = String(agora.getMonth() + 1).padStart(2, '0');
    const dia = String(agora.getDate()).padStart(2, '0');
    return `${agora.getFullYear()}-${mes}-${dia}`;
}

export function formatarData(data: string | null | undefined): string {
    if (!data) return '—';
    const [a, m, d] = data.split('-');
    return `${d}/${m}/${a}`;
}

export function diasEntre(inicio: string, fim: string): number {
    const [a1, m1, d1] = partes(inicio);
    const [a2, m2, d2] = partes(fim);
    return Math.round((Date.UTC(a2, m2 - 1, d2) - Date.UTC(a1, m1 - 1, d1)) / 86_400_000);
}

export function idade(nascimento: string | null | undefined, referencia: string = hojeIso()): number | null {
    if (!nascimento) return null;
    const [a1, m1, d1] = partes(nascimento);
    const [a2, m2, d2] = partes(referencia);
    let anos = a2 - a1;
    if (m2 < m1 || (m2 === m1 && d2 < d1)) anos -= 1;
    return anos >= 0 && anos < 130 ? anos : null;
}

export function plural(n: number, singular: string, pluralizado: string): string {
    return `${n} ${n === 1 ? singular : pluralizado}`;
}

const LIGACOES = new Set(['de', 'da', 'do', 'das', 'dos', 'e']);

/** No máximo três palavras: o primeiro nome e os dois últimos (sem «de», «da», «dos»…). */
export function nomeCurto(nome: string | null) {
    if (!nome) return 'Utente sem ficha';
    const palavras = nome.trim().split(/\s+/);
    if (palavras.length <= 3) return nome.trim();
    const uteis = palavras.filter((p) => !LIGACOES.has(p.toLowerCase()));
    return (uteis.length <= 3 ? uteis : [uteis[0], ...uteis.slice(-2)]).join(' ');
}
