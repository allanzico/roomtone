import { Html, Sparkles } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useMemo, useRef } from 'react';
import { AdditiveBlending, MathUtils } from 'three';
import type { Group, Sprite } from 'three';
import { labelPortal } from './labels';
import { live, tempo } from './live';
import { glowTexture } from './palette';
import { seeded } from './random';
import { useRoom } from './store';
import type { Cluster } from './types';

export function Clusters() {
    const clusters = useRoom((s) => s.clusters);

    return clusters.map((cluster, index) => (
        <TasteCloud key={cluster.id} cluster={cluster} index={index} />
    ));
}

type Puff = {
    position: [number, number, number];
    scale: number;
    opacity: number;
    spin: number;
};

/** A taste cluster: a soft nebula of the cluster's colour with a floating label. */
function TasteCloud({ cluster, index }: { cluster: Cluster; index: number }) {
    const group = useRef<Group>(null);
    const puffs = useRef<(Sprite | null)[]>([]);
    const fade = useRef(0);
    const age = useRef(0);

    const shape = useMemo<Puff[]>(() => {
        const rand = seeded(cluster.id * 7919 + 3);

        return Array.from({ length: 5 }, () => ({
            position: [
                (rand() - 0.5) * 2.4,
                (rand() - 0.5) * 1.2,
                (rand() - 0.5) * 2.4,
            ],
            scale: 2.6 + rand() * 2.4,
            opacity: 0.1 + rand() * 0.1,
            spin: (rand() - 0.5) * 0.3,
        }));
    }, [cluster.id]);

    useFrame((_, rawDelta) => {
        const delta = Math.min(rawDelta, 0.1);
        age.current += delta;

        // Clouds fade in one after another, once the Star has ignited.
        if (age.current > 0.8 + index * 0.25) {
            fade.current = MathUtils.damp(fade.current, 1, 0.9, delta);
        }

        if (group.current) {
            group.current.rotation.y += delta * 0.04 * tempo();
        }

        puffs.current.forEach((sprite, i) => {
            if (!sprite) {
                return;
            }

            sprite.material.rotation += delta * shape[i].spin * tempo();
            sprite.material.opacity =
                shape[i].opacity * fade.current * (0.75 + live.heat * 0.5);
        });
    });

    return (
        <group position={cluster.position}>
            <group ref={group}>
                {shape.map((puff, i) => (
                    <sprite
                        key={i}
                        ref={(el) => {
                            puffs.current[i] = el;
                        }}
                        position={puff.position}
                        scale={puff.scale}
                    >
                        <spriteMaterial
                            map={glowTexture()}
                            color={cluster.color}
                            blending={AdditiveBlending}
                            depthWrite={false}
                            transparent
                            opacity={0}
                        />
                    </sprite>
                ))}
                <Sparkles
                    count={70}
                    scale={[3.6, 2.2, 3.6]}
                    size={3.2}
                    speed={0.3}
                    opacity={0.85}
                    color={cluster.color}
                    noise={0.8}
                />
            </group>
            <Html
                portal={labelPortal}
                center
                position={[0, 1.6, 0]}
                distanceFactor={13}
                zIndexRange={[5, 0]}
                pointerEvents="none"
            >
                <div
                    className="rt-cluster-label"
                    style={{
                        color: cluster.color,
                        animationDelay: `${1.2 + index * 0.25}s`,
                    }}
                >
                    {cluster.label}
                </div>
            </Html>
        </group>
    );
}
