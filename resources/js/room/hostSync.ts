import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { xsrfToken } from '@/lib/xsrf';
import { end, nowPlaying, unavailable } from '@/routes/events';
import { useRoom } from './store';
import type { HostRoomProps } from './types';

/**
 * With the host key, tell the server a track's video would not play (removed, private, not embeddable), so no
 * set uses it again. The screen has already moved on.
 */
export function reportUnavailable(item: number, error: number): void {
    const { hostKey, event } = useRoom.getState();

    if (!hostKey) {
        return;
    }

    void fetch(unavailable.url(event.code), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({ key: hostKey, item, error }),
    }).catch(() => {
        // Offline: the track is skipped here either way.
    });
}

/** With the host key, end the party on the server, so guests can no longer join or change their loves. */
export async function endParty(): Promise<boolean> {
    const { hostKey, event } = useRoom.getState();

    if (!hostKey) {
        return false;
    }

    try {
        const response = await fetch(end.url(event.code), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ key: hostKey }),
        });

        return response.ok;
    } catch {
        return false;
    }
}

/**
 * With the host key, tell the server each time the room moves to another track, so set rebuilds and host
 * requests never bring back tracks the room has already heard. Reports are serialized so a quick skip cannot
 * arrive at the server before the preceding track; playback itself never waits on them.
 */
export function useHostSync(): void {
    useEffect(() => {
        let pending: number | null | undefined;
        let reporting = false;
        let closed = false;

        const report = async () => {
            if (reporting) {
                return;
            }

            reporting = true;
            while (!closed && pending !== undefined) {
                const item = pending;
                pending = undefined;
                const { hostKey, event } = useRoom.getState();

                if (!hostKey) {
                    break;
                }

                try {
                    const response = await fetch(nowPlaying.url(event.code), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-XSRF-TOKEN': xsrfToken(),
                        },
                        body: JSON.stringify({ key: hostKey, item }),
                    });

                    if (response.status === 404) {
                        pending = undefined;
                        router.reload({
                            only: ['nowPlaying', 'queue', 'youtubePaused'],
                            onSuccess: (page) =>
                                useRoom
                                    .getState()
                                    .adoptServer(
                                        page.props as unknown as HostRoomProps,
                                    ),
                        });
                        break;
                    }

                    if (response.ok) {
                        continue;
                    }

                    if (response.status !== 429 && response.status < 500) {
                        pending = undefined;
                        break;
                    }
                } catch {
                    // A brief connection loss should not leave the guest pages on the old title.
                }

                pending = useRoom.getState().nowPlaying?.id ?? null;
                await new Promise((resolve) => setTimeout(resolve, 2000));
            }
            reporting = false;
        };

        const unsubscribe = useRoom.subscribe((state, prev) => {
            if (
                state.hostKey &&
                state.nowPlaying &&
                ![state.nowPlaying, ...state.queue].some((item) => item.videoId)
            ) {
                state.skipUnavailableSet();

                return;
            }

            if (
                !state.hostKey ||
                state.nowPlaying?.id === prev.nowPlaying?.id
            ) {
                return;
            }

            pending = state.nowPlaying?.id ?? null;
            void report();
        });

        // The first state was loaded before this subscription mounted.
        useRoom.getState().skipUnavailableSet();

        return () => {
            closed = true;
            unsubscribe();
        };
    }, []);
}
