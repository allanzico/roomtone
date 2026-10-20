import { MeshDistortMaterial, Sparkles } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useEffect, useRef, useState } from 'react';
import type { ComponentRef } from 'react';
import { AdditiveBlending, Color, MathUtils, ShaderMaterial } from 'three';
import type { Group, Sprite } from 'three';
import { live } from './live';
import { energyColor, glowTexture } from './palette';
import { trackColor, useRoom } from './store';

const IDLE_COLOR = '#E9E6FF';

/** Fresnel shell: transparent in the middle, glowing at the silhouette, so the Star reads as a lit sphere. */
function makeRimMaterial(): ShaderMaterial {
    return new ShaderMaterial({
        uniforms: {
            uColor: { value: new Color(IDLE_COLOR) },
            uIntensity: { value: 0 },
        },
        vertexShader: /* glsl */ `
            varying vec3 vNormal;
            varying vec3 vView;
            void main() {
                vec4 mv = modelViewMatrix * vec4(position, 1.0);
                vNormal = normalize(normalMatrix * normal);
                vView = normalize(-mv.xyz);
                gl_Position = projectionMatrix * mv;
            }
        `,
        fragmentShader: /* glsl */ `
            uniform vec3 uColor;
            uniform float uIntensity;
            varying vec3 vNormal;
            varying vec3 vView;
            void main() {
                float rim = pow(1.0 - max(dot(vNormal, vView), 0.0), 2.8);
                gl_FragColor = vec4(uColor * rim * uIntensity, rim);
            }
        `,
        transparent: true,
        depthWrite: false,
        blending: AdditiveBlending,
    });
}

/** The current song: a living, breathing sphere at the centre of the room. */
export function Star() {
    const group = useRef<Group>(null);
    const material = useRef<ComponentRef<typeof MeshDistortMaterial>>(null);
    const corona = useRef<Sprite>(null);
    const halo = useRef<Sprite>(null);
    const ignite = useRef(0);

    const hex = useRoom((s) => trackColor(s, s.nowPlaying)) ?? IDLE_COLOR;
    const [target] = useState(() => new Color(hex));
    const [current] = useState(() => new Color(hex));
    const [tint] = useState(() => new Color());
    const [mixed] = useState(() => new Color());
    const [rim] = useState(makeRimMaterial);

    useEffect(() => {
        target.set(hex);
    }, [hex, target]);

    useFrame((_, rawDelta) => {
        const delta = Math.min(rawDelta, 0.1);
        const level = useRoom.getState().audioLevel;

        ignite.current = MathUtils.damp(ignite.current, 1, 1.4, delta);
        current.lerp(target, 1 - Math.exp(-delta * 1.8));
        mixed.copy(current).lerp(energyColor(live.heat, tint), 0.1);

        const m = material.current;

        if (m) {
            // Mostly self-lit: a dimmer surface colour keeps some shading, the emissive carries the glow.
            m.color.copy(mixed).multiplyScalar(0.6);
            m.emissive.copy(mixed);
            m.emissiveIntensity =
                (0.32 + level * 0.28 + live.heat * 0.4) * ignite.current;
            m.distort = 0.12 + live.heat * 0.12 + level * 0.04;
        }

        rim.uniforms.uColor.value.copy(mixed);
        rim.uniforms.uIntensity.value =
            (1.1 + level * 0.5 + live.heat * 0.6) * ignite.current;

        group.current?.scale.setScalar(
            ignite.current * (1 + level * 0.06 + live.heat * 0.1),
        );

        if (corona.current) {
            corona.current.material.color.copy(mixed);
            corona.current.material.opacity =
                (0.32 + level * 0.22) * ignite.current;
            corona.current.scale.setScalar(4.2 + level * 0.8 + live.heat * 1.2);
        }

        if (halo.current) {
            halo.current.material.color.copy(mixed);
            halo.current.material.opacity =
                (0.12 + level * 0.06 + live.heat * 0.08) * ignite.current;
            halo.current.scale.setScalar(13 + live.heat * 5);
        }
    });

    return (
        <group>
            <group ref={group}>
                <mesh>
                    <sphereGeometry args={[1.15, 128, 128]} />
                    <MeshDistortMaterial
                        ref={material}
                        color={IDLE_COLOR}
                        emissive={IDLE_COLOR}
                        roughness={0.4}
                        metalness={0}
                        distort={0.15}
                        speed={1.2}
                    />
                </mesh>
                <mesh scale={1.08} material={rim}>
                    <sphereGeometry args={[1.15, 64, 64]} />
                </mesh>
                <Sparkles
                    count={40}
                    scale={4}
                    size={2.4}
                    speed={0.5}
                    opacity={0.7}
                    color="#FFF4E8"
                    noise={1.5}
                />
            </group>
            <sprite ref={corona}>
                <spriteMaterial
                    map={glowTexture()}
                    blending={AdditiveBlending}
                    depthWrite={false}
                    transparent
                />
            </sprite>
            <sprite ref={halo}>
                <spriteMaterial
                    map={glowTexture()}
                    blending={AdditiveBlending}
                    depthWrite={false}
                    transparent
                />
            </sprite>
        </group>
    );
}
