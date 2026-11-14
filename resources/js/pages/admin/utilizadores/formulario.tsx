import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Botao, BotaoLigacao, Campo, Seccao, estiloEntrada } from '../../../components/clinico/campos';
import ClinicoLayout from '../../../layouts/clinico-layout';

type Utilizador = {
    id: number;
    name: string;
    username: string;
    abrev: string;
    email: string;
    equipa: string;
    activo: boolean;
    roles: string[];
    proprio: boolean;
};

type Props = { utilizador: Utilizador | null; perfis: string[]; perfisReservados: string[]; temMudarPassword: boolean };

export default function FormularioUtilizador({ utilizador, perfis, perfisReservados, temMudarPassword }: Props) {
    const form = useForm({
        name: utilizador?.name ?? '',
        username: utilizador?.username ?? '',
        abrev: utilizador?.abrev ?? '',
        email: utilizador?.email ?? '',
        equipa: utilizador?.equipa ?? '',
        activo: utilizador?.activo ?? true,
        password: '',
        mudar_password: true,
        roles: utilizador?.roles ?? ([] as string[]),
    });
    const { data, setData, processing } = form;
    const erros = form.errors as Record<string, string | undefined>;

    const alternar = (perfil: string) =>
        setData('roles', data.roles.includes(perfil) ? data.roles.filter((r) => r !== perfil) : [...data.roles, perfil]);

    const guardar = (e: FormEvent) => {
        e.preventDefault();
        if (utilizador) form.put(`/admin/utilizadores/${utilizador.id}`, { preserveScroll: true });
        else form.post('/admin/utilizadores', { preserveScroll: true });
    };

    return (
        <ClinicoLayout titulo={utilizador ? 'Editar conta' : 'Nova conta'} subtitulo={utilizador?.email}>
            <form onSubmit={guardar} noValidate className="max-w-3xl space-y-5">
                <Seccao titulo="Dados">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Campo rotulo="Nome" para="name" erro={erros.name} className="sm:col-span-2">
                            <input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} className={estiloEntrada} aria-invalid={!!erros.name} />
                        </Campo>
                        <Campo rotulo="Email" para="email" erro={erros.email}>
                            <input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} className={estiloEntrada} aria-invalid={!!erros.email} />
                        </Campo>
                        <Campo rotulo="Nome de utilizador" para="username" erro={erros.username} opcional>
                            <input id="username" value={data.username} onChange={(e) => setData('username', e.target.value)} className={estiloEntrada} />
                        </Campo>
                        <Campo rotulo="Abreviatura" para="abrev" erro={erros.abrev} opcional>
                            <input id="abrev" value={data.abrev} maxLength={10} onChange={(e) => setData('abrev', e.target.value)} className={estiloEntrada} />
                        </Campo>
                        <Campo rotulo="Equipa" para="equipa" erro={erros.equipa} opcional>
                            <select id="equipa" value={data.equipa} onChange={(e) => setData('equipa', e.target.value)} className={estiloEntrada}>
                                <option value="">Sem equipa</option>
                                <option value="A">Equipa A</option>
                                <option value="B">Equipa B</option>
                            </select>
                        </Campo>
                    </div>
                </Seccao>

                <Seccao titulo="Perfis" descricao="Definem o que a pessoa pode ver e fazer.">
                    <div className="grid gap-2 sm:grid-cols-2">
                        {perfis.map((p) => {
                            const reservado = perfisReservados.includes(p);
                            return (
                                <label key={p} className={`flex items-center gap-2 text-sm ${reservado ? 'text-slate-400' : ''}`}>
                                    <input type="checkbox" checked={data.roles.includes(p)} disabled={reservado} onChange={() => alternar(p)} />
                                    {p}
                                    {reservado && <span className="text-xs">(só superAdmin)</span>}
                                </label>
                            );
                        })}
                    </div>
                    {erros.roles && <p className="mt-2 text-sm text-rose-700">{erros.roles}</p>}
                </Seccao>

                <Seccao titulo="Acesso">
                    <label className="mb-4 flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={data.activo} disabled={utilizador?.proprio} onChange={(e) => setData('activo', e.target.checked)} />
                        Conta ativa
                        {utilizador?.proprio && <span className="text-xs text-slate-500">(não pode desativar a sua própria conta)</span>}
                    </label>
                    <Campo
                        rotulo={utilizador ? 'Nova palavra-passe' : 'Palavra-passe inicial'}
                        para="password"
                        erro={erros.password}
                        ajuda={utilizador ? 'Deixe vazio para manter a atual. Mínimo 8 caracteres.' : 'Mínimo 8 caracteres.'}
                        opcional={!!utilizador}
                    >
                        <input
                            id="password"
                            type="password"
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className={`${estiloEntrada} max-w-sm`}
                            aria-invalid={!!erros.password}
                        />
                    </Campo>
                    {temMudarPassword && (
                        <label className="mt-3 flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.mudar_password} onChange={(e) => setData('mudar_password', e.target.checked)} />
                            Obrigar a mudar a palavra-passe no próximo início de sessão
                        </label>
                    )}
                </Seccao>

                <div className="flex gap-3">
                    <Botao type="submit" disabled={processing}>
                        {utilizador ? 'Guardar alterações' : 'Criar conta'}
                    </Botao>
                    <BotaoLigacao href="/admin/utilizadores" variante="secundario">
                        Cancelar
                    </BotaoLigacao>
                </div>
            </form>
        </ClinicoLayout>
    );
}

FormularioUtilizador.layout = (pagina: React.ReactNode) => pagina;
