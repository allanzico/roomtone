import { router } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { setup } from '@/routes';
import { AskDj } from './AskDj';
import { Loading } from './Loading';
import { endParty } from './hostSync';
import { clusterColor, guestColor, listOf, mixBar, mixOf } from './genres';
import type { MixSegment } from './genres';
import { reducedMotion } from './stage';
import { useRoom } from './store';
import type { Guest, SetItem } from './types';
import {
    hasVideo,
    nowSlotWidth,
    StartParty,
    startPlayback,
    startPosition,
    videoRect,
    VideoTile,
    autoStart,
    controlsPosition,
    VolumeControl,
} from './VideoTile';

/**
 * The HTML layer over the pool. Everything meaningful lives here (the canvas is
 * aria-hidden). TV: one 1920×1080 stage scaled to fit. Phone: a column in CSS px.
 */
export function Hud({
    showHint,
    onDismissHint,
}: {
    showHint: boolean;
    onDismissHint: () => void;
}) {
    const stage = useRoom((s) => s.stage);
    const playable = useRoom(hasVideo);
    const started = useRoom((s) => s.started);

    // Play as soon as the first song with a video arrives, when the browser allows it (see autoStart).
    useEffect(() => {
        if (playable && !started) {
            autoStart();
        }
    }, [playable, started]);

    return (
        <>
            {stage.layout === 'tv' ? (
                <div
                    className="rt-stage"
                    style={{
                        transform: `translate(${stage.left}px, ${stage.top}px) scale(${stage.scale})`,
                    }}
                >
                    <TvHud showHint={showHint} onDismissHint={onDismissHint} />
                </div>
            ) : (
                <PhoneHud poolBottom={stage.band.y + stage.band.h} />
            )}
            {playable && <VideoTile style={videoRect(stage)} />}
            {playable && !started && (
                <StartParty style={startPosition(stage)} />
            )}
            {playable && started && stage.layout === 'tv' && (
                <TvControls style={controlsPosition(stage)} />
            )}
            <Arrivals />
        </>
    );
}

function TvHud({
    showHint,
    onDismissHint,
}: {
    showHint: boolean;
    onDismissHint: () => void;
}) {
    const empty = useRoom((s) => s.guests.length === 0);
    const stage = useRoom((s) => s.stage);
    const playable = useRoom(hasVideo);

    const host = useRoom((s) => s.hostKey !== null);
    const askOpen = useRoom((s) => s.askOpen);
    const asking = useRoom((s) => s.latestRequest?.status === 'pending');
    const showAsk = host && (askOpen || asking);

    return (
        <>
            <Header />
            {showAsk ? (
                <div className="rt-ask-tv">
                    <AskDj autoFocus={askOpen} />
                </div>
            ) : (
                showHint && <KeyHint onDismiss={onDismissHint} />
            )}
            <Join large={empty} />
            {empty ? (
                <p className="rt-warming">The room warms up as people join</p>
            ) : (
                <>
                    <GuestStream />
                    <div
                        className="rt-now-slot"
                        style={
                            playable
                                ? { width: nowSlotWidth(stage) }
                                : undefined
                        }
                    >
                        <NowPlaying />
                    </div>
                    <UpNext />
                </>
            )}
        </>
    );
}

function PhoneHud({ poolBottom }: { poolBottom: number }) {
    const empty = useRoom((s) => s.guests.length === 0);
    const host = useRoom((s) => s.hostKey !== null);

    return (
        <div className="rt-phone" data-host={host}>
            <Header />
            <div className="rt-phone-body" style={{ top: poolBottom }}>
                {empty ? (
                    <p className="rt-warming">
                        The room warms up as people join
                    </p>
                ) : (
                    <>
                        <JustIn />
                        <NowPlaying />
                        <UpNext />
                    </>
                )}
            </div>
            {host && <AskDj />}
            <PlaybackBar />
        </div>
    );
}

/* ---------- Shared data ---------- */

