import { useFrame, useThree } from '@react-three/fiber';
import { useRef } from 'react';
import { Color, MathUtils } from 'three';
import type { Fog } from 'three';
import { live } from './live';
import { inkColor } from './palette';
import { useRoom } from './store';

/**
 * Runs first every frame: eases energy towards the host's target, drives the
 * "breathing" audio level, and tints the background and fog.
 */
export function Conductor() {
    const scene = useThree((s) => s.scene);
    const phase = useRef(0);
    const ink = useRef(new Color());

    useFrame((_, rawDelta) => {
        const delta = Math.min(rawDelta, 0.1);
        const { energy, setAudioLevel } = useRoom.getState();

        live.energy = MathUtils.damp(live.energy, energy, 1.2, delta);
        live.heat = (live.energy - 1) / 4;

        // A slow breath: ~0.2Hz when calm, ~0.55Hz at peak. Integrated so tempo changes never jump.
        phase.current += delta * Math.PI * 2 * (0.2 + live.heat * 0.35);
        setAudioLevel(0.5 + 0.5 * Math.sin(phase.current));

        inkColor(live.heat, ink.current);

        if (scene.background instanceof Color) {
            scene.background.copy(ink.current);
        }

        (scene.fog as Fog | null)?.color.copy(ink.current);
    });

    return null;
}
