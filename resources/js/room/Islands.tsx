import { useSpring } from '@react-spring/three';
import { Html, MarchingCubes } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useEffect, useMemo, useRef } from 'react';
import type { ComponentRef } from 'react';
import { Color, ShaderMaterial } from 'three';
import type { Group, Mesh } from 'three';
import {
    EMBER_ID,
    emberLava,
    layoutIslands,
    lavaFor,
    membersByCluster,
} from './genres';
import type { Lava, Spot } from './genres';
import { hazePortal, labelPortal } from './labels';
import { live, tempo } from './live';
import { seeded } from './random';
import { makeRippleMaterial, rippleQuad } from './ripple';
import { PX_PER_UNIT, reducedMotion, toWorld } from './stage';
import type { Stage } from './stage';
import { useRoom } from './store';

/** Field resolution of each island. 28 keeps six islands well inside a frame on a laptop. */
const RESOLUTION = 28;
/** The lava is a flattened pebble, tipped back a little so it reads as liquid with depth. */
const FLATTEN = 0.5;
const TILT = -0.42;
/** three's MarchingCubes surface sits where the field reaches 80. */
const ISOLATION = 80;
const SUBTRACT = 12;
/** Body radius in the field's 0–1 space. The mesh is scaled so this lands on the island radius. */
const BODY = 0.25;

/** Strength for a lone ball of radius `d` (0–1 field space). */
function strengthFor(d: number): number {
    return (ISOLATION + SUBTRACT) * d * d;
}

export function Islands() {
    const clusters = useRoom((s) => s.clusters);
    const guests = useRoom((s) => s.guests);
    const stage = useRoom((s) => s.stage);
    const members = useMemo(() => membersByCluster(guests), [guests]);
    // The ember holds the room before anyone is clustered: empty room, or someone waiting for the agent.
    const withEmber = guests.length === 0 || members.has(null);
    const spots = useMemo(
        () => layoutIslands(clusters, members, stage, withEmber),
        [clusters, members, stage, withEmber],
    );

    return spots.map((spot) => {
        const index = clusters.findIndex((c) => c.id === spot.id);
        const cluster = index < 0 ? null : clusters[index];

        return (
            <Blob
                key={spot.id}
                id={spot.id}
                spot={spot}
                stage={stage}
                lava={cluster ? lavaFor(cluster, index) : emberLava()}
                label={cluster?.label ?? null}
            />
        );
    });
}

type Satellite = {
    angle: number;
    speed: number;
    distance: number;
    size: number;
};

function makeSatellites(id: number): Satellite[] {
    const rand = seeded(id * 7919 + 3);
    const count = 2 + Math.floor(rand() * 2);

    return Array.from({ length: count }, (_, i) => ({
        angle: (i / count) * Math.PI * 2 + rand() * 1.4,
        // One orbit every 20–40 seconds, either way round.
        speed: ((Math.PI * 2) / (20 + rand() * 20)) * (rand() > 0.5 ? 1 : -1),
        distance: 0.17 + rand() * 0.05,
        size: 0.1 + rand() * 0.03,
    }));
}

function makeLavaMaterial(lava: Lava): ShaderMaterial {
    return new ShaderMaterial({
        uniforms: {
            uLight: { value: new Color(lava.light) },
            uBase: { value: new Color(lava.base) },
            uDark: { value: new Color(lava.dark) },
        },
        vertexShader: /* glsl */ `
            varying vec3 vNormal;
            varying vec3 vView;

            void main() {
                vec4 mv = modelViewMatrix * vec4(position, 1.0);
                vView = -mv.xyz;
                vNormal = normalize(normalMatrix * normal);
                gl_Position = projectionMatrix * mv;
            }
        `,
        fragmentShader: /* glsl */ `
            uniform vec3 uLight;
            uniform vec3 uBase;
            uniform vec3 uDark;
            varying vec3 vNormal;
            varying vec3 vView;

            void main() {
                vec3 n = normalize(vNormal);
                vec3 v = normalize(vView);
                // Key light from the top left, like the reference.
                vec3 l = normalize(vec3(-0.55, 0.6, 0.6));
                float lambert = dot(n, l) * 0.5 + 0.5;

                vec3 color = mix(uDark, uBase, smoothstep(0.12, 0.62, lambert));
                color = mix(color, uLight, smoothstep(0.66, 1.0, lambert) * 0.85);

                // Lava rim: the edge glows in the base colour rather than going dark.
                float rim = pow(1.0 - max(dot(n, v), 0.0), 2.5);
                color = mix(color, uBase, rim * 0.45);

                // Wet highlight.
                vec3 h = normalize(l + v);
                float spec = pow(max(dot(n, h), 0.0), 70.0) * 0.28
                    + pow(max(dot(n, h), 0.0), 12.0) * 0.06;

                gl_FragColor = vec4(color + spec, 1.0);
                #include <colorspace_fragment>
            }
        `,
    });
}

type BlobProps = {
    id: number;
    spot: Spot;
    stage: Stage;
    lava: Lava;
    label: string | null;
};

