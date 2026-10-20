import { AdaptiveDpr, PerformanceMonitor } from '@react-three/drei';
import { Canvas } from '@react-three/fiber';
import { useState } from 'react';
import { Atmosphere } from './Atmosphere';
import { CameraRig } from './CameraRig';
import { Clusters } from './Clusters';
import { Conductor } from './Conductor';
import { Effects } from './Effects';
import { Grooves } from './Grooves';
import { GuestOrbs } from './GuestOrbs';
import { labelLayer } from './labels';
import { ReasonBeam } from './ReasonBeam';
import { Star } from './Star';

const INK = '#03060F';

export function Scene() {
    const [dpr, setDpr] = useState(() =>
        Math.min(window.devicePixelRatio || 1, 2),
    );

    return (
        <>
            <Canvas
                className="rt-canvas"
                dpr={dpr}
                flat
                gl={{ antialias: false, powerPreference: 'high-performance' }}
                camera={{ position: [0, 5, 24], fov: 42, near: 0.1, far: 200 }}
            >
                <PerformanceMonitor
                    onChange={({ factor }) =>
                        setDpr(Math.round((1 + factor) * 10) / 10)
                    }
                    onFallback={() => setDpr(1)}
                />
                <AdaptiveDpr pixelated />

                <color attach="background" args={[INK]} />
                <fog attach="fog" args={[INK, 18, 48]} />
                <ambientLight intensity={0.35} />
                <pointLight
                    position={[5, 6, 9]}
                    intensity={170}
                    decay={2}
                    color="#FFF1E6"
                />
                <pointLight
                    position={[-7, -2, -6]}
                    intensity={90}
                    decay={2}
                    color="#6C7BFF"
                />

                <Conductor />
                <CameraRig />
                <Atmosphere />
                <Grooves />
                <Clusters />
                <Star />
                <GuestOrbs />
                <ReasonBeam />
                <Effects />
            </Canvas>
            <div ref={labelLayer} className="rt-labels" />
        </>
    );
}
