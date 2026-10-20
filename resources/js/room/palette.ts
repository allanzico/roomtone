import { CanvasTexture, Color, SRGBColorSpace } from 'three';

// The room's mood runs from cool Atlantic blue (energy 1) through violet to warm ember (energy 5).
const COOL = new Color('#2F6BFF');
const MID = new Color('#9B4DFF');
const WARM = new Color('#FF5A2E');

// Background ink: blue-black when calm, wine-black at peak.
const INK_COOL = new Color('#03060F');
const INK_WARM = new Color('#0D050B');

/** Write the energy colour for `heat` (0 calm – 1 peak) into `out`. */
export function energyColor(heat: number, out: Color): Color {
    return heat < 0.5
        ? out.lerpColors(COOL, MID, heat * 2)
        : out.lerpColors(MID, WARM, (heat - 0.5) * 2);
}

export function inkColor(heat: number, out: Color): Color {
    return out.lerpColors(INK_COOL, INK_WARM, heat);
}

/** CSS version of the energy colour for an integer energy level (1–5), for the HUD. */
export function energyCss(energy: number): string {
    return `#${energyColor((energy - 1) / 4, new Color()).getHexString()}`;
}

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
        g.addColorStop(0.18, 'rgba(255,255,255,0.55)');
        g.addColorStop(0.45, 'rgba(255,255,255,0.14)');
        g.addColorStop(1, 'rgba(255,255,255,0)');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, size, size);
    }

    glow = new CanvasTexture(canvas);
    glow.colorSpace = SRGBColorSpace;

    return glow;
}
