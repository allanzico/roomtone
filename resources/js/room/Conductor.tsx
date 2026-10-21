import { useFrame } from '@react-three/fiber';
import { useRef } from 'react';
import { MathUtils } from 'three';
import { live } from './live';
import { reducedMotion } from './stage';
import { useRoom } from './store';

/**
 * Runs first every frame: eases energy towards the host's target and drives the
 * "breathing" audio level the islands and bloom follow.
 */
export function Conductor() {
    const phase = useRef(0);

    useFrame((_, rawDelta) => {
        const delta = Math.min(rawDelta, 0.1);
        const { energy, setAudioLevel } = useRoom.getState();

        live.energy = MathUtils.damp(live.energy, energy, 1.2, delta);
        live.heat = (live.energy - 1) / 4;

        if (reducedMotion) {
            return;
        }

        // A slow breath: ~0.2Hz when calm, ~0.55Hz at peak. Integrated so tempo changes never jump.
        phase.current += delta * Math.PI * 2 * (0.2 + live.heat * 0.35);
        setAudioLevel(0.5 + 0.5 * Math.sin(phase.current));
    });

    return null;
}
