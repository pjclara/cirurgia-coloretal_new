import { useState } from 'react';
import { Botao } from '../clinico/campos';
import { SinalizarCentro } from './sinalizar-centro';

export type DadosSinalizacao = {
    origens: { id: number; nome: string }[];
    responsaveis: { id: number; nome: string; equipa: string | null; perfil?: string | null }[];
};

/**
 * Pergunta, ao registar um internamento ou ao agendar, se um utente ainda não sinalizado para o centro de referência
 * deve ser sinalizado. Não bloqueia o registo. Só aparece com permissão para sinalizar (`sinalizacao` preenchido).
 */
export function PerguntaCentroReferencia({
    utente,
    sinalizacao,
}: {
    utente: { id: number; nome: string; sinalizado?: boolean } | null | undefined;
    sinalizacao: DadosSinalizacao | null | undefined;
}) {
    const [aSinalizar, setASinalizar] = useState(false);
    const [semSinalizar, setSemSinalizar] = useState(false);

    if (!utente || utente.sinalizado !== false || !sinalizacao || semSinalizar) return null;

    return (
        <div
            role="group"
            aria-label="Centro de referência"
            className="mb-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
        >
            <p className="font-medium">Este utente não está sinalizado para o centro de referência.</p>
            <p className="mt-0.5">Quer sinalizá-lo agora? Pode continuar em qualquer dos casos.</p>
            <div className="mt-2 flex gap-2">
                <Botao onClick={() => setASinalizar(true)}>Sinalizar agora</Botao>
                <Botao variante="secundario" onClick={() => setSemSinalizar(true)}>
                    Não
                </Botao>
            </div>
            <SinalizarCentro
                utenteId={utente.id}
                nome={utente.nome}
                origens={sinalizacao.origens}
                responsaveis={sinalizacao.responsaveis}
                aberto={aSinalizar}
                aoFechar={() => setASinalizar(false)}
            />
        </div>
    );
}
