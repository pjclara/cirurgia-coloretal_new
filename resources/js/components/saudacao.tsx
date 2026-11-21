import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { PropsPartilhadas } from '../clinico/tipos';

/** Bom dia (até às 12h), boa tarde (até às 20h) e boa noite. */
const cumprimento = (data: Date) => {
    const hora = data.getHours();
    if (hora >= 5 && hora < 12) return 'Bom dia';
    if (hora >= 12 && hora < 20) return 'Boa tarde';
    return 'Boa noite';
};

/** Primeiro nome, sem títulos como «Dr.» ou «Dra.». */
const primeiroNome = (nome: string) => nome.split(/\s+/).find((parte) => parte && !parte.endsWith('.')) ?? nome;

/** Cumprimenta o utilizador no canto superior, de acordo com a hora do dia. */
export function Saudacao({ className = '' }: { className?: string }) {
    const utilizador = usePage<PropsPartilhadas>().props.clinico?.utilizador;
    const [agora, setAgora] = useState(() => new Date());

    // Atualiza se a página ficar aberta pela mudança de período do dia.
    useEffect(() => {
        const temporizador = setInterval(() => setAgora(new Date()), 60_000);
        return () => clearInterval(temporizador);
    }, []);

    if (!utilizador) return null;

    return (
        <p className={`text-muted-foreground truncate text-sm ${className}`}>
            {cumprimento(agora)}, <span className="text-foreground font-medium">{primeiroNome(utilizador.nome)}</span>
        </p>
    );
}
