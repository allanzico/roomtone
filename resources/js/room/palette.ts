import {
    CanvasTexture,
    CustomBlending,
    OneFactor,
    SRGBColorSpace,
    SrcAlphaFactor,
    ZeroFactor,
} from 'three';

/**
 * Additive glow for a transparent canvas: adds light to the colour but leaves alpha alone,
 * so the browser composites it as pure light over the CSS space behind (no hard disc edge).
 */
export const glowBlending = {
    blending: CustomBlending,
    blendSrc: SrcAlphaFactor,
    blendDst: OneFactor,
    blendSrcAlpha: ZeroFactor,
    blendDstAlpha: OneFactor,
} as const;

let glow: CanvasTexture | null = null;

/** A soft radial glow, shared by every sprite in the scene. */
export function glowTexture(): CanvasTexture {
    if (glow) {
        return glow;
    }

    const size = 128;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');

    if (ctx) {
        const g = ctx.createRadialGradient(
            size / 2,
            size / 2,
            0,
            size / 2,
            size / 2,
            size / 2,
        );
        g.addColorStop(0, 'rgba(255,255,255,1)');
        g.addColorStop(0.25, 'rgba(255,255,255,0.6)');
        g.addColorStop(0.6, 'rgba(255,255,255,0.15)');
        g.addColorStop(1, 'rgba(255,255,255,0)');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, size, size);
    }

    glow = new CanvasTexture(canvas);
    glow.colorSpace = SRGBColorSpace;

    return glow;
}
