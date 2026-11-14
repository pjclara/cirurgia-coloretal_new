import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { rotas } from '../../clinico/rotas';
import { Botao, Campo, Seccao, estiloEntrada } from '../../components/clinico/campos';
import ClinicoLayout from '../../layouts/clinico-layout';

export default function AlterarPassword() {
    const form = useForm({ password: '', password_confirmation: '' });

    const guardar = (evento: FormEvent) => {
        evento.preventDefault();
        form.put(rotas.password, { onError: () => form.reset() });
    };

    return (
        <ClinicoLayout titulo="Definir uma password nova">
            <form onSubmit={guardar} className="max-w-md space-y-5" noValidate>
                <Seccao
                    titulo="A sua conta ainda usa a password inicial"
                    descricao="Escolha uma password só sua para continuar. Pelo menos 10 caracteres, com letras e números."
                >
                    <div className="space-y-4">
                        <Campo rotulo="Password nova" para="password" erro={form.errors.password}>
                            <input
                                id="password"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                className={estiloEntrada}
                                aria-invalid={!!form.errors.password}
                            />
                        </Campo>
                        <Campo rotulo="Repetir a password nova" para="password_confirmation">
                            <input
                                id="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password_confirmation}
                                onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                className={estiloEntrada}
                            />
                        </Campo>
                    </div>
                </Seccao>

                <Botao type="submit" disabled={form.processing}>
                    Guardar password
                </Botao>
            </form>
        </ClinicoLayout>
    );
}

AlterarPassword.layout = (pagina: ReactNode) => pagina;
