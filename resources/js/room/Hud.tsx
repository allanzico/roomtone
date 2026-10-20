import type { CSSProperties, ReactNode } from 'react';
import { energyCss } from './palette';
import { MAX_ENERGY, useRoom } from './store';

/** HTML overlay on top of the canvas. Each part subscribes only to the slice it shows. */
export function Hud({
    showHint,
    onDismissHint,
}: {
    showHint: boolean;
    onDismissHint: () => void;
}) {
    const energy = useRoom((s) => s.energy);

    return (
        <div
            className="rt-hud"
            style={{ '--rt-energy-color': energyCss(energy) } as CSSProperties}
        >
            <EventHeader>
                {showHint && <KeyHint onDismiss={onDismissHint} />}
            </EventHeader>
            <JoinCard />
            <div className="rt-bottom">
                <NowPlaying />
                <UpNext />
            </div>
        </div>
    );
}

function Dot({ color }: { color: string }) {
    return (
        <span
            className="rt-dot"
            style={{ backgroundColor: color, boxShadow: `0 0 14px ${color}` }}
            aria-hidden="true"
        />
    );
}

function EventHeader({ children }: { children: ReactNode }) {
    const name = useRoom((s) => s.event.name);
    const count = useRoom((s) => s.guests.length);
    const energy = useRoom((s) => s.energy);
    const arrival = useRoom((s) => {
        const last = s.guests[s.guests.length - 1];

        return last?.arrivedLive ? last : null;
    });

    return (
        <header className="rt-header">
            <h1 className="rt-event-name">{name}</h1>
            <div className="rt-meta">
                <p className="rt-count">
                    <span key={count} className="rt-count-number">
                        {count}
                    </span>{' '}
                    {count === 1 ? 'guest' : 'guests'} in the room
                </p>
                <div
                    className="rt-energy"
                    role="img"
                    aria-label={`Energy ${energy} of ${MAX_ENERGY}`}
                >
                    <span>Energy</span>
                    <span className="rt-energy-bars">
                        {Array.from({ length: MAX_ENERGY }, (_, i) => (
                            <span
                                key={i}
                                data-on={i < energy}
                                style={{ height: `${0.4 + i * 0.2}rem` }}
                            />
                        ))}
                    </span>
                </div>
            </div>
            {children}
            {arrival && (
                <p key={arrival.id} className="rt-arrival">
                    <Dot color={arrival.color} />
                    {arrival.name} just joined
                </p>
            )}
        </header>
    );
}

/** Title gets narrower and lighter when the room is calm, wider and heavier at peak. */
function titleAxes(energy: number): string {
    const wdth = 64 + (energy - 1) * 16;
    const wght = 360 + (energy - 1) * 115;

    return `'wdth' ${wdth}, 'wght' ${wght}`;
}

function NowPlaying() {
    const item = useRoom((s) => s.nowPlaying);
    const guest = useRoom((s) =>
        s.guests.find((g) => g.id === s.nowPlaying?.reasonGuestId),
    );
    const energy = useRoom((s) => s.energy);

    if (!item) {
        return (
            <section className="rt-panel rt-now">
                <p className="rt-reason">
                    The set starts when the first guest joins.
                </p>
            </section>
        );
    }

    const size =
        item.title.length > 30 ? 'l' : item.title.length > 16 ? 'm' : 's';

    return (
        <section className="rt-panel rt-now" aria-live="polite">
            <div key={item.id} className="rt-swap">
                {guest && (
                    <p className="rt-for">
                        <Dot color={guest.color} />
                        Playing for {guest.name}
                    </p>
                )}
                <h2
                    className="rt-title"
                    data-size={size}
                    style={{ fontVariationSettings: titleAxes(energy) }}
                >
                    {item.title}
                </h2>
                <p className="rt-artist">{item.artist}</p>
                <p className="rt-reason">{item.reasonText}</p>
            </div>
        </section>
    );
}

function UpNext() {
    const queue = useRoom((s) => s.queue);
    const guests = useRoom((s) => s.guests);

    return (
        <section className="rt-panel rt-next">
            <h3 className="rt-next-heading">Up next</h3>
            {queue.length === 0 ? (
                <p className="rt-next-empty">Nothing queued yet.</p>
            ) : (
                <ol className="rt-next-list">
                    {queue.slice(0, 3).map((item) => {
                        const guest = guests.find(
                            (g) => g.id === item.reasonGuestId,
                        );

                        return (
                            <li key={item.id} className="rt-next-item">
                                <span className="rt-next-track">
                                    <span className="rt-next-title">
                                        {item.title}
                                    </span>
                                    <span className="rt-next-artist">
                                        {item.artist}
                                    </span>
                                </span>
                                {guest && (
                                    <span className="rt-next-for">
                                        <Dot color={guest.color} />
                                        {guest.name}
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ol>
            )}
        </section>
    );
}

function JoinCard() {
    const code = useRoom((s) => s.event.code);

    return (
        <aside className="rt-join">
            {/* Placeholder until the real QR code arrives with the guest join flow. */}
            <div className="rt-qr" aria-hidden="true">
                <span className="rt-qr-finder" data-corner="tl" />
                <span className="rt-qr-finder" data-corner="tr" />
                <span className="rt-qr-finder" data-corner="bl" />
                <span className="rt-qr-code">{code}</span>
            </div>
            <div>
                <p className="rt-join-cta">Scan to join the room</p>
                <p className="rt-join-url">roomtone.app/e/{code}</p>
            </div>
        </aside>
    );
}

function KeyHint({ onDismiss }: { onDismiss: () => void }) {
    return (
        <div className="rt-hint" role="note">
            <span>
                <kbd>G</kbd> Add a guest
            </span>
            <span>
                <kbd>N</kbd> Next track
            </span>
            <span>
                <kbd>E</kbd> Change energy
            </span>
            <button
                type="button"
                onClick={onDismiss}
                aria-label="Hide shortcuts. Press H to show them again."
            >
                <span aria-hidden="true">×</span>
            </button>
        </div>
    );
}
