import { useFrame } from '@react-three/fiber';
import { useMemo, useRef } from 'react';
import {
    AdditiveBlending,
    BufferAttribute,
    BufferGeometry,
    MathUtils,
} from 'three';
import type { LineSegments, LineBasicMaterial } from 'three';
import { tempo } from './live';

const SEGMENTS = 160;

/**
 * A faint record lying under the room: concentric grooves the guests orbit above.
 * Built as one line-segment geometry so it costs a single draw call.
 */
export function Grooves() {
    const lines = useRef<LineSegments>(null);
    const material = useRef<LineBasicMaterial>(null);
    const fade = useRef(0);

    const geometry = useMemo(() => {
        const radii: number[] = [];

        for (let r = 2.3; r < 10.5; r += r < 3 ? 0.18 : 0.34) {
            radii.push(r);
        }

        const positions = new Float32Array(radii.length * SEGMENTS * 6);
        const colors = new Float32Array(radii.length * SEGMENTS * 6);
        let i = 0;

        for (const r of radii) {
            // Brightest around the middle of the record, fading at the label and the rim.
            const strength = Math.sin(((r - 2.3) / 8.2) * Math.PI) * 0.8 + 0.2;

            for (let s = 0; s < SEGMENTS; s++) {
                const a0 = (s / SEGMENTS) * Math.PI * 2;
                const a1 = ((s + 1) / SEGMENTS) * Math.PI * 2;
                positions.set(
                    [
                        Math.cos(a0) * r,
                        0,
                        Math.sin(a0) * r,
                        Math.cos(a1) * r,
                        0,
                        Math.sin(a1) * r,
                    ],
                    i,
                );
                colors.set(
                    [
                        strength,
                        strength,
                        strength * 1.1,
                        strength,
                        strength,
                        strength * 1.1,
                    ],
                    i,
                );
                i += 6;
            }
        }

        const g = new BufferGeometry();
        g.setAttribute('position', new BufferAttribute(positions, 3));
        g.setAttribute('color', new BufferAttribute(colors, 3));

        return g;
    }, []);

    useFrame((_, rawDelta) => {
        const delta = Math.min(rawDelta, 0.1);
        fade.current = MathUtils.damp(fade.current, 1, 0.6, delta);

        if (lines.current) {
            lines.current.rotation.y -= delta * 0.06 * tempo();
        }

        if (material.current) {
            material.current.opacity = 0.11 * fade.current;
        }
    });

    return (
        <group position={[0, -1.9, 0]} rotation={[0.05, 0, 0.03]}>
            <lineSegments ref={lines} geometry={geometry}>
                <lineBasicMaterial
                    ref={material}
                    vertexColors
                    color="#A9B4FF"
                    transparent
                    opacity={0}
                    blending={AdditiveBlending}
                    depthWrite={false}
                />
            </lineSegments>
        </group>
    );
}
