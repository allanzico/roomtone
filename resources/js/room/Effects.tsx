import { EffectComposer } from '@react-three/postprocessing';
import { useFrame, useThree } from '@react-three/fiber';
import { BloomEffect } from 'postprocessing';
import { useEffect, useMemo } from 'react';
import { HalfFloatType, UnsignedByteType } from 'three';
import { live } from './live';
import { useRoom } from './store';

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

/**
 * Bloom that only adds light. Stock bloom also blends its blurred alpha into the frame, which
 * on this transparent canvas dims the CSS space and haze behind the islands.
 */
class LightOnlyBloom extends BloomEffect {
    constructor() {
        super({
            mipmapBlur: true,
            luminanceThreshold: 0.72,
            luminanceSmoothing: 0.2,
            intensity: 0.25,
            radius: 0.7,
        });

        this.setFragmentShader(
            this.getFragmentShader().replace(
                'outputColor=texture2D(map,uv)*intensity;',
                'outputColor=vec4(texture2D(map,uv).rgb*intensity,0.0);',
            ),
        );
    }
}

/** A little bloom on the lava's highlights only. The CSS haze does the big glow. */
export function Effects() {
    const gl = useThree((s) => s.gl);
    const software = useMemo(() => isSoftwareRenderer(gl.getContext()), [gl]);
    const bloom = useMemo(() => new LightOnlyBloom(), []);

    useEffect(() => () => bloom.dispose(), [bloom]);

    useFrame(() => {
        const level = useRoom.getState().audioLevel;
        bloom.intensity = 0.22 + live.heat * 0.12 + level * 0.08;
    });

    return (
        <EffectComposer
            multisampling={software ? 0 : 4}
            frameBufferType={software ? UnsignedByteType : HalfFloatType}
        >
            <primitive object={bloom} />
        </EffectComposer>
    );
}
