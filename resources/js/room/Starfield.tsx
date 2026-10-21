import { useMemo } from 'react';
import { seeded } from './random';

/** Static star field behind the canvas. SVG is cheaper than in-scene stars and never moves. */
export function Starfield() {
    const stars = useMemo(() => {
        const rand = seeded(2026);

        // Rounded so the server-rendered page and the browser agree to the digit (`**` can differ in the last
        // place between engines, which breaks hydration).
        const round = (n: number) => Math.round(n * 1000) / 1000;

        return Array.from({ length: 240 }, () => ({
            x: round(rand() * 1920),
            y: round(rand() * 1080),
            r: round(0.4 + rand() ** 3 * 1.3),
            o: round(0.18 + rand() * 0.5),
        }));
    }, []);

    return (
        <svg
            className="rt-stars"
            viewBox="0 0 1920 1080"
            preserveAspectRatio="xMidYMid slice"
            aria-hidden="true"
        >
            {stars.map((s, i) => (
                <circle
                    key={i}
                    cx={s.x}
                    cy={s.y}
                    r={s.r}
                    fill="#EDEDF2"
                    opacity={s.o}
                />
            ))}
        </svg>
    );
}
