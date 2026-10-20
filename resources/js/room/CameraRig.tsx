import { useFrame, useThree } from '@react-three/fiber';
import { useRef } from 'react';
import { MathUtils } from 'three';
import { live } from './live';

const reducedMotion =
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Slow cinematic drift around the room. Never static, never fast. */
export function CameraRig() {
    const camera = useThree((s) => s.camera);
    const phase = useRef(0);
    const intro = useRef(0);

    useFrame((_, rawDelta) => {
        const delta = Math.min(rawDelta, 0.1);

        phase.current += delta * 0.05 * (0.7 + live.heat * 0.8);
        intro.current = MathUtils.damp(intro.current, 1, 0.9, delta);

        const sway = reducedMotion ? 0.03 : 0.2;
        const yaw = Math.sin(phase.current) * sway;
        // Pull in a little at higher energy; start further out and glide in on load.
        const distance = 15.5 - live.heat * 1.6 + (1 - intro.current) * 9;
        const height =
            3.1 +
            Math.sin(phase.current * 0.7) * (reducedMotion ? 0.05 : 0.45) +
            (1 - intro.current) * 2.5;

        camera.position.set(
            Math.sin(yaw) * distance,
            height,
            Math.cos(yaw) * distance,
        );
        camera.lookAt(0, -0.25, 0);
    });

    return null;
}
