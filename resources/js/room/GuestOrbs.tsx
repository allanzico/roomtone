import { useSpring } from '@react-spring/three';
import { Html, Trail } from '@react-three/drei';
import { useFrame, useThree } from '@react-three/fiber';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    AdditiveBlending,
    Color,
    DoubleSide,
    Euler,
    MathUtils,
    Quaternion,
    Vector3,
} from 'three';
import type { Group, Mesh, MeshBasicMaterial, Sprite } from 'three';
import { useShallow } from 'zustand/react/shallow';
import { labelPortal } from './labels';
import { live, tempo } from './live';
import { glowTexture } from './palette';
import { seeded } from './random';
import { useRoom } from './store';
import type { Cluster, Guest } from './types';

/** Roughly the Star's on-screen radius, used to hide names that pass behind it. */
const STAR_RADIUS = 1.3;

export function GuestOrbs() {
    const ids = useRoom(useShallow((s) => s.guests.map((g) => g.id)));

    return ids.map((id, index) => <GuestOrb key={id} id={id} index={index} />);
}

function GuestOrb({ id, index }: { id: number; index: number }) {
    const guest = useRoom((s) => s.guests.find((g) => g.id === id));
    const clusters = useRoom((s) => s.clusters);
    const isFor = useRoom((s) => s.nowPlaying?.reasonGuestId === id);

    return guest ? (
        <Orb guest={guest} clusters={clusters} isFor={isFor} index={index} />
    ) : null;
}

type Orbit = {
    center: Vector3;
    radius: number;
    speed: number;
    phase: number;
    tilt: Quaternion;
    bob: number;
    bobSpeed: number;
};

/** Each guest circles the weighted centre of their clusters, on an orbit seeded from their id. */
function makeOrbit(guest: Guest, clusters: Cluster[]): Orbit {
    const rand = seeded(Math.abs(guest.id) * 9973 + 17);
    const center = new Vector3();
    let total = 0;

    for (const { clusterId, weight } of guest.clusterWeights) {
        const cluster = clusters.find((c) => c.id === clusterId);

        if (cluster) {
            center.add(new Vector3(...cluster.position).multiplyScalar(weight));
            total += weight;
        }
    }

    if (total > 0) {
        // Pulled slightly towards the Star so guests with mixed tastes sit between their clusters.
        center.divideScalar(total).multiplyScalar(0.9);
    } else {
        center.set((rand() - 0.5) * 6, 0, (rand() - 0.5) * 6);
    }

    return {
        center,
        radius: 0.9 + rand() * 1.1,
        speed: (0.12 + rand() * 0.14) * (rand() > 0.5 ? 1 : -1),
        phase: rand() * Math.PI * 2,
        tilt: new Quaternion().setFromEuler(
            new Euler((rand() - 0.5) * 1.1, 0, (rand() - 0.5) * 1.1),
        ),
        bob: 0.12 + rand() * 0.25,
        bobSpeed: 0.3 + rand() * 0.4,
    };
}

type OrbProps = {
    guest: Guest;
    clusters: Cluster[];
    isFor: boolean;
    index: number;
};

