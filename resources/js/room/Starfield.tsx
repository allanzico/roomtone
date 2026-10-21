import { useMemo } from 'react';
import { seeded } from './random';

/** Static star field behind the canvas. SVG is cheaper than in-scene stars and never moves. */
export function Starfield() {
    const stars = useMemo(() => {
        const rand = seeded(2026);

        return Array.from({ length: 240 }, () => ({
            x: rand() * 1920,
            y: rand() * 1080,
            r: 0.4 + rand() ** 3 * 1.3,
            o: 0.18 + rand() * 0.5,
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
