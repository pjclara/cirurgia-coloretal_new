import { router, usePage } from '@inertiajs/react';
import type { PropsPartilhadas } from '../../clinico/tipos';
import { PesquisaUtente } from '../clinico/pesquisa-utente';

/** Pesquisa de utente para o inscrever em lista de espera; só para quem pode criar agendamentos. */
export function NovaInscricao() {
    const { permissoes } = usePage<PropsPartilhadas>().props.clinico;
    if (!permissoes.includes('create agendamento')) return null;

    return (
        <section aria-label="Nova inscrição" className="mb-5 max-w-xl rounded-lg border border-teal-200 bg-teal-50/60 px-4 py-3">
            <p className="mb-2 text-sm font-medium text-slate-900">Nova inscrição em lista de espera</p>
            <PesquisaUtente
                podeCriar={permissoes.includes('create internamento')}
                placeholder="Procurar o utente por nome ou processo"
                aoEscolher={(u) => router.visit(`/agendamento/utentes/${u.id}/inscrever`)}
            />
            <p className="mt-1.5 text-xs text-slate-600">Escolha o utente para o inscrever. Se ainda não existe, crie a ficha primeiro.</p>
        </section>
    );
}
