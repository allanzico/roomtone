import { PlaneGeometry, ShaderMaterial } from 'three';

/**
 * Ripple rings, drawn analytically on one quad. A ring spawns every `uInterval`
 * seconds (at most `uSpawns` of them), grows from `uFrom` to `uTo` over `uLife` with a
 * cubic ease-out, fading 0.85 → 0 while its stroke thins. `uStill` > 0 draws one
 * static ring instead (reduced motion, the ember). Radii and strokes are world units.
 */
export function makeRippleMaterial(): ShaderMaterial {
    return new ShaderMaterial({
        transparent: true,
        depthTest: false,
        depthWrite: false,
        uniforms: {
            uTime: { value: 0 },
            uInterval: { value: 0.8 },
            uLife: { value: 4 },
            uSpawns: { value: 1e6 },
            uFrom: { value: 0.24 },
            uTo: { value: 4.1 },
            uStrokeFrom: { value: 0.03 },
            uStrokeTo: { value: 0.01 },
            uStill: { value: 0 },
            uSize: { value: 4.2 },
            uOpacity: { value: 1 },
        },
        vertexShader: /* glsl */ `
            varying vec2 vUv;

            void main() {
                vUv = uv;
                gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
            }
        `,
        fragmentShader: /* glsl */ `
            uniform float uTime;
            uniform float uInterval;
            uniform float uLife;
            uniform float uSpawns;
            uniform float uFrom;
            uniform float uTo;
            uniform float uStrokeFrom;
            uniform float uStrokeTo;
            uniform float uStill;
            uniform float uSize;
            uniform float uOpacity;
            varying vec2 vUv;

            float ring(float d, float r, float w) {
                float aa = fwidth(d);
                return 1.0 - smoothstep(w * 0.5, w * 0.5 + aa, abs(d - r));
            }

            void main() {
                float d = length(vUv - 0.5) * 2.0 * uSize;
                float a = 0.0;

                if (uStill > 0.0) {
                    a = ring(d, uStill, uStrokeFrom) * 0.6;
                } else {
                    float latest = floor(uTime / uInterval);

                    for (int j = 0; j < 6; j++) {
                        float index = latest - float(j);

                        if (index < 0.0 || index >= uSpawns) {
                            continue;
                        }

                        float p = (uTime - index * uInterval) / uLife;

                        if (p >= 1.0) {
                            continue;
                        }

                        float eased = 1.0 - pow(1.0 - p, 3.0);
                        float r = mix(uFrom, uTo, eased);
                        float w = mix(uStrokeFrom, uStrokeTo, p);
                        a = max(a, ring(d, r, w) * 0.85 * (1.0 - p));
                    }
                }

                if (a <= 0.001) {
                    discard;
                }

                gl_FragColor = vec4(1.0, 1.0, 1.0, a * uOpacity);
            }
        `,
    });
}

/** A 2×2 quad: scale it by `uSize`. */
export const rippleQuad = new PlaneGeometry(2, 2);
