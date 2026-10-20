import { QuadraticBezierLine } from '@react-three/drei';
import type { QuadraticBezierLineRef } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useEffect, useRef, useState } from 'react';
import { AdditiveBlending, Color, MathUtils, Vector3 } from 'three';
import type { Sprite } from 'three';
import { live } from './live';
import { glowTexture } from './palette';
import { useRoom } from './store';

const RETARGET_SECONDS = 1.2;
/** Wait for the guests to burst out of the Star before drawing the first beam. */
const FIRST_DRAW_DELAY = 2.6;

function easeInOutCubic(x: number): number {
    return x < 0.5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2;
}

/** A glowing line from the Star to the guest the current song is for. */
export function ReasonBeam() {
    const targetId = useRoom((s) => s.nowPlaying?.reasonGuestId ?? null);
    const hex = useRoom(
        (s) =>
            s.guests.find((g) => g.id === s.nowPlaying?.reasonGuestId)?.color ??
            '#FFFFFF',
    );

    const core = useRef<QuadraticBezierLineRef>(null);
    const soft = useRef<QuadraticBezierLineRef>(null);
    const pulse = useRef<Sprite>(null);
    const progress = useRef(-FIRST_DRAW_DELAY / RETARGET_SECONDS);
    const visibility = useRef(0);
    const travel = useRef(0);

    const [from] = useState(() => new Vector3());
    const [end] = useState(() => new Vector3());
    const [start] = useState(() => new Vector3());
    const [mid] = useState(() => new Vector3());
    const [point] = useState(() => new Vector3());
    const [color] = useState(() => new Color(hex));
    const [targetColor] = useState(() => new Color(hex));

    // When the song changes, the beam sweeps from where it was to the new guest.
    useEffect(() => {
        from.copy(end);
        progress.current = Math.min(progress.current, 0);
        targetColor.set(hex).multiplyScalar(1.6);
    }, [targetId, hex, from, end, targetColor]);

    useFrame((_, rawDelta) => {
        const delta = Math.min(rawDelta, 0.1);
        const goal =
            targetId === null ? undefined : live.orbPositions.get(targetId);

        visibility.current = MathUtils.damp(
            visibility.current,
            goal && progress.current > 0 ? 1 : 0,
            3,
            delta,
        );
        progress.current = Math.min(
            1,
            progress.current + delta / RETARGET_SECONDS,
        );

        if (goal) {
            end.lerpVectors(
                from,
                goal,
                easeInOutCubic(MathUtils.clamp(progress.current, 0, 1)),
            );
        }

        // Leave the Star from the side facing the guest, arcing gently upward.
        start.copy(end).normalize().multiplyScalar(1.25);
        mid.lerpVectors(start, end, 0.5);
        mid.y += 0.8 + start.distanceTo(end) * 0.12;

        color.lerp(targetColor, 1 - Math.exp(-delta * 3));

        for (const line of [core.current, soft.current]) {
            if (!line) {
                continue;
            }

            line.setPoints(start, end, mid);
            line.material.color.copy(color);
        }

        if (core.current) {
            core.current.material.dashOffset -= delta * (0.5 + live.heat * 1.3);
            core.current.material.opacity = 0.95 * visibility.current;
        }

        if (soft.current) {
            soft.current.material.opacity = 0.16 * visibility.current;
        }

        // A spark travels along the beam towards the guest.
        travel.current =
            (travel.current + delta * (0.35 + live.heat * 0.45)) % 1;
        const u = travel.current;
        const v = 1 - u;
        point
            .copy(start)
            .multiplyScalar(v * v)
            .addScaledVector(mid, 2 * v * u)
            .addScaledVector(end, u * u);

        if (pulse.current) {
            pulse.current.position.copy(point);
            pulse.current.material.color.copy(color);
            pulse.current.material.opacity =
                Math.sin(u * Math.PI) * visibility.current;
            pulse.current.scale.setScalar(0.55 + Math.sin(u * Math.PI) * 0.35);
        }
    });

    return (
        <group>
            <QuadraticBezierLine
                ref={soft}
                start={[0, 0, 0]}
                end={[0, 0, 0]}
                color={hex}
                lineWidth={10}
                transparent
                opacity={0}
                depthWrite={false}
                blending={AdditiveBlending}
            />
            <QuadraticBezierLine
                ref={core}
                start={[0, 0, 0]}
                end={[0, 0, 0]}
                color={hex}
                lineWidth={2.2}
                dashed
                dashScale={6}
                dashSize={1}
                gapSize={0.35}
                transparent
                opacity={0}
                depthWrite={false}
            />
            <sprite ref={pulse}>
                <spriteMaterial
                    map={glowTexture()}
                    blending={AdditiveBlending}
                    depthWrite={false}
                    transparent
                    opacity={0}
                />
            </sprite>
        </group>
    );
}