function useMix(): MixSegment[] {
    const clusters = useRoom((s) => s.clusters);
    const nowPlaying = useRoom((s) => s.nowPlaying);
    const queue = useRoom((s) => s.queue);

    return useMemo(
        () => mixOf(clusters, nowPlaying, queue),
        [clusters, nowPlaying, queue],
    );
}

function Dot({
    color,
    className = 'rt-dot',
}: {
    color: string;
    className?: string;
}) {
    return (
        <span
            className={className}
            style={{ '--rt-dot': color } as CSSProperties}
            aria-hidden="true"
        />
    );
}

/* ---------- Header ---------- */

function Header() {
    const name = useRoom((s) => s.event.name);
    const reconnecting = useRoom((s) => s.reconnecting);
    const layout = useRoom((s) => s.stage.layout);
    const host = useRoom((s) => s.hostKey !== null);

    return (
        <header className="rt-header">
            <div className="rt-header-main">
                <h1 className="rt-event-name">{name}</h1>
                <GenreDots />
                {layout === 'phone' && <InviteButton />}
                {host && <EndParty />}
            </div>
            {reconnecting && <p className="rt-reconnecting">Reconnecting…</p>}
        </header>
    );
}

/** Host link only: end the party (guests' pages say so and stop taking changes), then go to the setup chat. */
function EndParty() {
    const end = async () => {
        if (!window.confirm('End this party and set up a new one?')) {
            return;
        }

        if (await endParty()) {
            router.visit(setup.url());
        } else {
            window.alert("Couldn't end the party. Try again.");
        }
    };

    return (
        <button type="button" className="rt-end-party" onClick={end}>
            End party
        </button>
    );
}

function GenreDots() {
    const mix = useMix().slice(0, 5);

    if (mix.length === 0) {
        return null;
    }

    const [first, ...rest] = mix;
    const label = first.playing
        ? `Genres in the set: ${first.label} playing now${rest.length ? `, then ${listOf(rest.map((s) => s.label))}` : ''}`
        : `Genres in the set: ${listOf(mix.map((s) => s.label))}`;

    return (
        <span className="rt-genres" role="img" aria-label={label}>
            {mix.map((segment) => (
                <span
                    key={segment.key}
                    className="rt-genre-dot"
                    data-playing={segment.playing}
                    style={{ '--rt-dot': segment.color } as CSSProperties}
                />
            ))}
        </span>
    );
}

/* ---------- Join ---------- */

function Join({ large }: { large: boolean }) {
    const code = useRoom((s) => s.event.code);
    const join = useRoom((s) => s.join);

    return (
        <aside
            className="rt-join"
            data-large={large}
            aria-label={`Join the room at ${join.host}, code ${code}`}
        >
            <div className="rt-join-text">
                <p className="rt-join-url">{join.host}</p>
                <p className="rt-code">{code}</p>
                <CopyLink url={join.url} />
            </div>
            {/* Drawn by the server from the join link; trusted markup. */}
            <div
                className="rt-qr"
                role="img"
                aria-label="QR code to join"
                dangerouslySetInnerHTML={{ __html: join.qr }}
            />
        </aside>
    );
}

/** The guest link to paste into a group chat. */
function CopyLink({ url }: { url: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // The browser refused (plain HTTP on a network address, or the page lost focus): show it to copy by hand.
            window.prompt('Copy the link to the room', url);
        }
    };

    return (
        <button
            type="button"
            className="rt-copy-link"
            onClick={() => void copy()}
            aria-live="polite"
        >
            {copied ? 'Link copied' : 'Copy link'}
        </button>
    );
}

function joinUrl(code: string): string {
    return `${window.location.origin}/e/${code}`;
}

function InviteButton() {
    const code = useRoom((s) => s.event.code);
    const [copied, setCopied] = useState(false);

    const invite = async () => {
        const url = joinUrl(code);

        if (typeof navigator.share === 'function') {
            try {
                await navigator.share({ url });

                return;
            } catch (error) {
                // Closing the share sheet is not an error worth falling back from.
                if (
                    error instanceof DOMException &&
                    error.name === 'AbortError'
                ) {
                    return;
                }
            }
        }

        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // No clipboard either (insecure origin); the code on the button still works.
        }
    };

    return (
        <button
            type="button"
            className="rt-invite"
            onClick={() => void invite()}
        >
            <span className="rt-code">{code}</span>
            <span className="rt-invite-action" aria-live="polite">
                {copied ? 'Link copied' : '· Invite'}
            </span>
        </button>
    );
}

