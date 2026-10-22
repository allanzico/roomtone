import { useId, useRef, useState } from 'react';
import type { KeyboardEvent, MouseEvent, PointerEvent } from 'react';
import { atFor, clockText, positions, snap, STEP, tripLabel } from './time';
import type { Draft, PlanPoint } from './types';

/** Drawing width: laptop 680 (as designed); phones draw narrower so the curve stays tall enough to drag. */
const WIDE = 680;
const COMPACT = 400;
const H = 170;
const PAD = { left: 14, right: 14, top: 22, bottom: 30 };
const PLOT_H = H - PAD.top - PAD.bottom;
/** Minimum distance between axis labels, in SVG units. */
const LABEL_GAP = 110;
/** A plan keeps 2 to 6 points (as SetupDraft does). */
const MIN_POINTS = 2;
const MAX_POINTS = 6;

/** Energy 1 (calm) to 5 (peak), as the genre palette runs: blue, violet, pink, ember. */
const ENERGY_COLOURS = ['#4c7dff', '#9d8cff', '#ff4fd8', '#ff8a3d', '#ff8a3d'];

type Props = {
    draft: Draft;
    /** Phone layout. */
    compact?: boolean;
    onChange: (points: PlanPoint[]) => void;
};

/**
 * The energy plan as a curve. Drag a handle (or use the arrow keys on it) to move it: up/down is energy (1–5),
 * left/right is time in 15-minute steps, never past its neighbours. Tap the space between two points to add one;
 * select a point and remove it (or press Delete). The peak is marked only when one point is the highest.
 */
