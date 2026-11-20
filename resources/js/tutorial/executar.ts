import { driver, type DriveStep } from 'driver.js';
import 'driver.js/dist/driver.css';
import './tutorial.css';

export type PassoTutorial = {
    /** Seletor CSS do elemento a destacar. Sem ele (ou se não existir), o passo aparece ao centro. */
    elemento?: string;
    titulo: string;
    texto: string;
    /** Corre antes de o passo ser mostrado (por exemplo, mudar de separador). */
    preparar?: () => void;
};

const esperar = (ms: number) => new Promise((resolver) => setTimeout(resolver, ms));

/**
 * Corre um tutorial passo a passo. Cada passo pode preparar o ecrã antes de ser mostrado
 * (mudar de separador, abrir uma secção); damos tempo ao React para desenhar o resultado.
 */
export async function executarTutorial(passos: PassoTutorial[]) {
    if (passos.length === 0) return;

    const preparar = async (n: number) => {
        const passo = passos[n];
        if (passo?.preparar) {
            passo.preparar();
            await esperar(120);
        }
    };

    const tour = driver({
        showProgress: true,
        progressText: '{{current}} de {{total}}',
        nextBtnText: 'Seguinte',
        prevBtnText: 'Anterior',
        doneBtnText: 'Concluir',
        allowClose: true,
        overlayOpacity: 0.55,
        stagePadding: 6,
        steps: passos.map(
            (p, n): DriveStep => ({
                element: p.elemento,
                popover: {
                    title: p.titulo,
                    description: p.texto,
                    onNextClick: async () => {
                        await preparar(n + 1);
                        tour.moveNext();
                    },
                    onPrevClick: async () => {
                        await preparar(n - 1);
                        tour.movePrevious();
                    },
                },
            }),
        ),
    });

    await preparar(0);
    tour.drive();
}
