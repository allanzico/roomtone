import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Starfield } from '@/room/Starfield';
import { store as tryDemo } from '@/routes/demo';
import '../../css/tokens.css';
import '@/room/room.css';

export default function Welcome() {
    const form = useForm<{ demo?: string }>({});

    const start = (event: FormEvent) => {
        event.preventDefault();
        form.post(tryDemo.url());
    };

    return (
        <>
            <Head title="Roomtone" />
            <main className="rt-room rt-landing">
                <div className="rt-space" aria-hidden="true">
                    <Starfield />
                </div>
                <div className="rt-landing-body">
                    <p className="rt-landing-mark">Roomtone</p>
                    <h1 className="rt-landing-title">
                        A party soundtrack built from what your guests love.
                    </h1>
                    <p className="rt-landing-lede">
                        Guests scan a code and name three things they love: a
                        show, a food, a city. The DJ turns those tastes into
                        music with Qloo, gives everyone a song, and tells the
                        room why each one is playing.
                    </p>
                    <form onSubmit={start} className="rt-landing-cta">
                        <button
                            type="submit"
                            className="rt-start-button"
                            disabled={form.processing}
                        >
                            {form.processing
                                ? 'Opening the room…'
                                : 'Try a demo party'}
                        </button>
                        <p className="rt-start-note">
                            Your own copy of a 16-guest rooftop party. Ask the
                            DJ for &ldquo;more Afrobeats&rdquo; or to
                            &ldquo;calm it down&rdquo;.
                        </p>
                        {form.errors.demo && (
                            <p className="rt-landing-error" role="alert">
                                {form.errors.demo}
                            </p>
                        )}
                    </form>
                </div>
            </main>
        </>
    );
}
