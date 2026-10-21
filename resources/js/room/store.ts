import { create } from 'zustand';
import { computeStage } from './stage';
import type { Stage } from './stage';
import type {
    Cluster,
    Guest,
    HostRequestState,
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
    /** The host clicked "Start the party"; until then nothing plays (browsers block unmuted autoplay). */
    started: boolean;
    /** Host pressed pause. */
    paused: boolean;
    /** Realtime connection dropped (Phase 2+). The last state stays on screen. */
    reconnecting: boolean;
    stage: Stage;
    /** Host link key, or null on a view-only screen. */
    hostKey: string | null;
    latestRequest: HostRequestState | null;
    /** TV: the "ask the DJ" composer is open (press /). */
    askOpen: boolean;
    init: (props: HostRoomProps) => void;
    /** Fresh server props (after a request or a poll): the server owns the set when there is a host key. */
    syncFromServer: (props: HostRoomProps) => void;
    setAskOpen: (open: boolean) => void;
    addGuest: (guest: Guest) => void;
    nextTrack: () => void;
    setEnergy: (energy: number) => void;
    setAudioLevel: (level: number) => void;
    togglePaused: () => void;
    start: () => void;
    stop: () => void;
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
    started: false,
    paused: false,
    reconnecting: false,
    hostKey: null,
    latestRequest: null,
    askOpen: false,
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
            hostKey: props.hostKey,
            latestRequest: props.latestRequest,
        }),

    syncFromServer: (props) =>
        set((state) => ({
            energy:
                props.nowPlaying && state.nowPlaying?.id !== props.nowPlaying.id
                    ? clampEnergy(props.nowPlaying.energy)
                    : state.energy,
            clusters: props.clusters,
            guests: props.guests,
            queue: props.queue,
            latestRequest: props.latestRequest,
            // Same track: keep the object so the player does not restart it.
            nowPlaying:
                state.nowPlaying?.id === props.nowPlaying?.id
                    ? state.nowPlaying
                    : props.nowPlaying,
        })),

    setAskOpen: (askOpen) => set({ askOpen }),

    addGuest: (guest) => set((state) => ({ guests: [...state.guests, guest] })),

    // With the host key the server owns the set and a played track is done. A view-only screen (no key) loops
    // the played track to the end of the queue so the room never runs dry.
    nextTrack: () =>
        set((state) => {
            const [next, ...rest] = state.queue;

            if (!next) {
                return {};
            }

            return {
                nowPlaying: next,
                // The scene follows the energy arc through each track's energy.
                energy: clampEnergy(next.energy),
                queue:
                    state.nowPlaying && state.hostKey === null
                        ? [...rest, state.nowPlaying]
                        : rest,
            };
        }),

    setEnergy: (energy) => set({ energy: clampEnergy(energy) }),

    setAudioLevel: (audioLevel) => set({ audioLevel }),

    togglePaused: () => set((state) => ({ paused: !state.paused })),

    start: () => set({ started: true, paused: false }),

    stop: () => set({ started: false }),

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
