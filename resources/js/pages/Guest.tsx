import { Head, router, usePoll } from '@inertiajs/react';
import { useEchoPublic } from '@laravel/echo-react';
import { useState } from 'react';
import { GuestForm } from '@/guest/GuestForm';
import { PartyEnded } from '@/guest/PartyEnded';
import { Loading } from '@/room/Loading';
import { Starfield } from '@/room/Starfield';
import { update as updateMe } from '@/routes/events/me';
import '../../css/tokens.css';
import '@/room/room.css';

type Props = {
    event: { code: string; name: string; ended: boolean };
    guest: {
        name: string;
        loves: {
            text: string;
            status: 'pending' | 'resolved' | 'unresolved';
            /** What the DJ read it as, when that differs from the text. */
            as: string | null;
            /** Read as a place: that place's music stands in for it (a food read as its city). */
            place: boolean;
            /** Read through Qloo's taste for a dish or cuisine. */
            dish: boolean;
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
    unresolved: 'No music link for this one, your other loves lead',
} as const;

/** The guest's page after joining: how the DJ read their loves, and their song once it is in the set. */
export default function Guest({ event, guest, song, reading }: Props) {
    useEchoPublic(`room.${event.code}`, 'RoomUpdated', () =>
        router.reload({ only: ['event', 'guest', 'song', 'reading'] }),
    );
    // The live connection can drop (a phone asleep, a flaky network): check now and then, so the song shown here
    // follows what plays. Inertia pauses polling while the page is hidden.
    usePoll(15000, { only: ['event', 'guest', 'song', 'reading'] });
    const [editing, setEditing] = useState(false);

    if (event.ended) {
        return <PartyEnded name={event.name} />;
    }

    if (editing) {
        return (
            <>
                <Head title={event.name} />
                <main className="rt-room rt-guest">
                    <div className="rt-space" aria-hidden="true">
                        <Starfield />
                    </div>
                    <GuestForm
                        initial={{
                            name: guest.name,
                            loves: guest.loves.map((love) => love.text),
                        }}
                        action={{
                            url: updateMe.url(event.code),
                            method: 'put',
                        }}
                        submit="Save"
                        busy="Saving…"
                        onSaved={() => setEditing(false)}
                        after={
                            <button
                                type="button"
                                className="rt-guest-change"
                                onClick={() => setEditing(false)}
                            >
                                Cancel
                            </button>
                        }
                    >
                        <p className="rt-landing-mark">{event.name}</p>
                        <h1 className="rt-guest-title">
                            Change what you told the DJ.
                        </h1>
                        <p className="rt-guest-lede">
                            Only what you change is read again. Your song
                            follows when the set is rebuilt.
                        </p>
                    </GuestForm>
                </main>
            </>
        );
    }

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
                                    {love.status === 'pending' && (
                                        <span
                                            className="rt-spinner"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {love.status === 'resolved' && love.dish
                                        ? `Got it: read as the taste of ${(love.as ?? love.text).toLowerCase()}`
                                        : love.status === 'resolved' && love.as
                                          ? love.place
                                              ? `Got it: music from ${love.as}`
                                              : `Got it: ${love.as}`
                                          : STATUS[love.status]}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <button
                        type="button"
                        className="rt-guest-change"
                        onClick={() => setEditing(true)}
                    >
                        Change my name or loves
                    </button>

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
                        <Loading>
                            {reading
                                ? 'The DJ is reading your loves…'
                                : 'The DJ is fitting you into the set. Your song shows up here.'}
                        </Loading>
                    )}
                </div>
            </main>
        </>
    );
}
