import { useEffect } from 'react';
import { nowPlaying } from '@/routes/events';
import { useRoom } from './store';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
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
