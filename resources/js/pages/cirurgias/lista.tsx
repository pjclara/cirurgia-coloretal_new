import { Link, usePage } from '@inertiajs/react';
import { formatarData, plural } from '../../clinico/formato';
import type { PropsPartilhadas } from '../../clinico/tipos';
import { BotaoLigacao, Seccao } from '../../components/clinico/campos';
import { NumeroProcesso } from '../../components/clinico/numero-processo';
import ClinicoLayout from '../../layouts/clinico-layout';

type Cirurgia = {
    id: number;
    data: string | null;
    tipo: string | null;
    abordagem: string | null;
    intervencoes: string[];
    internamento: { id: number; entrada: string | null; motivo: string | null };
};

type InternamentoResumo = { id: number; entrada: string | null; alta: string | null; motivo: string | null };

type Props = {
    utente: { id: number; nome: string; numero_processo: number };
    cirurgias: Cirurgia[];
    internamentos: InternamentoResumo[];
};

export default function ListaCirurgias({ utente, cirurgias, internamentos }: Props) {
    const { permissoes } = usePage<PropsPartilhadas>().props.clinico;
    const podeCriar = permissoes.includes('create internamento');
    // A cirurgia nova liga-se ao internamento aberto, ou ao mais recente.
    const alvo = internamentos.find((i) => !i.alta) ?? internamentos[0];

    return (
        <ClinicoLayout
            titulo="Cirurgias"
            subtitulo={
                <span>
                    <Link href={`/utentes/${utente.id}`} className="font-medium text-[#7d1830] hover:underline">
                        {utente.nome}
                    </Link>
                    <span className="tabular-nums">
                        {' '}
                        · <NumeroProcesso valor={utente.numero_processo} />
                    </span>
                </span>
            }
            acoes={podeCriar && alvo ? <BotaoLigacao href={`/internamentos/${alvo.id}/cirurgias/nova`}>Nova cirurgia</BotaoLigacao> : undefined}
        >
            <Seccao titulo="Cirurgias do utente" descricao={cirurgias.length > 0 ? plural(cirurgias.length, 'registo', 'registos') : undefined}>
                {cirurgias.length === 0 ? (
                    <p className="text-sm text-slate-600">
                        Este utente ainda não tem cirurgias registadas.
                        {!alvo && ' É preciso registar primeiro um internamento.'}
                    </p>
                ) : (
                    <ol className="divide-y divide-slate-100">
                        {cirurgias.map((c) => (
                            <li key={c.id} className="flex flex-wrap items-baseline gap-x-6 gap-y-1 py-3 first:pt-0 last:pb-0">
                                <p className="w-28 shrink-0 text-sm font-medium tabular-nums">{formatarData(c.data)}</p>
                                <div className="min-w-0 flex-1">
                                    <Link href={`/cirurgias/${c.id}`} className="text-sm font-medium text-[#7d1830] hover:underline">
                                        {c.intervencoes.length > 0 ? c.intervencoes.join(', ') : 'Sem intervenção indicada'}
                                    </Link>
                                    <p className="text-sm text-slate-600">{[c.tipo, c.abordagem].filter(Boolean).join(' · ')}</p>
                                </div>
                                <Link href={`/internamentos/${c.internamento.id}`} className="text-sm text-slate-600 hover:underline">
                                    Internamento de {formatarData(c.internamento.entrada)}
                                </Link>
                            </li>
                        ))}
                    </ol>
                )}
            </Seccao>
        </ClinicoLayout>
    );
}

ListaCirurgias.layout = (pagina: React.ReactNode) => pagina;
