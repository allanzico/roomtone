import type { ReactNode } from 'react';

/** A spinner and what is being waited for: songs take a few seconds to arrive, and a blank screen reads as broken. */
export function Loading({ children }: { children: ReactNode }) {
    return (
        <p className="rt-loading" role="status">
            <span className="rt-spinner" aria-hidden="true" />
            {children}
        </p>
    );
}
