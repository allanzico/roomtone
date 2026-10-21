import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { Hud } from '@/room/Hud';
import { live } from '@/room/live';
import { Scene } from '@/room/Scene';
import { computeStage } from '@/room/stage';
import { Starfield } from '@/room/Starfield';
import { useRoom } from '@/room/store';
import type { HostRoomProps } from '@/room/types';
import { useDevKeys } from '@/room/useDevKeys';
import '../../css/tokens.css';
import '@/room/room.css';

const HINT_KEY = 'roomtone:hide-key-hint';

function readHintHidden(): boolean {
    try {
        return window.localStorage.getItem(HINT_KEY) === '1';
    } catch {
        return false;
    }
}

function writeHintHidden(hidden: boolean): void {
    try {
        window.localStorage.setItem(HINT_KEY, hidden ? '1' : '0');
    } catch {
        // Storage can be unavailable (private mode); the hint just shows again next time.
    }
}

export default function HostRoom(props: HostRoomProps) {
    // Load the server props into the store before the first render of the scene.
    useState(() => {
        useRoom.getState().init(props);
        live.energy = props.event.energy;
        live.heat = (props.event.energy - 1) / 4;

        return true;
    });

    const layout = useRoom((s) => s.stage.layout);
    const [hintHidden, setHintHidden] = useState(readHintHidden);

    const toggleHint = useCallback(() => {
        setHintHidden((hidden) => {
            writeHintHidden(!hidden);

            return !hidden;
        });
    }, []);

    useDevKeys(toggleHint);

    useEffect(() => {
        const onResize = () =>
            useRoom
                .getState()
                .setStage(computeStage(window.innerWidth, window.innerHeight));

        onResize();
        window.addEventListener('resize', onResize);

        return () => window.removeEventListener('resize', onResize);
    }, []);

    return (
        <>
            <Head title={props.event.name} />
            <main className="rt-room" data-layout={layout}>
                <div className="rt-space" aria-hidden="true">
                    <Starfield />
                </div>
                <Scene />
                <Hud showHint={!hintHidden} onDismissHint={toggleHint} />
            </main>
        </>
    );
}
