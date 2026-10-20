import {
    Bloom,
    ChromaticAberration,
    EffectComposer,
    Noise,
    ToneMapping,
    Vignette,
} from '@react-three/postprocessing';
import { useFrame, useThree } from '@react-three/fiber';
import { BlendFunction, ToneMappingMode } from 'postprocessing';
import type { BloomEffect } from 'postprocessing';
import { useMemo, useRef } from 'react';
import { HalfFloatType, UnsignedByteType, Vector2 } from 'three';
import { live } from './live';
import { useRoom } from './store';

const aberration = new Vector2(0.0005, 0.0004);

/**
 * Software renderers (no GPU, or hardware acceleration switched off) draw HDR bloom as a black
 * screen. There we fall back to 8-bit buffers so the room still shows up.
 */
function isSoftwareRenderer(
    gl: WebGLRenderingContext | WebGL2RenderingContext,
): boolean {
    const info = gl.getExtension('WEBGL_debug_renderer_info');
    const renderer = String(
        gl.getParameter(info ? info.UNMASKED_RENDERER_WEBGL : gl.RENDERER),
    );

    return /swiftshader|llvmpipe|software/i.test(renderer);
}

export function Effects() {
    const gl = useThree((s) => s.gl);
    const software = useMemo(() => isSoftwareRenderer(gl.getContext()), [gl]);
    const bloom = useRef<BloomEffect>(null);

    useFrame(() => {
        if (bloom.current) {
            const level = useRoom.getState().audioLevel;
            bloom.current.intensity = 0.75 + live.heat * 0.95 + level * 0.35;
        }
    });

    return (
        <EffectComposer
            multisampling={software ? 0 : 4}
            frameBufferType={software ? UnsignedByteType : HalfFloatType}
        >
            <Bloom
                ref={bloom}
                mipmapBlur
                luminanceThreshold={0.2}
                luminanceSmoothing={0.3}
                intensity={1}
                radius={0.78}
            />
            <ToneMapping
                mode={
                    software
                        ? ToneMappingMode.LINEAR
                        : ToneMappingMode.ACES_FILMIC
                }
            />
            <ChromaticAberration
                offset={aberration}
                radialModulation
                modulationOffset={0.4}
            />
            <Vignette offset={0.25} darkness={0.75} />
            <Noise opacity={0.03} blendFunction={BlendFunction.SOFT_LIGHT} />
        </EffectComposer>
    );
}
