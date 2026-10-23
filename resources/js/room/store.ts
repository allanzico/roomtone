import { create } from 'zustand';
import { computeStage } from './stage';
import type { Stage } from './stage';
import type {
    Cluster,
    Guest,
    HostRequestState,
    HostRoomProps,
    JoinInfo,
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
    /** Player volume, 0–100, and mute; remembered on this device. */
    volume: number;
    muted: boolean;
    /** Realtime connection dropped (Phase 2+). The last state stays on screen. */
    reconnecting: boolean;
    stage: Stage;
    join: JoinInfo;
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
    setVolume: (volume: number) => void;
    toggleMute: () => void;
    start: () => void;
    stop: () => void;
    setStage: (stage: Stage) => void;
};

const VOLUME_KEY = 'roomtone-volume';
const MUTED_KEY = 'roomtone-muted';

function saved(key: string): string | null {
    return typeof window === 'undefined'
        ? null
        : window.localStorage.getItem(key);
}

function savedVolume(): number {
    const volume = Number(saved(VOLUME_KEY) ?? NaN);

    return volume >= 0 && volume <= 100 ? volume : 100;
}

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
    volume: savedVolume(),
    muted: saved(MUTED_KEY) === '1',
    reconnecting: false,
    join: { url: '', host: '', qr: '' },
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
            join: props.join,
            hostKey: props.hostKey,
            latestRequest: props.latestRequest,
        }),

    syncFromServer: (props) =>
        set((state) => {
            // The host screen is where playback happens and it tells the server when it moves on, so a reload that
            // lands before the server heard (or an older one) must not put the screen back on a track it already
            // left. A view-only screen follows the server. Same track: the object stays, so the player does not
            // restart it.
            const keep =
                state.nowPlaying !== null &&
                (state.hostKey !== null ||
                    state.nowPlaying.id === props.nowPlaying?.id);
            const nowPlaying = keep ? state.nowPlaying : props.nowPlaying;

            return {
                energy:
                    nowPlaying && nowPlaying.id !== state.nowPlaying?.id
                        ? clampEnergy(nowPlaying.energy)
                        : state.energy,
                clusters: props.clusters,
                guests: props.guests,
                queue: props.queue.filter((item) => item.id !== nowPlaying?.id),
                latestRequest: props.latestRequest,
                nowPlaying,
            };
        }),

    setAskOpen: (askOpen) => set({ askOpen }),

    // A guest who is already in the room (a repeated live event) is not added twice.
    addGuest: (guest) =>
        set((state) =>
            state.guests.some((g) => g.id === guest.id)
                ? {}
                : { guests: [...state.guests, guest] },
        ),

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
    // Like YouTube's slider: dragging to 0 mutes, moving it up unmutes.
    setVolume: (volume) => {
        const next = Math.min(100, Math.max(0, Math.round(volume)));
        window.localStorage.setItem(VOLUME_KEY, String(next));
        window.localStorage.setItem(MUTED_KEY, next === 0 ? '1' : '0');
        set({ volume: next, muted: next === 0 });
    },
    toggleMute: () =>
        set((state) => {
            // Unmuting from 0 brings the sound back at half.
            const volume =
                state.muted && state.volume === 0 ? 50 : state.volume;
            window.localStorage.setItem(VOLUME_KEY, String(volume));
            window.localStorage.setItem(MUTED_KEY, state.muted ? '0' : '1');

            return { muted: !state.muted, volume };
        }),

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
