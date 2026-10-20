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
    /** World position of every guest orb, written by each orb every frame. */
    orbPositions: new Map<number, Vector3>(),
};

/** Multiplier for orbit and drift speed at the current energy. */
export function tempo(): number {
    return 0.35 + live.heat * 1.6;
}
