/** Marca de autoria, no fundo da barra lateral e dos ecrãs de autenticação. */
export function DireitosDeAutor({ className = '' }: { className?: string }) {
    return <p className={`text-muted-foreground text-xs ${className}`}>© {new Date().getFullYear()} Surgtuga</p>;
}