/* ---------- Now playing ---------- */

/** Title gets narrower and lighter when the room is calm, wider and heavier at peak. Energy 3 = the design's 700 / wdth 92. */
function titleAxes(energy: number): CSSProperties {
    return {
        fontWeight: 600 + (energy - 1) * 50,
        fontVariationSettings: `'wdth' ${84 + (energy - 1) * 4}, 'opsz' 96`,
    };
}

function NowPlaying() {
    const item = useRoom((s) => s.nowPlaying);
    const guests = useRoom((s) => s.guests);
    const playable = useRoom(hasVideo);
    const paused = useRoom((s) => s.youtubePaused);

    // Keep the previous track on screen for its 300ms fade-out.
    const [shown, setShown] = useState(item);
    const [leaving, setLeaving] = useState<SetItem | null>(null);

    if (item !== shown) {
        setLeaving(reducedMotion ? null : shown);
        setShown(item);
    }

    const sentence = item
        ? `Now playing ${item.title} by ${item.artist}, ${item.reasonText}`
        : '';

    return (
        <section className="rt-now" aria-live="polite" aria-atomic="true">
            <p className="rt-sr">{sentence}</p>
            {item && (
                <div className="rt-now-text" aria-hidden="true">
                    {leaving && (
                        <div
                            key={leaving.id}
                            className="rt-now-track rt-now-leaving"
                            onAnimationEnd={() => setLeaving(null)}
                        >
                            <TrackText
                                item={leaving}
                                guest={guests.find(
                                    (g) => g.id === leaving.reasonGuestId,
                                )}
                            />
                        </div>
                    )}
                    <div key={item.id} className="rt-now-track rt-now-entering">
                        <TrackText
                            item={item}
                            guest={guests.find(
                                (g) => g.id === item.reasonGuestId,
                            )}
                        />
                    </div>
                </div>
            )}
            {item && <MixBar />}
            {!item && guests.length > 0 && (
                <Loading>The DJ is picking the first songs…</Loading>
            )}
            {item && !playable && (
                <p className="rt-notice" role="status">
                    {paused
                        ? "YouTube's daily search limit is reached, so new songs can't be looked up until it resets. Songs already found still play."
                        : 'No video was found for this song.'}
                </p>
            )}
        </section>
    );
}

function TrackText({
    item,
    guest,
}: {
    item: SetItem;
    guest: Guest | undefined;
}) {
    return (
        <>
            <Title text={item.title} />
            <p className="rt-reason">
                {item.artist},{' '}
                <Reason text={item.reasonText} name={guest?.name} />
            </p>
        </>
    );
}

/** "because Maria loves Ozark" with the guest's name in white. */
function Reason({ text, name }: { text: string; name: string | undefined }) {
    const at = name ? text.indexOf(name) : -1;

    if (!name || at < 0) {
        return text;
    }

    return (
        <>
            {text.slice(0, at)}
            <strong>{name}</strong>
            {text.slice(at + name.length)}
        </>
    );
}

/** Shrinks to fit down to its minimum size, then wraps to two lines, then ellipsis. */
function Title({ text }: { text: string }) {
    const ref = useRef<HTMLHeadingElement>(null);
    const energy = useRoom((s) => s.energy);
    const layout = useRoom((s) => s.stage.layout);
    const width = useRoom((s) => s.stage.width);
    // The TV slot narrows on small screens to stay clear of the video tile.
    const scale = useRoom((s) => s.stage.scale);

    useLayoutEffect(() => {
        const el = ref.current;

        if (!el) {
            return;
        }

        const [max, min] = layout === 'tv' ? [120, 72] : [52, 32];
        let cancelled = false;

        const fit = () => {
            if (cancelled) {
                return;
            }

            el.dataset.wrap = 'false';
            el.style.fontSize = `${max}px`;
            const available = el.clientWidth;
            const needed = el.scrollWidth;
            const size =
                needed > available
                    ? Math.max(min, Math.floor((max * available) / needed))
                    : max;
            el.style.fontSize = `${size}px`;
            el.dataset.wrap = String(el.scrollWidth > available + 1);
        };

        fit();
        void document.fonts.ready.then(fit);

        return () => {
            cancelled = true;
        };
    }, [text, energy, layout, width, scale]);

    return (
        <h2 ref={ref} className="rt-title" style={titleAxes(energy)}>
            {text}
        </h2>
    );
}

