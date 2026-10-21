import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Starfield } from '@/room/Starfield';
import { store as joinRoom } from '@/routes/events/guests';
import '../../css/tokens.css';
import '@/room/room.css';

type Props = { event: { code: string; name: string } };

const HINTS = ['A show you love', 'A food you love', 'A place you love'];

/** The guest's phone: name and three loves. No app, no login. */
export default function Join({ event }: Props) {
    const form = useForm({ name: '', loves: ['', '', ''] });
    const errors = form.errors;

    const setLove = (i: number, value: string) =>
        form.setData(
            'loves',
            form.data.loves.map((love, j) => (j === i ? value : love)),
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(joinRoom.url(event.code));
    };

    return (
        <>
            <Head title={`Join ${event.name}`} />
            <main className="rt-room rt-guest">
                <div className="rt-space" aria-hidden="true">
                    <Starfield />
                </div>
                <form className="rt-guest-body" onSubmit={submit} noValidate>
                    <p className="rt-landing-mark">{event.name}</p>
                    <h1 className="rt-guest-title">
                        Tell the DJ three things you love.
                    </h1>
                    <p className="rt-guest-lede">
                        Anything counts: a show, a food, a city, an artist.
                        You&rsquo;ll get a song because of it.
                    </p>

                    <label className="rt-field">
                        <span className="rt-field-label">Your name</span>
                        <input
                            className="rt-ask-input"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={40}
                            autoComplete="given-name"
                            required
                        />
                        {errors.name && (
                            <span className="rt-field-error">
                                {errors.name}
                            </span>
                        )}
                    </label>

                    {HINTS.map((hint, i) => (
                        <label key={hint} className="rt-field">
                            <span className="rt-sr">Love {i + 1}</span>
                            <input
                                className="rt-ask-input"
                                value={form.data.loves[i]}
                                onChange={(e) => setLove(i, e.target.value)}
                                placeholder={hint}
                                maxLength={80}
                                autoComplete="off"
                                required
                            />
                            {errors[`loves.${i}`] && (
                                <span className="rt-field-error">
                                    {errors[`loves.${i}`]}
                                </span>
                            )}
                        </label>
                    ))}

                    <button
                        type="submit"
                        className="rt-start-button rt-guest-cta"
                        disabled={form.processing}
                    >
                        {form.processing ? 'Joining…' : 'Join the room'}
                    </button>
                </form>
            </main>
        </>
    );
}
