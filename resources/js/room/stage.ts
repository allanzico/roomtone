/**
 * The two host screen layouts (handoff §3.1). Everything in the pool is laid out in
 * "layout px": design px of the 1920×1080 stage on a TV, CSS px on a phone. The 3D scene
 * uses 1 world unit = 100 layout px, with the world origin at the centre of the frame.
 */

export type Layout = 'tv' | 'phone';

export type Rect = { x: number; y: number; w: number; h: number };

export type Stage = {
    layout: Layout;
    /** Frame size in layout px. TV: 1920×1080. Phone: the viewport. */
    width: number;
    height: number;
    /** CSS px per layout px (the TV stage's scale; 1 on a phone). */
    scale: number;
    /** Letterbox offset of the TV stage in CSS px (0 on a phone). */
    left: number;
    top: number;
    /** Where the islands may sit, in layout px. */
    band: Rect;
    /** Island radius for one guest, before the band's area budget shrinks it. */
    r0: number;
    /** Share of the band the islands' bodies may cover. */
    fill: number;
    ember: { x: number; y: number; r: number };
    ripple: { from: number; to: number; still: number };
    point: { dot: number; halo: number; focusDot: number; focusHalo: number };
    /** How far a new guest's point drops in from. */
    drop: number;
};

export const TV_WIDTH = 1920;
export const TV_HEIGHT = 1080;
export const PX_PER_UNIT = 100;

const PHONE_POOL_TOP = 110;

export function computeStage(vw: number, vh: number): Stage {
    if (vw >= vh && vw >= 1024) {
        const scale = Math.min(vw / TV_WIDTH, vh / TV_HEIGHT);

        return {
            layout: 'tv',
            width: TV_WIDTH,
            height: TV_HEIGHT,
            scale,
            left: (vw - TV_WIDTH * scale) / 2,
            top: (vh - TV_HEIGHT * scale) / 2,
            // Clear of the header, the join block and the guest stream on the right.
            band: { x: 110, y: 110, w: 1430, h: 610 },
            r0: 100,
            fill: 0.3,
            ember: { x: 760, y: 430, r: 34 },
            ripple: { from: 24, to: 410, still: 70 },
            point: { dot: 5, halo: 14, focusDot: 9, focusHalo: 28 },
            drop: 320,
        };
    }

    const poolHeight = phonePoolHeight(vh);

    return {
        layout: 'phone',
        width: vw,
        height: vh,
        scale: 1,
        left: 0,
        top: 0,
        band: {
            x: 20,
            y: PHONE_POOL_TOP,
            w: Math.max(200, vw - 40),
            h: poolHeight,
        },
        r0: 40,
        fill: 0.3,
        ember: { x: vw * 0.42, y: PHONE_POOL_TOP + poolHeight * 0.5, r: 16 },
        ripple: { from: 8, to: 105, still: 34 },
        point: { dot: 3, halo: 8, focusDot: 5, focusHalo: 15 },
        drop: 140,
    };
}

/** 320px on a 390×844 phone, scaling with height. */
export function phonePoolHeight(vh: number): number {
    return Math.round(Math.min(420, Math.max(220, vh * 0.38)));
}

/** Layout px → world units (y up). */
export function toWorld(stage: Stage, x: number, y: number): [number, number] {
    return [
        (x - stage.width / 2) / PX_PER_UNIT,
        -(y - stage.height / 2) / PX_PER_UNIT,
    ];
}

export const reducedMotion =
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;