function Blob({ id, spot, stage, lava, label }: BlobProps) {
    const isEmber = id === EMBER_ID;
    const group = useRef<Group>(null);
    const cubes = useRef<ComponentRef<typeof MarchingCubes>>(null);
    const haze = useRef<HTMLDivElement>(null);
    const hazeSize = useRef(0);
    const labelAnchor = useRef<Group>(null);
    const labelText = useRef<HTMLDivElement>(null);
    const ringA = useRef<Mesh>(null);
    const ringB = useRef<Mesh>(null);
    const satellites = useMemo(
        () => (isEmber ? [] : makeSatellites(id)),
        [id, isEmber],
    );
    const material = useMemo(() => makeLavaMaterial(lava), [lava]);
    const rings = useMemo(
        () => (isEmber ? [makeRippleMaterial(), makeRippleMaterial()] : []),
        [isEmber],
    );
    const fontSize = useRef(0);

    const [spring, api] = useSpring(() => ({
        x: spot.x,
        y: spot.y,
        r: reducedMotion ? spot.r : 0,
        config: { tension: 120, friction: 18 },
    }));

    useEffect(() => {
        void api.start({
            x: spot.x,
            y: spot.y,
            r: spot.r,
            immediate: reducedMotion,
        });
    }, [api, spot.x, spot.y, spot.r]);

    useEffect(
        () => () => {
            live.islands.delete(id);
            material.dispose();
            rings.forEach((ring) => ring.dispose());
        },
        [id, material, rings],
    );

    useFrame((state) => {
        const [x, y] = toWorld(stage, spring.x.get(), spring.y.get());
        const r = Math.max(0, spring.r.get()) / PX_PER_UNIT;
        const time = state.clock.elapsedTime;
        const level = useRoom.getState().audioLevel;
        const breathe = reducedMotion ? 1 : 1 + 0.03 * (level * 2 - 1);

        live.islands.set(id, { x, y, r });
        group.current?.position.set(x, y, 0);

        const mc = cubes.current;

        if (mc) {
            const s = (r / (BODY * 2)) * breathe;
            mc.visible = r > 0.004;
            mc.scale.set(s, s, s * FLATTEN);
            mc.addBall(0.5, 0.5, 0.5, strengthFor(BODY), SUBTRACT);

            for (const sat of satellites) {
                const a =
                    sat.angle +
                    (reducedMotion ? 0 : time * sat.speed * tempo());
                mc.addBall(
                    0.5 + Math.cos(a) * sat.distance,
                    0.5 + Math.sin(a) * sat.distance,
                    0.5,
                    strengthFor(sat.size),
                    SUBTRACT,
                );
            }
        }

        if (haze.current) {
            const px =
                r * PX_PER_UNIT * stage.scale * (isEmber ? 4 : 2.9) * breathe;

            if (Math.abs(px - hazeSize.current) > 0.5) {
                hazeSize.current = px;
                haze.current.style.width = `${px}px`;
                haze.current.style.height = `${px}px`;
            }
        }

        // Label: centred near the top, growing with the island (TV 26–36px).
        if (labelAnchor.current && labelText.current) {
            labelAnchor.current.position.set(0, r * 0.56, r * FLATTEN);
            const px = r * PX_PER_UNIT;
            const size =
                stage.layout === 'tv'
                    ? Math.min(36, Math.max(26, 26 + ((px - 100) / 110) * 10))
                    : Math.min(14, Math.max(11, 11 + ((px - 40) / 40) * 3));

            if (Math.abs(size - fontSize.current) > 0.1) {
                fontSize.current = size;
                labelText.current.style.fontSize = `${size * stage.scale}px`;
            }
        }

        rings.forEach((ring, i) => {
            ring.uniforms.uStill.value = r * (i === 0 ? 2.2 : 3.6);
            ring.uniforms.uStrokeFrom.value = 1.5 / PX_PER_UNIT;
            ring.uniforms.uSize.value = r * 3.8;
            ring.uniforms.uOpacity.value = i === 0 ? 0.32 : 0.18;
        });
        ringA.current?.scale.setScalar(r * 3.8);
        ringB.current?.scale.setScalar(r * 3.8);
    });

    return (
        <group ref={group}>
            <Html
                portal={hazePortal}
                center
                zIndexRange={[0, 0]}
                pointerEvents="none"
            >
                <div
                    ref={haze}
                    className="rt-haze"
                    style={{
                        backgroundColor: lava.base,
                        opacity: isEmber ? 0.75 : 0.6,
                    }}
                />
            </Html>
            <MarchingCubes
                ref={cubes}
                resolution={RESOLUTION}
                maxPolyCount={12000}
                rotation={[TILT, 0, 0]}
                frustumCulled={false}
                visible={false}
            >
                <primitive object={material} attach="material" />
            </MarchingCubes>
            {rings.map((ring, i) => (
                <mesh
                    key={i}
                    ref={i === 0 ? ringA : ringB}
                    geometry={rippleQuad}
                    material={ring}
                    renderOrder={1}
                />
            ))}
            {label && (
                <group ref={labelAnchor}>
                    <Html
                        portal={labelPortal}
                        center
                        zIndexRange={[10, 0]}
                        pointerEvents="none"
                    >
                        <div
                            ref={labelText}
                            className="rt-island-label"
                            style={{ color: lava.ink }}
                        >
                            {label}
                        </div>
                    </Html>
                </group>
            )}
        </group>
    );
}
