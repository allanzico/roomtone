import { seeded } from './random';
import type { Rect, Stage } from './stage';
import { dominantClusterId } from './store';
import type { Cluster, Guest, SetItem } from './types';

/** A genre's colours (handoff §2, genre palette), read from the CSS tokens. */
export type Lava = {
    base: string;
    light: string;
    dark: string;
    /** Label ink on the lava, with its alpha. */
    ink: string;
};

export const SLOT_COUNT = 6;

let slots: Lava[] | null = null;

function readSlots(): Lava[] {
    if (slots) {
        return slots;
    }

    const css = getComputedStyle(document.documentElement);
    const token = (name: string) => css.getPropertyValue(name).trim();

    slots = Array.from({ length: SLOT_COUNT }, (_, i) => ({
        base: token(`--rt-genre-${i + 1}`),
        light: token(`--rt-genre-${i + 1}-light`),
        dark: token(`--rt-genre-${i + 1}-dark`),
        ink: token(`--rt-genre-${i + 1}-ink`),
    }));

    return slots;
}

/** The palette slot for a cluster: the one its colour came from, else by creation order. */
export function lavaFor(cluster: Cluster, index: number): Lava {
    const all = readSlots();
    const color = cluster.color.toLowerCase();

    return (
        all.find((slot) => slot.base.toLowerCase() === color) ??
        all[index % SLOT_COUNT]
    );
}

/** The ember is always slot 1. */
export function emberLava(): Lava {
    return readSlots()[0];
}

/** Base colour of a guest's dominant cluster, for list dots. Unclustered guests get a quiet grey. */
export function guestColor(
    guest: Guest | undefined,
    clusters: Cluster[],
): string {
    return clusterColor(dominantClusterId(guest), clusters);
}

/** Base colour of a cluster, for a track's dot. No cluster gets a quiet grey. */
export function clusterColor(id: number | null, clusters: Cluster[]): string {
    const index = clusters.findIndex((c) => c.id === id);

    return index < 0
        ? 'var(--rt-text-4)'
        : lavaFor(clusters[index], index).base;
}

/** Guests grouped by their dominant cluster, in join order. `null` = not clustered yet. */
export function membersByCluster(guests: Guest[]): Map<number | null, Guest[]> {
    const members = new Map<number | null, Guest[]>();

    for (const guest of guests) {
        const id = dominantClusterId(guest);
        members.set(id, [...(members.get(id) ?? []), guest]);
    }

    return members;
}

export type Spot = { id: number; x: number; y: number; r: number };

/** The ember's id in island maps. Cluster ids are positive. */
export const EMBER_ID = 0;

/**
 * Where each island sits, in layout px. Area is proportional to the guests in it
 * (radius = r0 · √members), shrunk together if the room outgrows the band. Each
 * cluster's x/z position is its preferred spot on the floor; overlaps are pushed apart.
 */
export function layoutIslands(
    clusters: Cluster[],
    members: Map<number | null, Guest[]>,
    stage: Stage,
    withEmber: boolean,
): Spot[] {
    const { band } = stage;
    const sized = clusters
        .map((cluster) => ({
            cluster,
            count: members.get(cluster.id)?.length ?? 0,
        }))
        .filter(({ count }) => count > 0);
    const total = sized.reduce((sum, { count }) => sum + count, 0);
    const r0 =
        total === 0
            ? stage.r0
            : Math.min(
                  stage.r0,
                  Math.sqrt((stage.fill * band.w * band.h) / (Math.PI * total)),
              );

    const spots: Spot[] = sized.map(({ cluster, count }) => ({
        id: cluster.id,
        ...floorToBand(cluster.position[0], cluster.position[2], band),
        r: r0 * Math.sqrt(count),
    }));

    if (withEmber) {
        spots.push({ id: EMBER_ID, ...stage.ember });
    }

    return relax(spots, band, stage.layout === 'tv' ? 28 : 18);
}

// The floor the agent places clusters on: x −7.2…7.2, z −3.05…3.05 (z towards the viewer).
const FLOOR_X = 7.2;
const FLOOR_Z = 3.05;

