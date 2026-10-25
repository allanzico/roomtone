import { Head, Link } from '@inertiajs/react';
import { Starfield } from '@/room/Starfield';
import { setup } from '@/routes';
import '../../css/tokens.css';
import '@/room/room.css';

export default function Welcome() {
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
                    <div className="rt-landing-cta">
                        <Link href={setup.url()} className="rt-start-button">
                            Start your room
                        </Link>
                        <p className="rt-start-note">
                            Talk to the DJ, put the screen up, and let your
                            guests scan in.
                        </p>
                    </div>
                </div>
            </main>
        </>
    );
}
