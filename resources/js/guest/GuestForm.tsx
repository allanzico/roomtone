import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

const HINTS = ['A show you love', 'A food you love', 'A place you love'];

type Props = {
    initial: { name: string; loves: string[] };
    /** Where the form goes: joining posts, changing puts. */
    action: { url: string; method: 'post' | 'put' };
    submit: string;
    busy: string;
    /** Shown above the fields (the page's heading). */
    children?: ReactNode;
    /** Shown after the submit button (a cancel link, say). */
    after?: ReactNode;
    onSaved?: () => void;
};

/** A guest's name and three loves: joining a room, or changing them later. */
export function GuestForm({
    initial,
    action,
    submit,
    busy,
    children,
    after,
    onSaved,
}: Props) {
    const form = useForm({ name: initial.name, loves: initial.loves });
    const errors = form.errors;

    const setLove = (i: number, value: string) =>
        form.setData(
            'loves',
            form.data.loves.map((love, j) => (j === i ? value : love)),
        );

    const send = (e: FormEvent) => {
        e.preventDefault();
        form.submit(action.method, action.url, { onSuccess: onSaved });
    };

    return (
        <form className="rt-guest-body" onSubmit={send} noValidate>
            {children}

            <label className="rt-field">
                <span className="rt-field-label">Your name</span>
                <input
                    className="rt-ask-input"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    maxLength={40}
                    autoComplete="given-name"
                    required
                />
                {errors.name && (
                    <span className="rt-field-error">{errors.name}</span>
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
                {form.processing ? busy : submit}
            </button>
            {after}
        </form>
    );
}
