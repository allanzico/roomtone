import { create } from 'zustand';
import type {
    Cluster,
    Guest,
    HostRoomProps,
    RoomEvent,
    SetItem,
} from './types';

export const MIN_ENERGY = 1;
export const MAX_ENERGY = 5;

type RoomState = {
    event: RoomEvent;
    clusters: Cluster[];
    guests: Guest[];
    nowPlaying: SetItem | null;
    queue: SetItem[];
    /** Host's target energy, 1–5. The scene eases towards it. */
    energy: number;
    /** 0–1. Driven by a slow "breathing" sine for now; the host mic replaces it later. */
    audioLevel: number;
    init: (props: HostRoomProps) => void;
    addGuest: (guest: Guest) => void;
    nextTrack: () => void;
    setEnergy: (energy: number) => void;
    setAudioLevel: (level: number) => void;
};

export const useRoom = create<RoomState>()((set) => ({
    event: { code: '', name: '', occasion: null, energy: 3 },
    clusters: [],
    guests: [],
    nowPlaying: null,
    queue: [],
    energy: 3,
    audioLevel: 0,

    init: (props) =>
        set({
            event: props.event,
            clusters: props.clusters,
            guests: props.guests,
            nowPlaying: props.nowPlaying,
            queue: props.queue,
            energy: clampEnergy(props.event.energy),
        }),

    addGuest: (guest) => set((state) => ({ guests: [...state.guests, guest] })),

    // Until the server owns the set (Phase 4+), the played track loops to the end of the queue.
    nextTrack: () =>
        set((state) => {
            const [next, ...rest] = state.queue;

            if (!next) {
                return {};
            }

            return {
                nowPlaying: next,
                queue: state.nowPlaying ? [...rest, state.nowPlaying] : rest,
            };
        }),

    setEnergy: (energy) => set({ energy: clampEnergy(energy) }),

    setAudioLevel: (audioLevel) => set({ audioLevel }),
}));

function clampEnergy(energy: number): number {
    return Math.min(MAX_ENERGY, Math.max(MIN_ENERGY, Math.round(energy)));
}

/** The cluster a guest belongs to most strongly. */
export function dominantClusterId(guest: Guest | undefined): number | null {
    if (!guest || guest.clusterWeights.length === 0) {
        return null;
    }

    return guest.clusterWeights.reduce((best, w) =>
        w.weight > best.weight ? w : best,
    ).clusterId;
}

/** The colour of the cluster a track was picked from: its reason guest's dominant cluster. */
export function trackColor(
    state: Pick<RoomState, 'guests' | 'clusters'>,
    item: SetItem | null,
): string | null {
    if (!item) {
        return null;
    }

    const guest = state.guests.find((g) => g.id === item.reasonGuestId);
    const clusterId = dominantClusterId(guest);

    return state.clusters.find((c) => c.id === clusterId)?.color ?? null;
}
