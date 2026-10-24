import { useEffect } from 'react';
import { makeFakeGuest } from './fakeGuests';
import { MAX_ENERGY, useRoom } from './store';

/** G (fake guest) and E (cycle energy) are for building the scene: they exist in dev builds only. */
export const DEV_KEYS = import.meta.env.DEV;

/**
 * Keyboard controls: N plays the next track, H toggles the hints, / asks the DJ (host link). In dev builds also
 * G adds a fake guest and E cycles energy 1→5.
 */
export function useDevKeys(onToggleHint: () => void): void {
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const typing =
                event.target instanceof HTMLElement &&
                event.target.closest(
                    'input, textarea, [contenteditable="true"]',
                );

            if (
                event.repeat ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey ||
                typing
            ) {
                return;
            }

            const room = useRoom.getState();

            if (event.key === '/' && room.hostKey !== null) {
                event.preventDefault();
                room.setAskOpen(true);

                return;
            }

            switch (event.key.toLowerCase()) {
                case 'g':
                    if (DEV_KEYS) {
                        room.addGuest(makeFakeGuest(room.clusters));
                    }

                    break;
                case 'n':
                    room.nextTrack();
                    break;
                case 'e':
                    if (DEV_KEYS) {
                        room.setEnergy((room.energy % MAX_ENERGY) + 1);
                    }

                    break;
                case 'h':
                    onToggleHint();
                    break;
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [onToggleHint]);
}
