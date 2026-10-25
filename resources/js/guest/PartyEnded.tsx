import { Head } from '@inertiajs/react';
import { Starfield } from '@/room/Starfield';
import '../../css/tokens.css';
import '@/room/room.css';

/** Shown to guests once the host has ended the party: nothing can be joined or changed any more. */
export function PartyEnded({ name }: { name: string }) {
    return (
        <>
            <Head title={name} />
            <main className="rt-room rt-guest">
                <div className="rt-space" aria-hidden="true">
                    <Starfield />
                </div>
                <div className="rt-guest-body" aria-live="polite">
                    <p className="rt-landing-mark">{name}</p>
                    <h1 className="rt-guest-title">This party has ended.</h1>
                    <p className="rt-guest-lede">
                        Thanks for adding your taste to the room. Nothing can be
                        joined or changed any more.
                    </p>
                </div>
            </main>
        </>
    );
}
