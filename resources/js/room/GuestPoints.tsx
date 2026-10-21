import { Html } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CircleGeometry, MathUtils, Vector3 } from 'three';
import type { Group, Mesh, MeshBasicMaterial, Sprite } from 'three';
import { EMBER_ID, membersByCluster, shortName } from './genres';
import { labelPortal } from './labels';
import { live } from './live';
import { glowBlending, glowTexture } from './palette';
import { seeded } from './random';
import { makeRippleMaterial, rippleQuad } from './ripple';
import { PX_PER_UNIT, reducedMotion } from './stage';
import type { Stage } from './stage';
import { useRoom } from './store';
import type { Guest } from './types';

const disc = new CircleGeometry(1, 32);
const GOLDEN_ANGLE = Math.PI * (3 - Math.sqrt(5));
const DROP_SECONDS = 0.9;
const LANDING_SECONDS = 1.6;

export function GuestPoints() {
    const guests = useRoom((s) => s.guests);
    const stage = useRoom((s) => s.stage);
    const focusId = useRoom((s) => s.nowPlaying?.reasonGuestId ?? null);
    const members = useMemo(() => membersByCluster(guests), [guests]);

    // Names in the pool: everyone in a small room; past 12 guests only the focus guest and the
    // last three to join. The phone pool is too small for more than the focus guest.
    const named = useMemo(() => {
        if (stage.layout === 'phone') {
            return new Set(focusId === null ? [] : [focusId]);
        }

        return guests.length > 12
            ? new Set([
                  ...(focusId === null ? [] : [focusId]),
                  ...guests.slice(-3).map((g) => g.id),
              ])
            : null;
    }, [guests, focusId, stage.layout]);

    return [...members.entries()].flatMap(([clusterId, list]) =>
        list.map((guest, index) => (
            <GuestPoint
                key={guest.id}
                guest={guest}
                islandId={clusterId ?? EMBER_ID}
                index={index}
                count={list.length}
                focus={guest.id === focusId}
                showName={named === null || named.has(guest.id)}
                stage={stage}
            />
        )),
    );
}

type GuestPointProps = {
    guest: Guest;
    islandId: number;
    index: number;
    count: number;
    focus: boolean;
    showName: boolean;
    stage: Stage;
};

