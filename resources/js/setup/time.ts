import type { Draft, PlanPoint } from './types';

export const STEP = 15;
const DAY = 24 * 60;

/** Clock "HH:MM" to minutes after midnight. */
export function clockMinutes(at: string): number {
    const [h, m] = at.split(':').map(Number);

    return h * 60 + m;
}

export function clockText(minutes: number): string {
    const m = ((Math.round(minutes) % DAY) + DAY) % DAY;

    return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
}

/** Points as one increasing minute scale: "00:30" after "23:00" is later that night. */
export function positions(draft: Draft): number[] {
    if (draft.timeline === 'trip') {
        return draft.points.map((p) => Number(p.at));
    }

    const out: number[] = [];

    for (const p of draft.points) {
        let m = clockMinutes(String(p.at));

        while (out.length > 0 && m < out[out.length - 1]) {
            m += DAY;
        }

        out.push(m);
    }

    return out;
}

export function atFor(draft: Draft, minutes: number): PlanPoint['at'] {
    return draft.timeline === 'trip'
        ? Math.max(0, minutes)
        : clockText(minutes);
}

export function snap(minutes: number): number {
    return Math.round(minutes / STEP) * STEP;
}

/** "20:00 to 00:30", or "3 h" / "2 h 30" for a trip. */
export function rangeText(draft: Draft): string {
    if (draft.timeline === 'trip') {
        const total = draft.minutes ?? Math.max(0, ...positions(draft));
        const h = Math.floor(total / 60);
        const m = total % 60;

        return m === 0 ? `${h} h` : `${h} h ${m}`;
    }

    const first = draft.points[0];
    const last = draft.points[draft.points.length - 1];

    return first && last ? `${first.at} to ${last.at}` : '';
}

/** Trip axis label: "Leave", "1 h", …, "Arrive". */
export function tripLabel(minutes: number, total: number): string {
    if (minutes <= 0) {
        return 'Leave';
    }

    if (minutes >= total - STEP) {
        return 'Arrive';
    }

    return `${Math.round(minutes / 60)} h`;
}
