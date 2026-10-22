import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { CSSProperties, FormEvent, KeyboardEvent } from 'react';
import { xsrfToken } from '@/lib/xsrf';
import { Starfield } from '@/room/Starfield';
import { store as openRoom, turn as setupTurn } from '@/routes/setup';
import { PlanCurve } from '@/setup/PlanCurve';
import {
    clockMinutes,
    clockText,
    positions,
    rangeText,
    snap,
} from '@/setup/time';
import type { Draft, Message, PlanPoint } from '@/setup/types';
import '../../css/tokens.css';
import '@/room/room.css';

const FIRST_QUESTION = 'Hey. What are we playing for?';
const LOST = 'I lost the thread. Say that again?';
const MAX_LENGTH = 500;

/** Quick starts: tapping one sends it as the host's message. */
const STARTS = [
    { text: 'A party', color: 'var(--rt-genre-1)' },
    { text: 'A long drive', color: 'var(--rt-genre-3)' },
    { text: 'A family road trip', color: 'var(--rt-genre-4)' },
    { text: 'Dinner with friends', color: 'var(--rt-genre-2)' },
    { text: 'Cooking at home', color: 'var(--rt-genre-5)' },
    { text: 'A workout', color: 'var(--rt-genre-6)' },
];

const OCCASIONS: Record<string, string> = {
    party: 'Party',
    birthday: 'Birthday',
    drive: 'Drive',
    'road trip': 'Road trip',
    dinner: 'Dinner',
    cooking: 'Cooking',
    workout: 'Workout',
    other: 'Something else',
};

const EMPTY: Draft = {
    occasion: null,
    name: null,
    timeline: 'clock',
    minutes: null,
    points: [],
};

/** The DJ's dot takes the occasion's colour: drives blue, workouts violet, everything else ember. */
function djColour(occasion: string | null): string {
    if (occasion === 'drive' || occasion === 'road trip') {
        return 'var(--rt-genre-3)';
    }

    return occasion === 'workout' ? 'var(--rt-genre-6)' : 'var(--rt-genre-1)';
}

