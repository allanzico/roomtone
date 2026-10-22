export type Timeline = 'clock' | 'trip';

export type PlanPoint = {
    /** Clock: local "HH:MM". Trip: minutes from leaving. */
    at: string | number;
    energy: number;
    label: string;
};

/** What the setup chat has agreed so far (checked by the server's SetupDraft). */
export type Draft = {
    occasion: string | null;
    name: string | null;
    timeline: Timeline;
    minutes: number | null;
    points: PlanPoint[];
};

export type Message = { from: 'host' | 'dj'; text: string };
