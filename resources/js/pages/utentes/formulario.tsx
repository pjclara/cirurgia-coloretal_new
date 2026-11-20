import { useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { hojeIso } from '../../clinico/formato';
import { rotas } from '../../clinico/rotas';
import type { Opcao } from '../../clinico/tipos';
import { Botao, BotaoLigacao, Campo, Seccao, Selecao, estiloEntrada } from '../../components/clinico/campos';
import ClinicoLayout from '../../layouts/clinico-layout';

type Utente = {
    id: number;
    nome: string;
    numero_processo: number;
    numero_utente: number | null;
    data_nascimento: string | null;
    sexo_id: number;
    concelho_id: number;
    estado_civil_id: number | null;
    etnia_id: number | null;
    classe_social_id: number | null;
};

export type PropsUtente = {
    utente: Utente | null;
    processoSugerido: string;
    dominios: { sexos: Opcao[]; concelhos: Opcao[]; estados_civis: Opcao[]; etnias: Opcao[]; classes_sociais: Opcao[] };
};

type Dados = {
    nome: string;
    numero_processo: string;
    numero_utente: string;
    data_nascimento: string;
    sexo_id: number | '';
    concelho_id: number | '';
    estado_civil_id: number | '';
    etnia_id: number | '';
    classe_social_id: number | '';
};

type Props = PropsUtente;

type DadosColados = { numero_processo?: string; numero_utente?: string; nome?: string; sexo?: string; data_nascimento?: string };

/** Lê o texto copiado do processo clínico («Nº Processo: … Nº Utente: … Nome: … - Masculino Data de Nascimento: dd-mm-aaaa …»). */
function lerTextoDoProcesso(texto: string): DadosColados {
    const t = texto.replace(/\s+/g, ' ');
    const nascimento = t.match(/Data de Nascimento:?\s*(\d{2})[-/.](\d{2})[-/.](\d{4})/i);
    // O nome vai de «Nome:» até ao primeiro « - » (ou «;»).
    const nome = t.match(/Nome:?\s*(.+?)\s*(?:\s[-–]\s|;|$)/i);
    return {
        numero_processo: t.match(/N[ºo°.]*\s*Processo:?\s*(\d+)/i)?.[1],
        numero_utente: t.match(/N[ºo°.]*\s*Utente:?\s*(\d+)/i)?.[1],
        nome: nome?.[1].trim(),
        sexo: t.match(/(Masculino|Feminino)/i)?.[1],
        data_nascimento: nascimento ? `${nascimento[3]}-${nascimento[2]}-${nascimento[1]}` : undefined,
    };
}

/** O formulário do utente, sem página à volta; usado na página de edição e na modal de criação. */
export function CorpoFormularioUtente({
    utente,
    processoSugerido,
    dominios,
    origem,
    aoCancelar,
    aoGuardar,
}: PropsUtente & { origem?: 'quadro' | 'referenciacao'; aoCancelar?: () => void; aoGuardar?: () => void }) {
    const form = useForm<Dados>({
        nome: utente?.nome ?? '',
        numero_processo: utente ? String(utente.numero_processo) : processoSugerido,
        numero_utente: utente?.numero_utente ? String(utente.numero_utente) : '',
        data_nascimento: utente?.data_nascimento ?? '',
        sexo_id: utente?.sexo_id ?? '',
        concelho_id: utente?.concelho_id ?? '',
        estado_civil_id: utente?.estado_civil_id ?? '',
        etnia_id: utente?.etnia_id ?? '',
        classe_social_id: utente?.classe_social_id ?? '',
    });

    const guardar = (evento: FormEvent) => {
        evento.preventDefault();
        form.transform((atual) => ({ ...atual, ...(origem ? { origem } : {}), ...(aoGuardar ? { voltar: true } : {}) }));
        if (utente) form.put(rotas.utentes.ficha(utente.id), aoGuardar ? { preserveScroll: true, onSuccess: aoGuardar } : undefined);
        else form.post(rotas.utentes.lista);
    };

    const { data, errors, setData } = form;

    const [colado, setColado] = useState('');
    const [resultadoColado, setResultadoColado] = useState<string | null>(null);

    /** Pré-preenche a identificação a partir do texto colado; o que não for reconhecido fica como está. */
    const preencherDoTexto = (texto: string) => {
        setColado(texto);
        if (texto.trim() === '') return setResultadoColado(null);
        const lido = lerTextoDoProcesso(texto);
        // Pela inicial (M/F), para servir mesmo que a lista use «Masculino»/«M»/«Homem».
        const inicial = lido.sexo?.charAt(0).toLowerCase();
        const sexo = inicial ? dominios.sexos.find((o) => o.nome.trim().toLowerCase().startsWith(inicial)) : undefined;
        const preenchidos = [
            lido.nome && 'nome',
            lido.numero_processo && 'nº de processo',
            lido.numero_utente && 'nº de utente',
            lido.data_nascimento && 'data de nascimento',
            sexo && 'sexo',
        ].filter(Boolean);
        setData((atual) => ({
            ...atual,
            nome: lido.nome ?? atual.nome,
            numero_processo: lido.numero_processo ?? atual.numero_processo,
            numero_utente: lido.numero_utente ? lido.numero_utente.slice(0, 9) : atual.numero_utente,
            data_nascimento: lido.data_nascimento ?? atual.data_nascimento,
            sexo_id: sexo ? sexo.id : atual.sexo_id,
        }));
        setResultadoColado(
            preenchidos.length > 0
                ? `Preenchido: ${preenchidos.join(', ')}. Confirme os dados e indique o concelho.`
                : 'Não foi possível reconhecer dados neste texto.',
        );
    };

    return (
        <form onSubmit={guardar} className="max-w-3xl space-y-5" noValidate>
            {!utente && (
                <Seccao titulo="Colar dados do processo">
                    <Campo
                        rotulo="Texto copiado do processo clínico"
                        para="colar_processo"
                        ajuda={resultadoColado ?? 'Cole aqui o texto para pré-preencher a identificação.'}
                        opcional
                    >
                        <textarea
                            id="colar_processo"
                            rows={3}
                            value={colado}
                            onChange={(e) => preencherDoTexto(e.target.value)}
                            className={estiloEntrada}
                            placeholder="Nº Processo: … Nº Utente: … Nome: … - Masculino Data de Nascimento: …"
                        />
                    </Campo>
                </Seccao>
            )}

            <Seccao titulo="Identificação">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Campo rotulo="Nome completo" para="nome" erro={errors.nome} className="sm:col-span-2">
                        <input
                            id="nome"
                            value={data.nome}
                            onChange={(e) => setData('nome', e.target.value)}
                            className={estiloEntrada}
                            aria-invalid={!!errors.nome}
                            autoComplete="off"
                        />
                    </Campo>

                    <Campo rotulo="Número de processo" para="numero_processo" erro={errors.numero_processo}>
                        <input
                            id="numero_processo"
                            inputMode="numeric"
                            value={data.numero_processo}
                            onChange={(e) => setData('numero_processo', e.target.value.replace(/\D/g, ''))}
                            className={`${estiloEntrada} tabular-nums`}
                            aria-invalid={!!errors.numero_processo}
                        />
                    </Campo>

                    <Campo rotulo="Número de utente" para="numero_utente" erro={errors.numero_utente} ajuda="9 dígitos." opcional>
                        <input
                            id="numero_utente"
                            inputMode="numeric"
                            maxLength={9}
                            value={data.numero_utente}
                            onChange={(e) => setData('numero_utente', e.target.value.replace(/\D/g, ''))}
                            className={`${estiloEntrada} tabular-nums`}
                            aria-invalid={!!errors.numero_utente}
                        />
                    </Campo>

                    <Campo rotulo="Data de nascimento" para="data_nascimento" erro={errors.data_nascimento}>
                        <input
                            id="data_nascimento"
                            type="date"
                            min="1900-01-01"
                            max={hojeIso()}
                            value={data.data_nascimento}
                            onChange={(e) => setData('data_nascimento', e.target.value)}
                            className={estiloEntrada}
                            aria-invalid={!!errors.data_nascimento}
                        />
                    </Campo>

                    <Campo rotulo="Sexo" para="sexo_id" erro={errors.sexo_id}>
                        <Selecao
                            id="sexo_id"
                            opcoes={dominios.sexos}
                            valor={data.sexo_id}
                            aoMudar={(v) => setData('sexo_id', v)}
                            invalido={!!errors.sexo_id}
                        />
                    </Campo>

                    <Campo rotulo="Concelho" para="concelho_id" erro={errors.concelho_id}>
                        <Selecao
                            id="concelho_id"
                            opcoes={dominios.concelhos}
                            valor={data.concelho_id}
                            aoMudar={(v) => setData('concelho_id', v)}
                            invalido={!!errors.concelho_id}
                        />
                    </Campo>
                </div>
            </Seccao>

            <div className="flex gap-2">
                <Botao type="submit" disabled={form.processing}>
                    {utente ? 'Guardar alterações' : 'Criar utente'}
                </Botao>
                {aoCancelar ? (
                    <Botao type="button" variante="secundario" onClick={aoCancelar}>
                        Cancelar
                    </Botao>
                ) : (
                    <BotaoLigacao href={utente ? rotas.utentes.ficha(utente.id) : rotas.utentes.lista} variante="secundario">
                        Cancelar
                    </BotaoLigacao>
                )}
            </div>
        </form>
    );
}

export default function FormularioUtente(props: Props) {
    return (
        <ClinicoLayout titulo={props.utente ? 'Editar utente' : 'Criar utente'} subtitulo={props.utente?.nome}>
            <CorpoFormularioUtente {...props} />
        </ClinicoLayout>
    );
}

FormularioUtente.layout = (pagina: ReactNode) => pagina;
