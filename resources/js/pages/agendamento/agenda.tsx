import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { CATEGORIAS, COR_ESTADO, NOMES_DIA, nomeEstado, type Categoria } from '../../clinico/agendamento';
import { formatarData } from '../../clinico/formato';
import type { Opcao, PropsPartilhadas } from '../../clinico/tipos';
import { ModalAgendamento, type Modal } from '../../components/agendamento/modal-agendamento';
import { ModalValidarAmbulatorios, type AmbulatorioPorValidar } from '../../components/agendamento/modal-validar-ambulatorios';
import { NovaInscricao } from '../../components/agendamento/nova-inscricao';
import { PainelEspera, type Espera } from '../../components/agendamento/painel-espera';
import { Botao, BotaoLigacao } from '../../components/clinico/campos';
import { EtiquetaCr } from '../../components/clinico/etiqueta-cr';
import { NumeroProcesso } from '../../components/clinico/numero-processo';
import ClinicoLayout from '../../layouts/clinico-layout';

type Item = {
    id: number;
    data: string;
    utente: { id: number | null; nome: string | null; numero_processo: number | null; cr: boolean };
    intervencoes: string[];
    hora: string | null;
    periodo: string | null;
    local: string | null;
    sala: string | null;
    tipo: string | null;
    categoria: Categoria;
    estado: number | null;
    responsavel: string | null;
    reserva_sangue: boolean;
};

type Props = {
    dias: { data: string; agendamentos: Item[] }[];
    vista: 'semana' | 'mes';
    semana: string;
    mes: string;
    anterior: string;
    seguinte: string;
    hoje: string;
    cancelados: boolean;
    estados: Opcao[];
    modal: Modal | null;
    espera: Espera;
    ambulatoriosPorValidar: AmbulatorioPorValidar[];
};

/** Quantos itens cabem numa célula do mês antes do "+ N mais". */
const MAX_NO_DIA = 3;

/** Operado: riscado (já está feito); cancelado: esbatido, para não se confundirem. */
const estiloDoEstado = (estado: number | null) => (estado === 5 ? 'line-through decoration-2 opacity-70' : estado === 4 ? 'opacity-50 italic' : '');

