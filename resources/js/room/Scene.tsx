import { AdaptiveDpr, PerformanceMonitor } from '@react-three/drei';
import { Canvas } from '@react-three/fiber';
import { useState } from 'react';
import type { CSSProperties } from 'react';
import { CameraRig } from './CameraRig';
import { Conductor } from './Conductor';
import { Effects } from './Effects';
import { GuestPoints } from './GuestPoints';
import { Islands } from './Islands';
import { hazeLayer, labelLayer } from './labels';
import { Ripples } from './Ripples';
import { useRoom } from './store';

/** The pool: lava islands, guest points and ripples. Transparent; space is drawn in CSS behind it. */
export function Scene() {
    const scale = useRoom((s) => s.stage.scale);
    const [dpr, setDpr] = useState(() =>
        Math.min(window.devicePixelRatio || 1, 2),
    );

    return (
        <div className="rt-scene" aria-hidden="true">
            <div
                ref={hazeLayer}
                className="rt-labels"
                style={{ '--rt-s': scale } as CSSProperties}
            />
            <Canvas
                className="rt-canvas"
                dpr={dpr}
                flat
                gl={{
                    alpha: true,
                    antialias: false,
                    powerPreference: 'high-performance',
                }}
                camera={{ position: [0, 0, 20], fov: 30, near: 0.1, far: 100 }}
            >
                <PerformanceMonitor
                    onChange={({ factor }) =>
                        setDpr(Math.round((1 + factor) * 10) / 10)
                    }
                    onFallback={() => setDpr(1)}
                />
                <AdaptiveDpr pixelated />

                <Conductor />
                <CameraRig />
                <Islands />
                <Ripples />
                <GuestPoints />
                <Effects />
            </Canvas>
            <div
                ref={labelLayer}
                className="rt-labels"
                style={{ '--rt-s': scale } as CSSProperties}
            />
        </div>
    );
}
