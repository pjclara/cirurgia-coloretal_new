import { formatar, type Ponto } from './graficos';

const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

/** "2024-03" -> "mar 24". */
export const nomeMes = (ym: string) => `${MESES[Number(ym.slice(5)) - 1]} ${ym.slice(2, 4)}`;

/** Linhas de tabela de uma série: rótulo e valor formatado ("—" sem valor). */
export const tabela = (pontos: Ponto[], sufixo = '') => pontos.map((p) => [p.rotulo, p.valor === null ? '—' : `${formatar(p.valor)}${sufixo}`]);

/** Trimestre ou intervalo de trimestres escolhido, para as notas dos gráficos. */
export const nomePeriodo = (de: string | null, ate: string | null) => (de === ate ? (de ?? '') : `${de} a ${ate}`);
