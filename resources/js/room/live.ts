import { Vector3 } from 'three';

/**
 * Values that change every frame. They live outside React and zustand so the
 * scene can animate at 60fps without re-rendering any component.
 */
export const live = {
    /** Smoothed energy, eases towards the store's target (1–5). */
    energy: 3,
    /** Smoothed energy mapped to 0 (calm) – 1 (peak). */
    heat: 0.5,
    /** Current (sprung) centre and radius of every island and the ember, in world units. */
    islands: new Map<number, { x: number; y: number; r: number }>(),
    /** World position of every guest point, written by each point every frame. */
    points: new Map<number, Vector3>(),
};

/** Multiplier for wobble speed at the current energy. */
export function tempo(): number {
    return 0.6 + live.heat * 0.8;
}