function GuestPoint({
    guest,
    islandId,
    index,
    count,
    focus,
    showName,
    stage,
}: GuestPointProps) {
    const group = useRef<Group>(null);
    const dot = useRef<Mesh>(null);
    const halo = useRef<Sprite>(null);
    const landing = useRef<Mesh>(null);
    const [position] = useState(() => new Vector3());
    const [target] = useState(() => new Vector3());
    const placed = useRef(false);
    const focusLevel = useRef(focus ? 1 : 0);
    const arrival = useRef(guest.arrivedLive && !reducedMotion ? 0 : 1);
    const landedAt = useRef(-1);
    const ripple = useMemo(() => {
        const material = makeRippleMaterial();
        material.uniforms.uSpawns.value = 1;
        material.uniforms.uInterval.value = 100;
        material.uniforms.uLife.value = LANDING_SECONDS;

        return material;
    }, []);
    const spin = useMemo(
        () => seeded(islandId * 131 + 5)() * Math.PI * 2,
        [islandId],
    );

    useEffect(() => {
        live.points.set(guest.id, position);

        return () => {
            live.points.delete(guest.id);
            ripple.dispose();
        };
    }, [guest.id, position, ripple]);

    useFrame((state, rawDelta) => {
        const delta = Math.min(rawDelta, 0.1);
        const time = state.clock.elapsedTime;
        const island = live.islands.get(islandId);

        if (!island || !group.current) {
            return;
        }

        const { point } = stage;

        if (islandId === EMBER_ID) {
            // Not clustered yet: drift slowly around the ember.
            const a =
                spin + index * GOLDEN_ANGLE + (reducedMotion ? 0 : time * 0.18);
            const d = island.r * (2 + (index % 3) * 0.6);
            target.set(
                island.x + Math.cos(a) * d,
                island.y + Math.sin(a) * d * 0.7,
                0.2,
            );
        } else {
            // Spread members over the island on a sunflower spiral, kept clear of the label at the top.
            const spread =
                count === 1 ? 0 : 0.6 * Math.sqrt((index + 0.5) / count);
            const a = spin + index * GOLDEN_ANGLE;
            target.set(
                island.x + Math.cos(a) * spread * island.r,
                island.y +
                    Math.sin(a) * spread * island.r * 0.82 -
                    island.r * 0.1,
                island.r * 0.6,
            );
        }

        if (!placed.current || reducedMotion) {
            position.copy(target);
            placed.current = true;
        } else {
            position.x = MathUtils.damp(position.x, target.x, 4, delta);
            position.y = MathUtils.damp(position.y, target.y, 4, delta);
            position.z = target.z;
        }

        // A live arrival drops in from above its island, then lands with one ripple.
        let opacity = 1;
        let lift = 0;

        if (arrival.current < 1) {
            arrival.current = Math.min(
                1,
                arrival.current + delta / DROP_SECONDS,
            );
            const eased = 1 - (1 - arrival.current) ** 3;
            lift = (1 - eased) * (stage.drop / PX_PER_UNIT);
            opacity = Math.min(1, arrival.current * 2);

            if (arrival.current >= 1) {
                landedAt.current = time;
            }
        }

        group.current.position.set(position.x, position.y + lift, position.z);

        focusLevel.current = reducedMotion
            ? Number(focus)
            : MathUtils.damp(focusLevel.current, focus ? 1 : 0, 5, delta);
        const f = focusLevel.current;
        const dotR = MathUtils.lerp(point.dot, point.focusDot, f) / PX_PER_UNIT;
        const haloR =
            MathUtils.lerp(point.halo, point.focusHalo, f) / PX_PER_UNIT;

        dot.current?.scale.setScalar(dotR);
        (dot.current?.material as MeshBasicMaterial | undefined)?.setValues({
            opacity,
        });

        if (halo.current) {
            // The glow texture fades out well before its edge; 2.6× lands the visible halo on haloR.
            halo.current.scale.setScalar(haloR * 2.6);
            halo.current.material.opacity =
                MathUtils.lerp(0.45, 0.75, f) * opacity;
        }

        if (landing.current) {
            const age = landedAt.current < 0 ? -1 : time - landedAt.current;
            landing.current.visible = age >= 0 && age < LANDING_SECONDS;

            if (landing.current.visible) {
                const to = (stage.ripple.to * 0.35) / PX_PER_UNIT;
                ripple.uniforms.uTime.value = age;
                ripple.uniforms.uFrom.value = dotR;
                ripple.uniforms.uTo.value = to;
                ripple.uniforms.uStrokeFrom.value = 2 / PX_PER_UNIT;
                ripple.uniforms.uStrokeTo.value = 1 / PX_PER_UNIT;
                ripple.uniforms.uSize.value = to * 1.05;
                landing.current.scale.setScalar(to * 1.05);
            }
        }
    });

    // Names sit just under the dot; Html is anchored on the point, CSS handles the offset.
    const nameOffset =
        (focus ? stage.point.focusDot + 8 : stage.point.dot + 5) * stage.scale;

    return (
        <group ref={group}>
            <mesh
                ref={landing}
                geometry={rippleQuad}
                material={ripple}
                renderOrder={1}
                visible={false}
            />
            <sprite ref={halo} renderOrder={2}>
                <spriteMaterial
                    map={glowTexture()}
                    {...glowBlending}
                    color="#FFFFFF"
                    depthTest={false}
                    depthWrite={false}
                    transparent
                    opacity={0}
                />
            </sprite>
            <mesh ref={dot} geometry={disc} renderOrder={3}>
                <meshBasicMaterial
                    color="#FFFFFF"
                    depthTest={false}
                    depthWrite={false}
                    transparent
                />
            </mesh>
            {showName && (
                <Html
                    portal={labelPortal}
                    zIndexRange={[20, 11]}
                    pointerEvents="none"
                >
                    <div
                        className="rt-point-name"
                        data-focus={focus}
                        data-arriving={guest.arrivedLive === true}
                        style={{
                            transform: `translate(-50%, ${nameOffset}px)`,
                        }}
                    >
                        {shortName(guest.name)}
                    </div>
                </Html>
            )}
        </group>
    );
}