export function PlanCurve({ draft, onChange, compact = false }: Props) {
    const W = compact ? COMPACT : WIDE;
    const svg = useRef<SVGSVGElement>(null);
    // useId() can contain characters that break SVG url(#…) references.
    const id = useId().replace(/[^a-zA-Z0-9_-]/g, '');
    const gradientId = `rt-plan-${id}`;
    const glowId = `rt-glow-${id}`;
    const at = positions(draft);
    // The x scale is fixed while a handle is dragged, so the curve does not swim under the pointer.
    const [frozen, setFrozen] = useState<[number, number] | null>(null);
    const [selected, setSelected] = useState<number | null>(null);

    if (draft.points.length < 2) {
        return null;
    }

    const span: [number, number] = frozen ?? [
        at[0],
        draft.timeline === 'trip'
            ? Math.max(at[at.length - 1], draft.minutes ?? 0)
            : at[at.length - 1],
    ];
    const x = (m: number) =>
        PAD.left +
        ((m - span[0]) / Math.max(STEP, span[1] - span[0])) *
            (W - PAD.left - PAD.right);
    const y = (energy: number) => PAD.top + ((5 - energy) / 4) * PLOT_H;
    // A flat or two-topped plan has no peak.
    const top = Math.max(...draft.points.map((p) => p.energy));
    const peak =
        draft.points.filter((p) => p.energy === top).length === 1
            ? draft.points.findIndex((p) => p.energy === top)
            : -1;

    const xy = draft.points.map((p, i) => [x(at[i]), y(p.energy)] as const);

    // Axis labels never collide: the first, the last and the peak always show; a point too close to the label
    // before it (or to the last one) keeps its handle but drops its label.
    const last = draft.points.length - 1;
    const axisLabels = new Set<number>(peak >= 0 ? [0, last, peak] : [0, last]);
    let previousX = xy[0][0];
    for (let i = 1; i < last; i++) {
        if (
            i === peak ||
            (xy[i][0] - previousX >= LABEL_GAP &&
                xy[last][0] - xy[i][0] >= LABEL_GAP)
        ) {
            axisLabels.add(i);
            previousX = xy[i][0];
        }
    }
    const path = smooth(xy);

    const move = (i: number, minutes: number, energy: number) => {
        const lo = i === 0 ? span[0] - 6 * 60 : at[i - 1] + STEP;
        const hi = i === at.length - 1 ? span[1] + 6 * 60 : at[i + 1] - STEP;
        const m = Math.min(hi, Math.max(lo, snap(minutes)));
        const e = Math.min(5, Math.max(1, Math.round(energy)));

        onChange(
            draft.points.map((p, j) =>
                j === i ? { ...p, at: atFor(draft, m), energy: e } : p,
            ),
        );
    };

    /** A pointer position as plan minutes and energy, or null when the SVG isn't laid out. */
    const planAt = (clientX: number, clientY: number) => {
        const box = svg.current?.getBoundingClientRect();

        if (!box) {
            return null;
        }

        const px = ((clientX - box.left) / box.width) * W;
        const py = ((clientY - box.top) / box.height) * H;

        return {
            minutes:
                span[0] +
                ((px - PAD.left) / (W - PAD.left - PAD.right)) *
                    (span[1] - span[0]),
            energy: 5 - ((py - PAD.top) / PLOT_H) * 4,
        };
    };

    const pointerMove = (i: number) => (event: PointerEvent<SVGGElement>) => {
        if (!event.currentTarget.hasPointerCapture(event.pointerId)) {
            return;
        }

        const p = planAt(event.clientX, event.clientY);

        if (p) {
            move(i, p.minutes, p.energy);
        }
    };

    const remove = (i: number) => {
        if (draft.points.length <= MIN_POINTS) {
            return;
        }

        setSelected(null);
        onChange(draft.points.filter((_, j) => j !== i));
    };

    // A tap on empty space between two points adds one there, 15 minutes clear of its neighbours.
    const add = (event: MouseEvent<SVGSVGElement>) => {
        if (
            draft.points.length >= MAX_POINTS ||
            (event.target as Element).closest('.rt-curve-handle')
        ) {
            return;
        }

        const p = planAt(event.clientX, event.clientY);

        if (!p) {
            return;
        }

        const m = snap(p.minutes);
        const i = at.findIndex((t) => t > m);

        if (i <= 0 || m - at[i - 1] < STEP || at[i] - m < STEP) {
            setSelected(null);

            return;
        }

        const point: PlanPoint = {
            at: atFor(draft, m),
            energy: Math.min(5, Math.max(1, Math.round(p.energy))),
            label: '',
        };
        onChange([
            ...draft.points.slice(0, i),
            point,
            ...draft.points.slice(i),
        ]);
        setSelected(i);
    };

    const keys = (i: number) => (event: KeyboardEvent<SVGGElement>) => {
        const p = draft.points[i];
        const step = {
            ArrowUp: [0, 1],
            ArrowDown: [0, -1],
            ArrowRight: [STEP, 0],
            ArrowLeft: [-STEP, 0],
        }[event.key];

        if (step) {
            event.preventDefault();
            move(i, at[i] + step[0], p.energy + step[1]);
        } else if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            remove(i);
        }
    };

    const chosen =
        selected !== null && selected < draft.points.length ? selected : null;
    const chosenName =
        chosen === null
            ? ''
            : draft.points[chosen].label ||
              (draft.timeline === 'trip'
                  ? tripLabel(at[chosen], span[1])
                  : clockText(at[chosen]));

    return (
        <div className="rt-curve-wrap">
            <svg
                ref={svg}
                className="rt-curve"
                viewBox={`0 0 ${W} ${H}`}
                role="group"
                aria-label="Energy plan"
                onClick={add}
            >
                <defs>
                    <linearGradient id={gradientId} x1="0" x2="1" y1="0" y2="0">
                        {draft.points.map((p, i) => (
                            <stop
                                key={i}
                                offset={
                                    (xy[i][0] - xy[0][0]) /
                                    Math.max(1, xy[xy.length - 1][0] - xy[0][0])
                                }
                                stopColor={ENERGY_COLOURS[p.energy - 1]}
                            />
                        ))}
                    </linearGradient>
                    <filter
                        id={glowId}
                        x="-10%"
                        y="-40%"
                        width="120%"
                        height="180%"
                    >
                        <feGaussianBlur stdDeviation="5" />
                    </filter>
                </defs>

                <line
                    className="rt-curve-base"
                    x1={PAD.left}
                    x2={W - PAD.right}
                    y1={H - PAD.bottom}
                    y2={H - PAD.bottom}
                />
                <path
                    d={path}
                    stroke={`url(#${gradientId})`}
                    strokeWidth="10"
                    fill="none"
                    opacity="0.5"
                    filter={`url(#${glowId})`}
                />
                <path
                    d={path}
                    stroke={`url(#${gradientId})`}
                    strokeWidth="3"
                    fill="none"
                    strokeLinecap="round"
                />

                {draft.points.map((p, i) => {
                    const [cx, cy] = xy[i];
                    const labelled = axisLabels.has(i);
                    const time =
                        draft.timeline === 'trip'
                            ? tripLabel(at[i], span[1])
                            : clockText(at[i]);
                    const middle = i > 0 && i < draft.points.length - 1;
                    const below =
                        draft.timeline === 'clock' && middle && p.label
                            ? `${time} ${p.label.toLowerCase()}`
                            : time;

                    return (
                        <g
                            key={i}
                            className="rt-curve-handle"
                            data-peak={i === peak}
                            tabIndex={0}
                            role="slider"
                            aria-valuemin={1}
                            aria-valuemax={5}
                            aria-valuenow={p.energy}
                            aria-valuetext={`${time}, energy ${p.energy} of 5${p.label ? `, ${p.label.toLowerCase()}` : ''}${i === peak ? ', peak' : ''}`}
                            aria-label={p.label || time}
                            data-selected={i === chosen}
                            onKeyDown={keys(i)}
                            onFocus={() => setSelected(i)}
                            onPointerDown={(e) => {
                                e.currentTarget.setPointerCapture(e.pointerId);
                                setFrozen(span);
                                setSelected(i);
                            }}
                            onPointerMove={pointerMove(i)}
                            onPointerUp={(e) => {
                                e.currentTarget.releasePointerCapture(
                                    e.pointerId,
                                );
                                setFrozen(null);
                            }}
                        >
                            <circle
                                cx={cx}
                                cy={cy}
                                r="15"
                                className="rt-curve-halo"
                            />
                            <circle
                                cx={cx}
                                cy={cy}
                                r={i === peak ? 8 : 6}
                                className="rt-curve-dot"
                            />
                            {draft.timeline === 'trip' && p.label && (
                                <text
                                    x={cx}
                                    y={cy - 18}
                                    className="rt-curve-phase"
                                    textAnchor="middle"
                                >
                                    {p.label}
                                </text>
                            )}
                            {labelled && (
                                <text
                                    x={cx}
                                    y={H - 8}
                                    className="rt-curve-axis"
                                    textAnchor={
                                        i === 0
                                            ? 'start'
                                            : i === draft.points.length - 1
                                              ? 'end'
                                              : 'middle'
                                    }
                                >
                                    {below}
                                </text>
                            )}
                        </g>
                    );
                })}
            </svg>
            <p className="rt-curve-tools">
                {chosen !== null && draft.points.length > MIN_POINTS ? (
                    <button
                        type="button"
                        className="rt-curve-remove"
                        onClick={() => remove(chosen)}
                    >
                        Remove {chosenName.toLowerCase()}
                    </button>
                ) : (
                    <span className="rt-curve-hint">
                        {draft.points.length < MAX_POINTS
                            ? 'Drag a point to change it. Tap between two points to add one.'
                            : 'Drag a point to change it, or select one to remove it.'}
                    </span>
                )}
            </p>
        </div>
    );
}

/** A smooth path through the points (Catmull-Rom as cubic Béziers). */
function smooth(points: ReadonlyArray<readonly [number, number]>): string {
    let d = `M ${points[0][0]} ${points[0][1]}`;

    for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i - 1] ?? points[i];
        const p1 = points[i];
        const p2 = points[i + 1];
        const p3 = points[i + 2] ?? p2;
        const c1 = [p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6];
        const c2 = [p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6];
        d += ` C ${c1[0]} ${c1[1]}, ${c2[0]} ${c2[1]}, ${p2[0]} ${p2[1]}`;
    }

    return d;
}
