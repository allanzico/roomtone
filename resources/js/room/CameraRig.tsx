import { useFrame, useThree } from '@react-three/fiber';
import { useRef } from 'react';
import { MathUtils } from 'three';
import type { PerspectiveCamera } from 'three';
import { live } from './live';
import { PX_PER_UNIT, reducedMotion } from './stage';
import { useRoom } from './store';

/**
 * Frames the pool so 1 world unit = 100 layout px at the island plane, then drifts very
 * slowly around it. The drift is small enough that the pool never leaves its band.
 */
export function CameraRig() {
    const camera = useThree((s) => s.camera) as PerspectiveCamera;
    const size = useThree((s) => s.size);
    const phase = useRef(0);

    useFrame((_, rawDelta) => {
        const delta = Math.min(rawDelta, 0.1);
        const { scale } = useRoom.getState().stage;

        phase.current += delta * 0.05 * (0.7 + live.heat * 0.8);

        const visibleHeight = size.height / (PX_PER_UNIT * scale);
        const distance =
            visibleHeight / (2 * Math.tan(MathUtils.degToRad(camera.fov / 2)));
        const yaw = reducedMotion ? 0 : Math.sin(phase.current) * 0.05;
        const pitch = reducedMotion ? 0 : Math.sin(phase.current * 0.7) * 0.03;

        camera.position.set(
            Math.sin(yaw) * Math.cos(pitch) * distance,
            Math.sin(pitch) * distance,
            Math.cos(yaw) * Math.cos(pitch) * distance,
        );
        camera.lookAt(0, 0, 0);
    });

    return null;
}
