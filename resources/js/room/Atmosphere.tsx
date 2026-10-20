import { Stars } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useRef, useState } from 'react';
import { AdditiveBlending, Color } from 'three';
import type { Sprite } from 'three';
import { live } from './live';
import { energyColor, glowTexture } from './palette';

/** Distant stars and a huge, faint glow behind the room that carries the energy colour. */
export function Atmosphere() {
    const glow = useRef<Sprite>(null);
    const [color] = useState(() => new Color());

    useFrame(() => {
        if (glow.current) {
            glow.current.material.color.copy(energyColor(live.heat, color));
            glow.current.material.opacity = 0.05 + live.heat * 0.08;
        }
    });

    return (
        <>
            <Stars
                radius={70}
                depth={45}
                count={3500}
                factor={3.2}
                saturation={0}
                fade
                speed={0.35}
            />
            <sprite ref={glow} position={[0, 1, -16]} scale={40}>
                <spriteMaterial
                    map={glowTexture()}
                    blending={AdditiveBlending}
                    depthWrite={false}
                    transparent
                    fog={false}
                />
            </sprite>
        </>
    );
}
