import { create } from 'zustand';
import { computeStage } from './stage';
import type { Stage } from './stage';
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
    /** Host pressed pause on the phone. Playback arrives in Phase 4; for now it only shows. */
    paused: boolean;
    /** Realtime connection dropped (Phase 2+). The last state stays on screen. */
    reconnecting: boolean;
    stage: Stage;
    init: (props: HostRoomProps) => void;
    addGuest: (guest: Guest) => void;
    nextTrack: () => void;
    setEnergy: (energy: number) => void;
    setAudioLevel: (level: number) => void;
    togglePaused: () => void;
    setStage: (stage: Stage) => void;
};

export const useRoom = create<RoomState>()((set) => ({
    event: { code: '', name: '', occasion: null, energy: 3 },
    clusters: [],
    guests: [],
    nowPlaying: null,
    queue: [],
    energy: 3,
    audioLevel: 0,
    paused: false,
    reconnecting: false,
    stage: computeStage(
        typeof window === 'undefined' ? 1920 : window.innerWidth,
        typeof window === 'undefined' ? 1080 : window.innerHeight,
    ),

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

    togglePaused: () => set((state) => ({ paused: !state.paused })),

    setStage: (stage) => set({ stage }),
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
