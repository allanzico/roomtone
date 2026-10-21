import { useFrame } from '@react-three/fiber';
import { useEffect, useMemo, useRef } from 'react';
import type { Mesh } from 'three';
import { live } from './live';
import { makeRippleMaterial, rippleQuad } from './ripple';
import { PX_PER_UNIT, reducedMotion } from './stage';
import { useRoom } from './store';

const FADE_SECONDS = 0.4;

/**
 * "Why this song": rings ripple out from the guest the playing track is for. On a new
 * track the old rings fade out (400ms) while fresh ones start on the new guest.
 */
export function Ripples() {
    const stage = useRoom((s) => s.stage);
    const current = useRef<Mesh>(null);
    const previous = useRef<Mesh>(null);
    const [now, before] = useMemo(
        () => [makeRippleMaterial(), makeRippleMaterial()],
        [],
    );
    const focus = useRef<{
        id: number | null;
        start: number;
        oldId: number | null;
        oldStart: number;
        fadeStart: number;
    }>({ id: null, start: -1, oldId: null, oldStart: 0, fadeStart: -1 });

    useEffect(
        () => () => {
            now.dispose();
            before.dispose();
        },
        [now, before],
    );

    useFrame((state) => {
        const time = state.clock.elapsedTime;
        const f = focus.current;
        const id = useRoom.getState().nowPlaying?.reasonGuestId ?? null;

        if (f.start < 0 || id !== f.id) {
            if (f.start >= 0) {
                f.oldId = f.id;
                f.oldStart = f.start;
                f.fadeStart = time;
            }

            f.id = id;
            f.start = time;
        }

        const { ripple } = stage;
        const size = (ripple.to + 4) / PX_PER_UNIT;

        for (const material of [now, before]) {
            material.uniforms.uFrom.value = ripple.from / PX_PER_UNIT;
            material.uniforms.uTo.value = ripple.to / PX_PER_UNIT;
            material.uniforms.uStrokeFrom.value =
                (stage.layout === 'tv' ? 3 : 1.6) / PX_PER_UNIT;
            material.uniforms.uStrokeTo.value =
                (stage.layout === 'tv' ? 1 : 0.8) / PX_PER_UNIT;
            material.uniforms.uSize.value = size;
            material.uniforms.uStill.value = reducedMotion
                ? ripple.still / PX_PER_UNIT
                : 0;
        }

        place(current.current, f.id, size);
        now.uniforms.uTime.value = time - f.start;

        const fade = f.fadeStart < 0 ? 1 : (time - f.fadeStart) / FADE_SECONDS;
        const showOld =
            fade < 1 &&
            !reducedMotion &&
            place(previous.current, f.oldId, size);

        if (previous.current) {
            previous.current.visible = showOld;
        }

        before.uniforms.uTime.value = time - f.oldStart;
        before.uniforms.uOpacity.value = 1 - fade;
    });

    return (
        <>
            <mesh
                ref={current}
                geometry={rippleQuad}
                material={now}
                renderOrder={1}
            />
            <mesh
                ref={previous}
                geometry={rippleQuad}
                material={before}
                renderOrder={1}
            />
        </>
    );
}

/** Centre a ring quad on a guest's point. Returns false (and hides it) if they have none. */
function place(
    mesh: Mesh | null,
    guestId: number | null,
    size: number,
): boolean {
    const point = guestId === null ? undefined : live.points.get(guestId);

    if (!mesh) {
        return false;
    }

    mesh.visible = point !== undefined;

    if (point) {
        mesh.position.copy(point);
        mesh.scale.setScalar(size);
    }

    return mesh.visible;
}
