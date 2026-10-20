import { useEffect } from 'react';
import { makeFakeGuest } from './fakeGuests';
import { MAX_ENERGY, useRoom } from './store';

/**
 * Keyboard controls for seeing the three key moments with fake data:
 * G adds a guest, N plays the next track, E cycles energy 1→5.
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

            switch (event.key.toLowerCase()) {
                case 'g':
                    room.addGuest(makeFakeGuest(room.clusters));
                    break;
                case 'n':
                    room.nextTrack();
                    break;
                case 'e':
                    room.setEnergy((room.energy % MAX_ENERGY) + 1);
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
