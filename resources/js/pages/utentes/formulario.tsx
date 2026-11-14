import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
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

type Props = {
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

export default function FormularioUtente({ utente, processoSugerido, dominios }: Props) {
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
        if (utente) form.put(rotas.utentes.ficha(utente.id));
        else form.post(rotas.utentes.lista);
    };

    const { data, errors, setData } = form;

    return (
        <ClinicoLayout titulo={utente ? 'Editar utente' : 'Criar utente'} subtitulo={utente?.nome}>
            <form onSubmit={guardar} className="max-w-3xl space-y-5" noValidate>
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
                            <Selecao id="sexo_id" opcoes={dominios.sexos} valor={data.sexo_id} aoMudar={(v) => setData('sexo_id', v)} invalido={!!errors.sexo_id} />
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

                <Seccao titulo="Dados sociais">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Campo rotulo="Estado civil" para="estado_civil_id" erro={errors.estado_civil_id} opcional>
                            <Selecao
                                id="estado_civil_id"
                                opcoes={dominios.estados_civis}
                                valor={data.estado_civil_id}
                                aoMudar={(v) => setData('estado_civil_id', v)}
                                vazio="Sem informação"
                            />
                        </Campo>
                        <Campo rotulo="Etnia" para="etnia_id" erro={errors.etnia_id} opcional>
                            <Selecao id="etnia_id" opcoes={dominios.etnias} valor={data.etnia_id} aoMudar={(v) => setData('etnia_id', v)} vazio="Sem informação" />
                        </Campo>
                        <Campo rotulo="Classe social" para="classe_social_id" erro={errors.classe_social_id} opcional>
                            <Selecao
                                id="classe_social_id"
                                opcoes={dominios.classes_sociais}
                                valor={data.classe_social_id}
                                aoMudar={(v) => setData('classe_social_id', v)}
                                vazio="Sem informação"
                            />
                        </Campo>
                    </div>
                </Seccao>

                <div className="flex gap-2">
                    <Botao type="submit" disabled={form.processing}>
                        {utente ? 'Guardar alterações' : 'Criar utente'}
                    </Botao>
                    <BotaoLigacao href={utente ? rotas.utentes.ficha(utente.id) : rotas.utentes.lista} variante="secundario">
                        Cancelar
                    </BotaoLigacao>
                </div>
            </form>
        </ClinicoLayout>
    );
}

FormularioUtente.layout = (pagina: ReactNode) => pagina;