function Orb({ guest, clusters, isFor, index }: OrbProps) {
    const camera = useThree((s) => s.camera);
    const orbit = useMemo(() => makeOrbit(guest, clusters), [guest, clusters]);
    const arrivedLive = guest.arrivedLive === true;

    const group = useRef<Group>(null);
    const halo = useRef<Sprite>(null);
    const ripple = useRef<Mesh>(null);
    const occluder = useRef<HTMLDivElement>(null);
    const angle = useRef(orbit.phase);
    const focus = useRef(0);
    const rippleAge = useRef(-1);
    const landed = useRef(!arrivedLive);

    const [position] = useState(() => new Vector3());
    const [target] = useState(() => new Vector3());
    const [control] = useState(() => new Vector3());
    const [toOrb] = useState(() => new Vector3());
    const [start] = useState(() => {
        if (!arrivedLive) {
            return new Vector3(); // first load: guests burst outward from the Star
        }

        // Live arrival: fly in from beyond the edge of the screen.
        const rand = seeded(Math.abs(guest.id) * 31 + 7);

        return new Vector3(
            (rand() > 0.5 ? 1 : -1) * (12 + rand() * 3),
            6 + rand() * 3,
            10 + rand() * 3,
        );
    });
    const [glow] = useState(() => new Color(guest.color).multiplyScalar(1.7));

    const [{ t }] = useSpring(() => ({
        from: { t: 0 },
        to: { t: 1 },
        delay: arrivedLive ? 0 : 1300 + index * 120,
        config: arrivedLive
            ? { mass: 1.6, tension: 55, friction: 16 }
            : { mass: 1, tension: 34, friction: 13 },
    }));

    useEffect(() => {
        live.orbPositions.set(guest.id, position);

        return () => {
            live.orbPositions.delete(guest.id);
        };
    }, [guest.id, position]);

    useFrame((state, rawDelta) => {
        const delta = Math.min(rawDelta, 0.1);
        const time = state.clock.elapsedTime;

        angle.current += delta * orbit.speed * tempo();
        const a = angle.current;

        target
            .set(
                Math.cos(a) * orbit.radius,
                Math.sin(time * orbit.bobSpeed + orbit.phase) * orbit.bob,
                Math.sin(a) * orbit.radius,
            )
            .applyQuaternion(orbit.tilt)
            .add(orbit.center);

        // Travel along an arc from the start point to the (moving) orbit. The spring may overshoot a little.
        const p = t.get();
        const q = 1 - p;
        control.lerpVectors(start, target, 0.5).y += arrivedLive ? 3.5 : 1.2;
        position
            .copy(start)
            .multiplyScalar(q * q)
            .addScaledVector(control, 2 * q * p)
            .addScaledVector(target, p * p);

        group.current?.position.copy(position);

        // Hide the name while the orb passes behind the Star (Html labels aren't depth-tested).
        if (occluder.current) {
            toOrb.subVectors(position, camera.position);
            const along = -camera.position.dot(toOrb) / toOrb.length();
            const behind =
                toOrb.length() > camera.position.length() &&
                Math.sqrt(
                    Math.max(0, camera.position.lengthSq() - along * along),
                ) < STAR_RADIUS;
            occluder.current.style.opacity = behind ? '0' : '1';
        }

        // Live arrivals land with a ripple.
        if (!landed.current && p > 0.96) {
            landed.current = true;
            rippleAge.current = 0;
        }

        const ring = ripple.current;

        if (ring && rippleAge.current >= 0) {
            rippleAge.current += delta;
            const k = rippleAge.current / 1.6;
            ring.visible = k < 1;
            ring.position.copy(position);
            ring.quaternion.copy(camera.quaternion);
            ring.scale.setScalar(0.3 + k * 4.5);
            (ring.material as MeshBasicMaterial).opacity =
                (1 - k) * (1 - k) * 0.9;

            if (k >= 1) {
                rippleAge.current = -1;
            }
        }

        focus.current = MathUtils.damp(
            focus.current,
            isFor ? 1 : 0,
            2.5,
            delta,
        );
        const level = useRoom.getState().audioLevel;
        const appear = MathUtils.clamp(p * 1.3, 0, 1);

        group.current?.scale.setScalar(
            appear * (1 + focus.current * (0.55 + level * 0.15)),
        );

        if (halo.current) {
            halo.current.material.opacity =
                0.32 + focus.current * 0.4 + level * 0.06;
            halo.current.scale.setScalar(1 + focus.current * 1.1);
        }
    });

    return (
        <>
            <group ref={group} scale={0}>
                <Trail
                    width={0.38}
                    length={4}
                    decay={1.4}
                    color={guest.color}
                    attenuation={(w) => w * w}
                >
                    <mesh>
                        <sphereGeometry args={[0.14, 24, 24]} />
                        <meshBasicMaterial color={glow} />
                    </mesh>
                </Trail>
                <mesh>
                    <sphereGeometry args={[0.065, 16, 16]} />
                    <meshBasicMaterial color="#FFFFFF" />
                </mesh>
                <sprite ref={halo} scale={1}>
                    <spriteMaterial
                        map={glowTexture()}
                        color={guest.color}
                        blending={AdditiveBlending}
                        depthWrite={false}
                        transparent
                        opacity={0}
                    />
                </sprite>
                <Html
                    portal={labelPortal}
                    center
                    position={[0, -0.42, 0]}
                    distanceFactor={10}
                    zIndexRange={[20, 10]}
                    pointerEvents="none"
                >
                    <div ref={occluder} className="rt-occluder">
                        <div
                            className="rt-orb-label"
                            data-focus={isFor}
                            style={{
                                animationDelay: arrivedLive
                                    ? '0.9s'
                                    : `${1.9 + index * 0.12}s`,
                            }}
                        >
                            {guest.name}
                        </div>
                    </div>
                </Html>
            </group>
            <mesh ref={ripple} visible={false}>
                <ringGeometry args={[0.9, 1, 64]} />
                <meshBasicMaterial
                    color={glow}
                    transparent
                    opacity={0}
                    blending={AdditiveBlending}
                    depthWrite={false}
                    side={DoubleSide}
                />
            </mesh>
        </>
    );
}
