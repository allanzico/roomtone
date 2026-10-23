import { useEffect } from 'react';
import { xsrfToken } from '@/lib/xsrf';
import { nowPlaying, unavailable } from '@/routes/events';
import { useRoom } from './store';

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

/**
 * With the host key, tell the server each time the room moves to another track, so set rebuilds and host
 * requests never bring back tracks the room has already heard. Fire and forget: playback never waits on it.
 */
export function useHostSync(): void {
    useEffect(
        () =>
            useRoom.subscribe((state, prev) => {
                const item = state.nowPlaying;

                if (
                    !state.hostKey ||
                    !item ||
                    item.id === prev.nowPlaying?.id
                ) {
                    return;
                }

                void fetch(nowPlaying.url(state.event.code), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                    body: JSON.stringify({ key: state.hostKey, item: item.id }),
                }).catch(() => {
                    // Offline for a moment: the next track change sends the state again.
                });
            }),
        [],
    );
}