function localNow(): string {
    return new Intl.DateTimeFormat('en-GB', {
        weekday: 'long',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(new Date());
}

/**
 * Setup: the host tells the DJ what the music is for, in their own words; the DJ proposes an energy plan the host
 * can drag, then opens the room.
 */
export default function Setup() {
    const [messages, setMessages] = useState<Message[]>([]);
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [ready, setReady] = useState(false);
    // Taps for the DJ's latest line: answers to its question, or tweaks to its plan.
    const [choices, setChoices] = useState<string[]>([]);
    const [thinking, setThinking] = useState(false);
    const [text, setText] = useState('');
    const [opening, setOpening] = useState(false);
    // Phones get the design's shorter first placeholder; decided after mount so server rendering matches.
    const [narrow, setNarrow] = useState(false);

    useEffect(
        () => setNarrow(window.matchMedia('(max-width: 640px)').matches),
        [],
    );
    const composer = useRef<HTMLTextAreaElement>(null);
    const dock = useRef<HTMLDivElement>(null);
    const [dockHeight, setDockHeight] = useState(0);
    const firstOpen = messages.length === 0;

    // The dock (quick replies, the plan, its button, the composer) floats at the bottom; the chat keeps that much
    // room under its last line.
    useEffect(() => {
        const box = dock.current;

        if (!box) {
            return;
        }

        const observer = new ResizeObserver(() =>
            setDockHeight(box.offsetHeight),
        );
        observer.observe(box);

        return () => observer.disconnect();
    }, []);

    // After each DJ reply (or when the dock changes height), focus stays in the composer and the chat follows its
    // newest line down to just above the dock, never past the top of a long reply.
    useEffect(() => {
        if (!thinking) {
            composer.current?.focus({ preventScroll: true });
        }

        const bottom =
            document.documentElement.scrollHeight - window.innerHeight;
        const lines = document.querySelectorAll('.rt-dj-line');
        const newest = lines[lines.length - 1]?.getBoundingClientRect();
        window.scrollTo({
            top: newest
                ? Math.min(bottom, window.scrollY + newest.top - 32)
                : bottom,
            behavior: 'smooth',
        });
    }, [thinking, messages.length, ready, dockHeight]);

    const say = (dj: string) =>
        setMessages((all) => [...all, { from: 'dj', text: dj }]);

    const send = async (raw: string) => {
        const message = raw.trim().slice(0, MAX_LENGTH);

        if (message === '' || thinking) {
            return;
        }

        const history: Message[] = [
            { from: 'dj', text: FIRST_QUESTION },
            ...messages,
            { from: 'host', text: message },
        ];
        setMessages((all) => [...all, { from: 'host', text: message }]);
        setChoices([]);
        setText('');
        setThinking(true);

        try {
            const response = await fetch(setupTurn.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({
                    messages: history.slice(-24),
                    draft,
                    now: localNow(),
                }),
            });
            const body = (await response.json()) as {
                reply?: string;
                choices?: string[];
                draft?: Draft;
                ready?: boolean;
            };

            if (!response.ok || !body.draft) {
                throw new Error('turn failed');
            }

            setDraft(body.draft);
            setReady(body.ready === true);
            setChoices(body.choices ?? []);
            say(body.reply ?? LOST);
        } catch {
            // The host's message stays; nothing is lost.
            say(LOST);
        } finally {
            setThinking(false);
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        void send(text);
    };

    const enterSends = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            void send(text);
        }
    };

    const open = () => {
        setOpening(true);
        router.post(
            openRoom.url(),
            { draft, tz: Intl.DateTimeFormat().resolvedOptions().timeZone },
            {
                onError: () => setOpening(false),
                onFinish: () => setOpening(false),
            },
        );
    };

    const colour = djColour(draft.occasion);
    const trip = draft.timeline === 'trip';

    return (
        <>
            <Head title="Set up a room" />
            <main
                className="rt-room rt-setup"
                style={
                    {
                        '--rt-dj': colour,
                        '--rt-dock-h': `${dockHeight}px`,
                    } as CSSProperties
                }
            >
                <div className="rt-space" aria-hidden="true">
                    <Starfield />
                </div>
                <p className="rt-wordmark">roomtone</p>

                <div className="rt-setup-col">
                    {firstOpen ? (
                        <section className="rt-setup-first">
                            <h1 className="rt-dj-first">
                                <span
                                    className="rt-setup-dot rt-setup-dot-big"
                                    aria-hidden="true"
                                />
                                {FIRST_QUESTION}
                            </h1>
                            <p className="rt-setup-sub">
                                Tell me in your own words. I&rsquo;ll shape the
                                music around it, and everyone with you can add
                                to it.
                            </p>
                            <ul className="rt-starts">
                                {STARTS.map((start) => (
                                    <li key={start.text}>
                                        <button
                                            type="button"
                                            className="rt-start-chip"
                                            style={
                                                {
                                                    '--rt-dot': start.color,
                                                } as CSSProperties
                                            }
                                            onClick={() =>
                                                void send(start.text)
                                            }
                                        >
                                            <span
                                                className="rt-dot"
                                                aria-hidden="true"
                                            />
                                            {start.text}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : (
                        <ol className="rt-chat" aria-live="polite">
                            <DjLine text={FIRST_QUESTION} />
                            {messages.map((m, i) =>
                                m.from === 'dj' ? (
                                    <DjLine key={i} text={m.text} />
                                ) : (
                                    <li key={i} className="rt-you">
                                        <span className="rt-sr">You: </span>
                                        {m.text}
                                    </li>
                                ),
                            )}
                            {thinking && (
                                <li className="rt-dj-line">
                                    <span
                                        className="rt-setup-dot"
                                        aria-hidden="true"
                                    />
                                    <span
                                        className="rt-dj-thinking"
                                        aria-label="The DJ is thinking"
                                    >
                                        <span />
                                        <span />
                                        <span />
                                    </span>
                                </li>
                            )}
                        </ol>
                    )}
                </div>

                <div className="rt-setup-dock" ref={dock}>
                    {!firstOpen && !thinking && choices.length > 0 && (
                        <ul className="rt-replies" aria-label="Quick replies">
                            {choices.map((choice) => (
                                <li key={choice}>
                                    <button
                                        type="button"
                                        className="rt-start-chip rt-reply"
                                        onClick={() => void send(choice)}
                                    >
                                        <span
                                            className="rt-dot"
                                            aria-hidden="true"
                                        />
                                        {choice}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    {ready && !thinking && (
                        <section className="rt-plan" aria-label="The plan">
                            <PlanCurve
                                draft={draft}
                                compact={narrow}
                                onChange={(points: PlanPoint[]) =>
                                    setDraft({ ...draft, points })
                                }
                            />
                            <div className="rt-plan-row">
                                <Facts
                                    draft={draft}
                                    onChange={setDraft}
                                    onAck={say}
                                />
                                <div className="rt-open">
                                    <button
                                        type="button"
                                        className="rt-start-button rt-open-cta"
                                        onClick={open}
                                        disabled={opening}
                                    >
                                        {opening
                                            ? 'Opening…'
                                            : trip
                                              ? 'Start the drive'
                                              : 'Open the room'}
                                    </button>
                                    <p className="rt-start-note">
                                        {trip
                                            ? 'Everyone in the car can add songs from their phone.'
                                            : 'Guests join by scanning the code, or with a shared link.'}
                                    </p>
                                </div>
                            </div>
                        </section>
                    )}
                    <form className="rt-setup-composer" onSubmit={submit}>
                        <label className="rt-sr" htmlFor="rt-setup-input">
                            Tell the DJ
                        </label>
                        <textarea
                            id="rt-setup-input"
                            ref={composer}
                            className="rt-ask-input rt-setup-input"
                            rows={1}
                            value={text}
                            maxLength={MAX_LENGTH}
                            onChange={(e) => setText(e.target.value)}
                            onKeyDown={enterSends}
                            placeholder={
                                !firstOpen
                                    ? 'Tell the DJ more'
                                    : narrow
                                      ? '4 hours to the coast with the kids'
                                      : "Maria's 30th on the rooftop, or four hours to the coast with the kids"
                            }
                        />
                        <button
                            type="submit"
                            className="rt-ask-send"
                            aria-label="Send to the DJ"
                            disabled={thinking || text.trim() === ''}
                        >
                            <span aria-hidden="true">↑</span>
                        </button>
                    </form>
                </div>
            </main>
        </>
    );
}

function DjLine({ text }: { text: string }) {
    return (
        <li className="rt-dj-line">
            <span className="rt-setup-dot" aria-hidden="true" />
            <span className="rt-sr">DJ: </span>
            {text}
        </li>
    );
}

type FactKey = 'occasion' | 'name' | 'range';

/** "I heard": what the DJ understood, each one editable in place. The DJ acknowledges in one short line. */
function Facts({
    draft,
    onChange,
    onAck,
}: {
    draft: Draft;
    onChange: (draft: Draft) => void;
    onAck: (text: string) => void;
}) {
    const [editing, setEditing] = useState<FactKey | null>(null);
    const [value, setValue] = useState('');

    const facts: { key: FactKey; text: string }[] = [
        {
            key: 'occasion',
            text: draft.occasion ? OCCASIONS[draft.occasion] : '',
        },
        { key: 'name', text: draft.name ?? '' },
        { key: 'range', text: rangeText(draft) },
    ].filter((f): f is { key: FactKey; text: string } => f.text !== '');

    const save = (key: FactKey) => {
        const next = applyFact(draft, key, value.trim());

        if (next === null) {
            onAck(
                draft.timeline === 'trip'
                    ? 'I didn’t catch that length. Try “3 h” or “2 h 30”.'
                    : 'I didn’t catch those times. Try “20:00 to 00:30”.',
            );
        } else {
            onChange(next);
            onAck(
                `Got it: ${key === 'range' ? rangeText(next) : key === 'occasion' && next.occasion ? OCCASIONS[next.occasion] : value.trim()}.`,
            );
        }

        setEditing(null);
    };

    return (
        <p className="rt-facts">
            <span className="rt-facts-label">I heard</span>
            {facts.map((fact) =>
                editing === fact.key ? (
                    <input
                        key={fact.key}
                        className="rt-fact-input"
                        value={value}
                        autoFocus
                        aria-label={`Edit ${fact.key}`}
                        maxLength={60}
                        onChange={(e) => setValue(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                save(fact.key);
                            } else if (e.key === 'Escape') {
                                setEditing(null);
                            }
                        }}
                        onBlur={() => setEditing(null)}
                    />
                ) : (
                    <button
                        key={fact.key}
                        type="button"
                        className="rt-fact"
                        onClick={() => {
                            setValue(fact.text);
                            setEditing(fact.key);
                        }}
                    >
                        {fact.text}
                    </button>
                ),
            )}
        </p>
    );
}

/** Apply an edited fact to the draft, or null when it can't be read. Times keep the curve's shape. */
function applyFact(draft: Draft, key: FactKey, value: string): Draft | null {
    if (value === '') {
        return null;
    }

    if (key === 'name') {
        return { ...draft, name: value.slice(0, 60) };
    }

    if (key === 'occasion') {
        const lower = value.toLowerCase();
        const match =
            Object.keys(OCCASIONS).find((o) => lower.includes(o)) ?? 'other';

        return { ...draft, occasion: match };
    }

    const at = positions(draft);
    const [from, to] = [at[0], at[at.length - 1]];
    const rescale = (start: number, end: number): PlanPoint[] =>
        draft.points.map((p, i) => {
            const m = snap(
                start +
                    ((at[i] - from) / Math.max(1, to - from)) * (end - start),
            );

            return {
                ...p,
                at: draft.timeline === 'trip' ? Math.max(0, m) : clockText(m),
            };
        });

    if (draft.timeline === 'trip') {
        const hm = value.match(/^(\d+(?:\.\d+)?)\s*h(?:ours?)?\s*(\d+)?/i);
        const minutesOnly = value.match(/^(\d+)\s*m(?:in)?/i);
        const total = hm
            ? Math.round(Number(hm[1]) * 60 + Number(hm[2] ?? 0))
            : minutesOnly
              ? Number(minutesOnly[1])
              : NaN;

        if (!Number.isFinite(total) || total < 15) {
            return null;
        }

        return {
            ...draft,
            minutes: snap(total),
            points: rescale(0, snap(total)),
        };
    }

    const times = value.match(/(\d{1,2}):(\d{2})\D+(\d{1,2}):(\d{2})/);

    if (!times) {
        return null;
    }

    const start = clockMinutes(`${times[1]}:${times[2]}`);
    let end = clockMinutes(`${times[3]}:${times[4]}`);

    while (end <= start) {
        end += 24 * 60;
    }

    return { ...draft, points: rescale(start, end) };
}
