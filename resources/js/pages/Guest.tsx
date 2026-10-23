import { Head, router } from '@inertiajs/react';
import { useEchoPublic } from '@laravel/echo-react';
import { Starfield } from '@/room/Starfield';
import '../../css/tokens.css';
import '@/room/room.css';

type Props = {
    event: { code: string; name: string };
    guest: {
        name: string;
        loves: {
            text: string;
            status: 'pending' | 'resolved' | 'unresolved';
            /** What the DJ read it as, when that differs from the text. */
            as: string | null;
        }[];
    };
    song: {
        title: string;
        artist: string;
        reason: string;
        playing: boolean;
        ahead: number;
    } | null;
    reading: boolean;
};

const STATUS = {
    pending: 'Reading…',
    resolved: 'Got it',
    unresolved: "Couldn't read this one",
} as const;

/** The guest's page after joining: how the DJ read their loves, and their song once it is in the set. */
export default function Guest({ event, guest, song, reading }: Props) {
    useEchoPublic(`room.${event.code}`, 'RoomUpdated', () =>
        router.reload({ only: ['guest', 'song', 'reading'] }),
    );

    return (
        <>
            <Head title={event.name} />
            <main className="rt-room rt-guest">
                <div className="rt-space" aria-hidden="true">
                    <Starfield />
                </div>
                <div className="rt-guest-body" aria-live="polite">
                    <p className="rt-landing-mark">{event.name}</p>
                    <h1 className="rt-guest-title">
                        You&rsquo;re in, {guest.name}.
                    </h1>

                    <ul className="rt-guest-loves">
                        {guest.loves.map((love) => (
                            <li key={love.text} data-status={love.status}>
                                <span>{love.text}</span>
                                <span className="rt-guest-love-status">
                                    {love.status === 'resolved' && love.as
                                        ? `Got it: ${love.as}`
                                        : STATUS[love.status]}
                                </span>
                            </li>
                        ))}
                    </ul>

                    {song ? (
                        <section className="rt-guest-song">
                            <p className="rt-field-label">
                                {song.playing
                                    ? 'Playing now'
                                    : song.ahead <= 1
                                      ? 'Up next'
                                      : `Coming up, ${song.ahead} songs away`}
                            </p>
                            <p className="rt-guest-song-title">{song.title}</p>
                            <p className="rt-guest-lede">
                                {song.artist}, {song.reason}
                            </p>
                        </section>
                    ) : (
                        <p className="rt-guest-lede">
                            {reading
                                ? 'The DJ is reading your loves…'
                                : 'The DJ is fitting you into the set. Your song shows up here.'}
                        </p>
                    )}
                </div>
            </main>
        </>
    );
}