export default function Agenda({
    dias,
    vista,
    mes,
    semana,
    anterior,
    seguinte,
    hoje,
    cancelados,
    estados,
    modal,
    espera,
    ambulatoriosPorValidar,
}: Props) {
    const { permissoes } = usePage<PropsPartilhadas>().props.clinico;
    const ir = (parametros: Record<string, string | boolean>) => router.get('/agendamento', parametros, { preserveScroll: true });
    // O que se está a arrastar: um agendamento (mover) ou um doente da lista de espera (agendar).
    const [arrastado, setArrastado] = useState<{ tipo: 'agendamento' | 'espera'; id: number } | null>(null);
    const [sobre, setSobre] = useState<string | null>(null);
    const [validarAmbulatorios, setValidarAmbulatorios] = useState(false);
    const podeMover = permissoes.includes('update agendamento');
    const podeCriar = permissoes.includes('create agendamento');
    /** Pode-se largar neste dia? Mover agendamentos vale para qualquer dia; agendar a partir da lista só de hoje em diante. */
    const aceita = (data: string) =>
        arrastado?.tipo === 'agendamento' ? podeMover : arrastado?.tipo === 'espera' ? podeCriar && data >= hoje : false;
    const largar = (data: string) => {
        const dado = arrastado;
        setArrastado(null);
        setSobre(null);
        if (!dado || !aceita(data)) return;
        if (dado.tipo === 'agendamento') mover(dado.id, data);
        else router.get(`/agendamento/lista-de-espera/${dado.id}/agendar`, { agendar_em: data }, { preserveScroll: true });
    };
    /** Atributos de uma zona onde se pode largar (um dia do calendário). */
    const zona = (data: string) => ({
        onDragOver: (e: React.DragEvent) => {
            if (!aceita(data)) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (sobre !== data) setSobre(data);
        },
        onDragLeave: () => sobre === data && setSobre(null),
        onDrop: (e: React.DragEvent) => {
            e.preventDefault();
            largar(data);
        },
    });
    const realce = (data: string) =>
        sobre === data ? 'ring-2 ring-inset ring-[#a3213e] bg-[#a3213e]/5' : arrastado && aceita(data) ? 'bg-slate-50' : '';
    const mover = (id: number, data: string) => {
        const origem = dias.flatMap((d) => d.agendamentos.map((a) => ({ ...a }))).find((a) => a.id === id);
        if (!origem || origem.data === data) return;
        router.patch(
            `/agendamento/${id}/mover`,
            { data },
            {
                preserveScroll: true,
                onError: (erros) => {
                    if (erros.conflito && window.confirm(`${erros.conflito}\n\nMover mesmo assim?`)) {
                        router.patch(`/agendamento/${id}/mover`, { data, forcar: true }, { preserveScroll: true });
                    }
                },
            },
        );
    };
    const eMes = vista === 'mes';
    const visiveis = dias.filter((d, n) => n < 5 || d.agendamentos.length > 0);
    const nomeMes = new Date(mes + 'T00:00:00').toLocaleDateString('pt-PT', { month: 'long', year: 'numeric' });
    const referencia = eMes ? mes : semana;
    const mesAtual = new Date(mes + 'T00:00:00').getMonth();
    const total = dias.reduce((soma, d) => soma + d.agendamentos.length, 0);

    return (
        <ClinicoLayout
            titulo="Agendamento"
            subtitulo={`${eMes ? nomeMes.charAt(0).toUpperCase() + nomeMes.slice(1) : `Semana de ${formatarData(semana)}`} · ${total} ${total === 1 ? 'cirurgia agendada' : 'cirurgias agendadas'}`}
            largura="larga"
            acoes={
                <>
                    {ambulatoriosPorValidar.length > 0 && (
                        <Botao variante="secundario" onClick={() => setValidarAmbulatorios(true)}>
                            Validar ambulatórios ({ambulatoriosPorValidar.length})
                        </Botao>
                    )}
                    <BotaoLigacao
                        href={`/agendamento/mapa?vista=semana&data=${eMes ? (mes.slice(0, 7) === hoje.slice(0, 7) ? hoje : mes) : semana}`}
                        variante="secundario"
                        novoSeparador
                    >
                        Mapa para imprimir
                    </BotaoLigacao>
                    <BotaoLigacao href="/agendamento/lista-de-espera" variante="secundario">
                        Lista de espera
                    </BotaoLigacao>
                </>
            }
        >
            {validarAmbulatorios && <ModalValidarAmbulatorios lista={ambulatoriosPorValidar} aoFechar={() => setValidarAmbulatorios(false)} />}

            <NovaInscricao />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <div className="mr-2 inline-flex overflow-hidden rounded-md border border-slate-300">
                    {(['mes', 'semana'] as const).map((v) => (
                        <button
                            key={v}
                            type="button"
                            onClick={() => ir({ vista: v, semana: referencia, cancelados })}
                            className={`px-3 py-1.5 text-sm ${vista === v ? 'bg-[#a3213e] text-white' : 'bg-white hover:bg-slate-50'}`}
                        >
                            {v === 'mes' ? 'Mês' : 'Semana'}
                        </button>
                    ))}
                </div>
                <Botao variante="secundario" onClick={() => ir({ vista, semana: anterior, cancelados })}>
                    ‹ {eMes ? 'Mês anterior' : 'Semana anterior'}
                </Botao>
                <Botao variante="secundario" onClick={() => ir({ vista, cancelados })}>
                    {eMes ? 'Este mês' : 'Esta semana'}
                </Botao>
                <Botao variante="secundario" onClick={() => ir({ vista, semana: seguinte, cancelados })}>
                    {eMes ? 'Mês seguinte' : 'Semana seguinte'} ›
                </Botao>
                <label className="ml-auto flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={cancelados} onChange={(e) => ir({ vista, semana: referencia, cancelados: e.target.checked })} />
                    Mostrar cancelados
                </label>
            </div>

            <ul className="mb-4 flex flex-wrap items-center gap-x-5 gap-y-1.5 text-sm" aria-label="Legenda das cores">
                {(Object.keys(CATEGORIAS) as Categoria[]).map((c) => {
                    const n = dias.reduce((soma, d) => soma + d.agendamentos.filter((a) => a.categoria === c).length, 0);
                    return (
                        <li key={c} className="flex items-center gap-2 text-slate-700">
                            <span
                                className={`inline-block size-3 rounded-sm ${CATEGORIAS[c].ponto} ${c === 'adicional' ? 'ring-1 ring-amber-700 ring-offset-1' : ''}`}
                                aria-hidden="true"
                            />
                            {CATEGORIAS[c].rotulo}
                            <span className="text-xs text-slate-500 tabular-nums">({n})</span>
                        </li>
                    );
                })}
                <li className="text-xs text-slate-500">✓ riscado = operado · ✕ esbatido = cancelado</li>
            </ul>

            <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="min-w-0">
                    {eMes && (
                        <div className="overflow-x-auto">
                            <div className="grid min-w-[56rem] grid-cols-7 overflow-hidden rounded-lg border border-slate-200 bg-white">
                                {['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'].map((n) => (
                                    <div key={n} className="border-b border-slate-200 bg-slate-50 px-2 py-1.5 text-xs font-semibold text-slate-600">
                                        {n}
                                    </div>
                                ))}
                                {dias.map((dia) => {
                                    const d = new Date(dia.data + 'T00:00:00');
                                    const doMes = d.getMonth() === mesAtual;
                                    const eHoje = dia.data === hoje;
                                    return (
                                        <div
                                            key={dia.data}
                                            {...zona(dia.data)}
                                            className={`group min-h-28 border-r border-b border-slate-100 p-1.5 ${doMes ? 'bg-white' : 'bg-slate-50/70'} ${realce(dia.data)}`}
                                        >
                                            <span
                                                className={`mb-1 inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1 text-xs tabular-nums ${
                                                    eHoje ? 'bg-[#a3213e] font-semibold text-white' : doMes ? 'text-slate-800' : 'text-slate-400'
                                                }`}
                                            >
                                                {d.getDate()}
                                            </span>
                                            {podeCriar && dia.data >= hoje && (
                                                <Link
                                                    href={`/agendamento/lista-de-espera?estado=abertas&agendar_em=${dia.data}`}
                                                    title="Agendar neste dia"
                                                    className="float-right rounded px-1.5 text-sm text-slate-400 opacity-0 group-hover:opacity-100 hover:bg-slate-100 hover:text-[#7d1830] focus:opacity-100"
                                                >
                                                    +
                                                </Link>
                                            )}
                                            <ul className="clear-both space-y-1">
                                                {dia.agendamentos.slice(0, MAX_NO_DIA).map((a) => (
                                                    <li key={a.id}>
                                                        <Link
                                                            draggable={podeMover}
                                                            onDragStart={(e) => {
                                                                e.dataTransfer.setData('text/plain', `agendamento:${a.id}`);
                                                                e.dataTransfer.effectAllowed = 'move';
                                                                setArrastado({ tipo: 'agendamento', id: a.id });
                                                            }}
                                                            onDragEnd={() => {
                                                                setArrastado(null);
                                                                setSobre(null);
                                                            }}
                                                            href={permissoes.includes('show agendamento') ? `/agendamento/${a.id}` : '#'}
                                                            title={`${CATEGORIAS[a.categoria].rotulo}${a.estado === 4 ? ' (cancelado)' : a.estado === 5 ? ' (operado)' : ''} · Processo ${a.utente.numero_processo ?? '—'} — ${a.intervencoes.join(', ')}`}
                                                            className={`block rounded px-1.5 py-0.5 text-xs leading-tight ${CATEGORIAS[a.categoria].chip} ${estiloDoEstado(a.estado)}`}
                                                        >
                                                            <span className="block truncate font-medium">
                                                                {a.estado === 5 ? '✓ ' : a.estado === 4 ? '✕ ' : ''}
                                                                {[
                                                                    a.hora ?? a.periodo?.charAt(0),
                                                                    a.sala ? `S${a.sala}` : null,
                                                                    a.utente.numero_processo ?? 'Sem processo',
                                                                ]
                                                                    .filter(Boolean)
                                                                    .join(' · ')}
                                                            </span>
                                                            <span className="block truncate opacity-80">
                                                                {a.intervencoes.length > 0 ? a.intervencoes.join(', ') : 'Sem intervenção'}
                                                            </span>
                                                        </Link>
                                                    </li>
                                                ))}
                                                {dia.agendamentos.length > MAX_NO_DIA && (
                                                    <li>
                                                        <button
                                                            type="button"
                                                            onClick={() => ir({ vista: 'semana', semana: dia.data, cancelados })}
                                                            className="px-1.5 text-xs text-[#7d1830] hover:underline"
                                                        >
                                                            + {dia.agendamentos.length - MAX_NO_DIA} mais
                                                        </button>
                                                    </li>
                                                )}
                                            </ul>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {!eMes && (
                        <div className="space-y-4">
                            {visiveis.map((dia) => {
                                const d = new Date(dia.data + 'T00:00:00');
                                const eHoje = dia.data === hoje;
                                return (
                                    <section
                                        key={dia.data}
                                        {...zona(dia.data)}
                                        className={`rounded-lg border bg-white ${eHoje ? 'border-[#a3213e]' : 'border-slate-200'} ${realce(dia.data)}`}
                                    >
                                        <header
                                            className={`flex items-baseline gap-3 border-b px-4 py-2 ${eHoje ? 'border-[#a3213e]/40 bg-[#a3213e]/5' : 'border-slate-200 bg-slate-50'}`}
                                        >
                                            <h2 className="text-sm font-semibold">{NOMES_DIA[d.getDay()]}</h2>
                                            <span className="text-sm text-slate-600 tabular-nums">{formatarData(dia.data)}</span>
                                            {eHoje && <span className="rounded bg-[#a3213e] px-1.5 py-0.5 text-xs font-medium text-white">Hoje</span>}
                                        </header>
                                        {dia.agendamentos.length === 0 ? (
                                            <p className="px-4 py-3 text-sm text-slate-500">Sem cirurgias agendadas.</p>
                                        ) : (
                                            <ul className="divide-y divide-slate-100">
                                                {dia.agendamentos.map((a) => (
                                                    <li
                                                        key={a.id}
                                                        className={`flex flex-wrap items-baseline gap-x-5 gap-y-1 border-l-4 px-4 py-2.5 ${CATEGORIAS[a.categoria].faixa} ${a.estado === 4 ? 'opacity-60' : ''}`}
                                                    >
                                                        <p className="w-36 shrink-0 text-xs text-slate-600">
                                                            {[a.hora, a.periodo, a.sala ? `Sala ${a.sala}` : null].filter(Boolean).join(' · ') || '—'}
                                                        </p>
                                                        <div
                                                            className={`min-w-0 flex-1 ${a.estado === 5 ? 'line-through decoration-2 opacity-70' : ''}`}
                                                        >
                                                            {a.utente.id ? (
                                                                <Link
                                                                    href={`/utentes/${a.utente.id}`}
                                                                    className="text-sm font-semibold hover:underline"
                                                                >
                                                                    {a.utente.nome}
                                                                </Link>
                                                            ) : (
                                                                <span className="text-sm font-semibold">Utente sem ficha</span>
                                                            )}
                                                            {a.utente.cr && <EtiquetaCr />}
                                                            <span className="ml-2 text-sm text-slate-600 tabular-nums">
                                                                <NumeroProcesso valor={a.utente.numero_processo} />
                                                            </span>
                                                            <p className="text-sm text-slate-700">
                                                                {a.intervencoes.length > 0 ? a.intervencoes.join(', ') : 'Sem intervenção indicada'}
                                                            </p>
                                                            <p className="text-xs text-slate-500">
                                                                {[a.tipo, a.local, a.responsavel].filter(Boolean).join(' · ')}
                                                                {a.reserva_sangue && ' · Reserva de sangue'}
                                                            </p>
                                                        </div>
                                                        <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${COR_ESTADO[a.estado ?? 6]}`}>
                                                            {nomeEstado(estados, a.estado)}
                                                        </span>
                                                        {permissoes.includes('show agendamento') && (
                                                            <Link href={`/agendamento/${a.id}`} className="text-sm text-[#7d1830] hover:underline">
                                                                Abrir
                                                            </Link>
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </section>
                                );
                            })}
                        </div>
                    )}
                </div>
                <PainelEspera
                    espera={espera}
                    hoje={hoje}
                    podeAgendar={podeCriar}
                    aoArrastar={(id) => setArrastado(id === null ? null : { tipo: 'espera', id })}
                    aoSoltar={() => {
                        setArrastado(null);
                        setSobre(null);
                    }}
                    parametros={{ vista, semana: referencia, cancelados }}
                />
            </div>
            <ModalAgendamento modal={modal} />
        </ClinicoLayout>
    );
}

Agenda.layout = (pagina: React.ReactNode) => pagina;