function floorToBand(
    x: number,
    z: number,
    band: Rect,
): { x: number; y: number } {
    const u = Math.min(1, Math.max(0, (x + FLOOR_X) / (FLOOR_X * 2)));
    const v = Math.min(1, Math.max(0, (z + FLOOR_Z) / (FLOOR_Z * 2)));

    return { x: band.x + u * band.w, y: band.y + v * band.h };
}

/** Satellite blobs reach a little past an island's body radius. */
const REACH = 1.15;

/** Push overlapping islands apart and keep them inside the band. Deterministic. */
function relax(spots: Spot[], band: Rect, gap: number): Spot[] {
    const out = spots.map((s) => ({ ...s }));
    const rand = seeded(7);

    for (let pass = 0; pass < 80; pass++) {
        for (let i = 0; i < out.length; i++) {
            for (let j = i + 1; j < out.length; j++) {
                const a = out[i];
                const b = out[j];
                let dx = b.x - a.x;
                let dy = b.y - a.y;
                let dist = Math.hypot(dx, dy);
                const min = (a.r + b.r) * REACH + gap;

                if (dist >= min) {
                    continue;
                }

                if (dist < 0.001) {
                    dx = rand() - 0.5;
                    dy = rand() - 0.5;
                    dist = Math.hypot(dx, dy);
                }

                // The smaller island moves more.
                const push = (min - dist) / dist;
                const share = b.r / (a.r + b.r);
                a.x -= dx * push * share;
                a.y -= dy * push * share;
                b.x += dx * push * (1 - share);
                b.y += dy * push * (1 - share);
            }
        }

        for (const s of out) {
            s.x = clampInside(s.x, s.r * REACH, band.x, band.w);
            s.y = clampInside(s.y, s.r * REACH, band.y, band.h);
        }
    }

    return out;
}

function clampInside(
    v: number,
    r: number,
    start: number,
    size: number,
): number {
    return r * 2 >= size
        ? start + size / 2
        : Math.min(start + size - r, Math.max(start + r, v));
}

export type MixSegment = {
    key: string;
    label: string;
    color: string;
    count: number;
    playing: boolean;
};

const MAX_SEGMENTS = 4;

/**
 * The set by cluster, for the mix bar and the genre dots: the playing cluster first,
 * then the rest in palette order.
 */
export function mixOf(
    clusters: Cluster[],
    nowPlaying: SetItem | null,
    queue: SetItem[],
): MixSegment[] {
    const items = nowPlaying ? [nowPlaying, ...queue] : queue;
    const counts = new Map<number, number>();

    // A track counts for its own genre, not for the genre of the guest it is for.
    for (const item of items) {
        if (item.clusterId !== null) {
            counts.set(item.clusterId, (counts.get(item.clusterId) ?? 0) + 1);
        }
    }

    const playingId = nowPlaying?.clusterId ?? null;

    return clusters
        .map((cluster, index) => ({
            key: String(cluster.id),
            label: cluster.label,
            color: lavaFor(cluster, index).base,
            count: counts.get(cluster.id) ?? 0,
            playing: cluster.id === playingId,
        }))
        .filter((segment) => segment.count > 0)
        .sort((a, b) => Number(b.playing) - Number(a.playing));
}

/** At most four segments; the tail merges into "Other". */
export function mixBar(mix: MixSegment[]): MixSegment[] {
    if (mix.length <= MAX_SEGMENTS) {
        return mix;
    }

    const rest = mix.slice(MAX_SEGMENTS - 1);

    return [
        ...mix.slice(0, MAX_SEGMENTS - 1),
        {
            key: 'other',
            label: 'Other',
            color: 'var(--rt-text-4)',
            count: rest.reduce((sum, s) => sum + s.count, 0),
            playing: rest.some((s) => s.playing),
        },
    ];
}

/** "A, B and C" */
export function listOf(words: string[]): string {
    return words.length < 2
        ? (words[0] ?? '')
        : `${words.slice(0, -1).join(', ')} and ${words[words.length - 1]}`;
}

const graphemes = new Intl.Segmenter(undefined, { granularity: 'grapheme' });

/** Pool names: 14 characters (graphemes, so emoji and accents stay whole), then an ellipsis. */
export function shortName(name: string): string {
    const chars = Array.from(graphemes.segment(name), (s) => s.segment);

    return chars.length > 14 ? `${chars.slice(0, 14).join('')}…` : name;
}