function MixBar() {
    const segments = mixBar(useMix());
    const label = `The set by genre: ${segments
        .map(
            (s) =>
                `${s.label}, ${s.count} ${s.count === 1 ? 'track' : 'tracks'}${s.playing ? ', playing now' : ''}`,
        )
        .join('; ')}`;

    return (
        <div className="rt-mix" role="img" aria-label={label}>
            {segments.map((s) => (
                <div
                    key={s.key}
                    className="rt-mix-segment"
                    data-playing={s.playing}
                    style={
                        {
                            flexGrow: s.count,
                            '--rt-dot': s.color,
                        } as CSSProperties
                    }
                >
                    <span className="rt-mix-line" />
                    <span className="rt-mix-label">
                        {s.playing ? `${s.label}, now` : s.label}
                    </span>
                </div>
            ))}
        </div>
    );
}

/* ---------- Up next ---------- */

function UpNext() {
    const queue = useRoom((s) => s.queue);
    const guests = useRoom((s) => s.guests);
    const clusters = useRoom((s) => s.clusters);

    if (queue.length === 0) {
        return null;
    }

    return (
        <section className="rt-next" aria-labelledby="rt-next-heading">
            <h3 id="rt-next-heading" className="rt-next-heading">
                Up next
            </h3>
            <ol className="rt-next-list">
                {queue.slice(0, 3).map((item) => {
                    const guest = guests.find(
                        (g) => g.id === item.reasonGuestId,
                    );

                    return (
                        <li key={item.id} className="rt-next-item">
                            <Dot
                                color={clusterColor(item.clusterId, clusters)}
                            />
                            <span className="rt-next-title" title={item.title}>
                                {item.title}
                            </span>
                            {guest && (
                                <span className="rt-next-guest">
                                    <span className="rt-sr">for </span>
                                    {guest.name.split(' ')[0]}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}

/* ---------- Guests ---------- */

/** TV: everyone who joined, newest at the bottom; the top fades out. */
function GuestStream() {
    const guests = useRoom((s) => s.guests);
    const clusters = useRoom((s) => s.clusters);
    const focusId = useRoom((s) => s.nowPlaying?.reasonGuestId ?? null);
    const last = guests[guests.length - 1];
    // Re-keyed on each live arrival so the list replays its slide-up.
    const arrivalKey = last?.arrivedLive ? last.id : 'initial';

    return (
        <section className="rt-stream" aria-label="Guests">
            <ol
                key={arrivalKey}
                className="rt-stream-list"
                data-arrival={last?.arrivedLive === true}
            >
                {guests.slice(-8).map((guest) => (
                    <li
                        key={guest.id}
                        className="rt-stream-row"
                        data-focus={guest.id === focusId}
                    >
                        <span className="rt-stream-text">
                            <span className="rt-stream-name">{guest.name}</span>
                            {guest.loves[0] && (
                                <span className="rt-stream-loves">
                                    loves {guest.loves[0]}
                                </span>
                            )}
                        </span>
                        <Dot color={guestColor(guest, clusters)} />
                    </li>
                ))}
            </ol>
        </section>
    );
}

/** Phone: the last three to join, newest first. */
function JustIn() {
    const guests = useRoom((s) => s.guests);
    const clusters = useRoom((s) => s.clusters);
    const focusId = useRoom((s) => s.nowPlaying?.reasonGuestId ?? null);

    return (
        <p className="rt-just-in">
            <span className="rt-just-in-label">Just in</span>
            {guests
                .slice(-3)
                .reverse()
                .map((guest) => (
                    <span
                        key={guest.id}
                        className="rt-just-in-guest"
                        data-focus={guest.id === focusId}
                    >
                        <Dot color={guestColor(guest, clusters)} />
                        {guest.name}
                    </span>
                ))}
        </p>
    );
}

/** Polite announcement for each live arrival. */
function Arrivals() {
    const arrival = useRoom((s) => {
        const last = s.guests[s.guests.length - 1];

        return last?.arrivedLive ? last : null;
    });

    return (
        <p className="rt-sr" aria-live="polite">
            {arrival ? `${arrival.name} joined` : ''}
        </p>
    );
}

/* ---------- Playback ---------- */

/** TV: play/pause, next and volume under the video. */
function TvControls({ style }: { style: CSSProperties }) {
    const paused = useRoom((s) => s.paused);
    const togglePaused = useRoom((s) => s.togglePaused);
    const nextTrack = useRoom((s) => s.nextTrack);
    const canSkip = useRoom((s) => s.queue.length > 0);

    return (
        <nav className="rt-tv-controls" style={style} aria-label="Playback">
            <button
                type="button"
                className="rt-volume-mute"
                aria-label={paused ? 'Play' : 'Pause'}
                onClick={togglePaused}
            >
                {paused ? <PlayIcon /> : <PauseIcon />}
            </button>
            <button
                type="button"
                className="rt-volume-mute"
                aria-label="Next track"
                onClick={nextTrack}
                disabled={!canSkip}
            >
                <NextIcon />
            </button>
            <VolumeControl />
        </nav>
    );
}

function NextIcon() {
    return (
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
            <path d="M6 5l9 7-9 7z" fill="currentColor" />
            <rect
                x="16"
                y="5"
                width="2.5"
                height="14"
                rx="1"
                fill="currentColor"
            />
        </svg>
    );
}

function PlaybackBar() {
    const started = useRoom((s) => s.started);
    const paused = useRoom((s) => s.paused) || !started;
    const togglePaused = useRoom((s) => s.togglePaused);
    const nextTrack = useRoom((s) => s.nextTrack);
    const canSkip = useRoom((s) => s.queue.length > 0);

    return (
        <nav className="rt-playback" aria-label="Playback">
            <VolumeControl />
            <button
                type="button"
                className="rt-pause"
                aria-label={paused ? 'Play' : 'Pause'}
                onClick={started ? togglePaused : startPlayback}
            >
                {paused ? <PlayIcon /> : <PauseIcon />}
            </button>
            <button
                type="button"
                className="rt-skip"
                onClick={nextTrack}
                disabled={!canSkip}
            >
                Skip
            </button>
        </nav>
    );
}

function PauseIcon() {
    return (
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
            <rect
                x="6"
                y="4"
                width="4"
                height="16"
                rx="1.5"
                fill="currentColor"
            />
            <rect
                x="14"
                y="4"
                width="4"
                height="16"
                rx="1.5"
                fill="currentColor"
            />
        </svg>
    );
}

function PlayIcon() {
    return (
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
            <path
                d="M7 4.5v15a1 1 0 0 0 1.5.86l12-7.5a1 1 0 0 0 0-1.72l-12-7.5A1 1 0 0 0 7 4.5Z"
                fill="currentColor"
            />
        </svg>
    );
}

/* ---------- Dev ---------- */

function KeyHint({ onDismiss }: { onDismiss: () => void }): ReactNode {
    const host = useRoom((s) => s.hostKey !== null);
    const setAskOpen = useRoom((s) => s.setAskOpen);

    return (
        <div className="rt-hint" role="note">
            {host && (
                <button
                    type="button"
                    className="rt-hint-ask"
                    onClick={() => setAskOpen(true)}
                >
                    <kbd>/</kbd> ask the DJ
                </button>
            )}
            <span>
                <kbd>G</kbd> add a guest
            </span>
            <span>
                <kbd>N</kbd> next track
            </span>
            <span>
                <kbd>E</kbd> energy
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
