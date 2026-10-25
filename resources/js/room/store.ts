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
    /** Items this screen has left, so an older server response cannot rewind playback. */
    locallyLeftIds: number[];
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
    /** YouTube lookups are paused: new songs may have no video. */
    youtubePaused: boolean;
    /** Host link key, or null on a view-only screen. */
    hostKey: string | null;
    latestRequest: HostRequestState | null;
    /** TV: the "ask the DJ" composer is open (press /). */
    askOpen: boolean;
    init: (props: HostRoomProps) => void;
    /** Fresh server props (after a request or a poll): the server owns the set when there is a host key. */
    syncFromServer: (props: HostRoomProps) => void;
    /** Take the server's playing track and queue as they are (after the server refused a report). */
    adoptServer: (props: HostRoomProps) => void;
    setAskOpen: (open: boolean) => void;
    addGuest: (guest: Guest) => void;
    nextTrack: () => void;
    skipUnavailableSet: () => void;
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
    locallyLeftIds: [],
    queue: [],
    energy: 3,
    audioLevel: 0,
    started: false,
    paused: false,
    volume: savedVolume(),
    muted: saved(MUTED_KEY) === '1',
    reconnecting: false,
    join: { url: '', host: '', qr: '' },
    youtubePaused: false,
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
            locallyLeftIds: [],
            queue: props.queue,
            energy: clampEnergy(props.event.energy),
            join: props.join,
            hostKey: props.hostKey,
            youtubePaused: props.youtubePaused,
            latestRequest: props.latestRequest,
        }),

    adoptServer: (props) =>
        set({
            nowPlaying: props.nowPlaying,
            locallyLeftIds: [],
            queue: props.queue,
            energy: props.nowPlaying
                ? clampEnergy(props.nowPlaying.energy)
                : clampEnergy(props.event.energy),
            youtubePaused: props.youtubePaused,
        }),

    syncFromServer: (props) =>
        set((state) => {
            // The host screen is where playback happens and it tells the server when it moves on, so a reload that
            // lands before the server heard (or an older one) must not put the screen back on a track it already
            // left. A view-only screen follows the server. Same track: the object stays, so the player does not
            // restart it.
            const keep =
                state.hostKey !== null &&
                props.nowPlaying !== null &&
                state.locallyLeftIds.includes(props.nowPlaying.id);
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
                youtubePaused: props.youtubePaused,
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

    // Only the host link drives playback. A view-only screen follows server updates without silently advancing
    // music that guests' pages cannot hear about.
    nextTrack: () =>
        set((state) => {
            if (state.hostKey === null) {
                return {};
            }

            const [next, ...rest] = state.queue;

            return {
                nowPlaying: next ?? null,
                // The scene follows the energy arc through each track's energy.
                energy: next ? clampEnergy(next.energy) : state.energy,
                queue: rest,
                locallyLeftIds: state.nowPlaying
                    ? [...state.locallyLeftIds, state.nowPlaying.id]
                    : state.locallyLeftIds,
            };
        }),

    skipUnavailableSet: () =>
        set((state) => {
            if (
                state.hostKey === null ||
                state.nowPlaying === null ||
                [state.nowPlaying, ...state.queue].some((item) => item.videoId)
            ) {
                return {};
            }

            return {
                nowPlaying: null,
                queue: [],
                locallyLeftIds: [...state.locallyLeftIds, state.nowPlaying.id],
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
