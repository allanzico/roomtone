import { useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { FormEvent } from 'react';
import { store as storeRequest } from '@/routes/events/requests';
import { useRoom } from './store';

const MAX_LENGTH = 200;

/**
 * "Ask the DJ": the host steers the music mid-party in plain words ("calm it down", "more Afrobeats").
 * Only on the host link. The DJ answers in one line; while it works, the screen polls for the new set.
 */
export function AskDj({ autoFocus = false }: { autoFocus?: boolean }) {
    const code = useRoom((s) => s.event.code);
    const hostKey = useRoom((s) => s.hostKey);
    const latest = useRoom((s) => s.latestRequest);
    const setAskOpen = useRoom((s) => s.setAskOpen);
    const input = useRef<HTMLInputElement>(null);
    const form = useForm({ key: hostKey ?? '', text: '' });
    const working = latest?.status === 'pending' || form.processing;

    useEffect(() => {
        if (autoFocus) {
            input.current?.focus();
        }
    }, [autoFocus]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (working || form.data.text.trim() === '') {
            return;
        }

        form.post(storeRequest.url(code), {
            preserveState: true,
            preserveScroll: true,
            only: ['latestRequest'],
            onSuccess: () => form.setData('text', ''),
        });
    };

    return (
        <form className="rt-ask" onSubmit={submit}>
            <DjLine working={working} error={form.errors.text} />
            <div className="rt-ask-row">
                <label className="rt-sr" htmlFor="rt-ask-input">
                    Ask the DJ
                </label>
                <input
                    id="rt-ask-input"
                    ref={input}
                    className="rt-ask-input"
                    value={form.data.text}
                    onChange={(e) => form.setData('text', e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Escape') {
                            setAskOpen(false);
                        }
                    }}
                    maxLength={MAX_LENGTH}
                    placeholder="Speeches in 10 min, calm it down"
                    autoComplete="off"
                />
                <button
                    type="submit"
                    className="rt-ask-send"
                    aria-label="Send to the DJ"
                    disabled={working || form.data.text.trim() === ''}
                >
                    <span aria-hidden="true">↑</span>
                </button>
            </div>
        </form>
    );
}

function DjLine({ working, error }: { working: boolean; error?: string }) {
    const latest = useRoom((s) => s.latestRequest);
    const text = error ?? (working ? null : latest?.reply);

    if (!working && !text) {
        return null;
    }

    return (
        <p className="rt-dj" aria-live="polite">
            <span className="rt-dj-dot" aria-hidden="true" />
            {working ? (
                <span className="rt-dj-thinking" aria-label="The DJ is on it">
                    <span />
                    <span />
                    <span />
                </span>
            ) : (
                text
            )}
        </p>
    );
}
