import { Head } from '@inertiajs/react';
import { GuestForm } from '@/guest/GuestForm';
import { PartyEnded } from '@/guest/PartyEnded';
import { Starfield } from '@/room/Starfield';
import { store as joinRoom } from '@/routes/events/guests';
import '../../css/tokens.css';
import '@/room/room.css';

type Props = { event: { code: string; name: string; ended: boolean } };

/** The guest's phone: name and three loves. No app, no login. */
export default function Join({ event }: Props) {
    if (event.ended) {
        return <PartyEnded name={event.name} />;
    }

    return (
        <>
            <Head title={`Join ${event.name}`} />
            <main className="rt-room rt-guest">
                <div className="rt-space" aria-hidden="true">
                    <Starfield />
                </div>
                <GuestForm
                    initial={{ name: '', loves: ['', '', ''] }}
                    action={{ url: joinRoom.url(event.code), method: 'post' }}
                    submit="Join the room"
                    busy="Joining…"
                >
                    <p className="rt-landing-mark">{event.name}</p>
                    <h1 className="rt-guest-title">
                        Tell the DJ three things you love.
                    </h1>
                    <p className="rt-guest-lede">
                        Anything counts: a show, a food, a city, an artist.
                        You&rsquo;ll get a song because of it.
                    </p>
                </GuestForm>
            </main>
        </>
    );
}
