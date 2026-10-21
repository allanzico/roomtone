import { useEffect, useRef } from 'react';
import type { CSSProperties } from 'react';
import type { Stage } from './stage';
import { useRoom } from './store';
import type { SetItem } from './types';
import { ENDED, loadYouTube } from './youtube';
import type { YouTubePlayer } from './youtube';

/**
 * The YouTube player. YouTube's policies require it to be visible (no audio-only background player) and at
 * least 200×200 px, so on a TV it sits bottom centre in real px, outside the scaled stage, never smaller than
 * 356×200. Sound only starts after the host clicks "Start the party" (browsers block unmuted autoplay).
 */

/** The live player, so the Start button can call playVideo() inside its click (the user gesture). */
let current: YouTubePlayer | null = null;

const MIN_WIDTH = 356;
const DESIGN_WIDTH = 400;
/** Bottom-centre gap between "Now playing" (ends x 940) and "Up next" (starts x 1380), in design px. */
const GAP = { left: 940, right: 1380, bottom: 64, lifted: 800 };

export function hasVideo(state: {
    nowPlaying: SetItem | null;
    queue: SetItem[];
}): boolean {
    return [state.nowPlaying, ...state.queue].some((item) => item?.videoId);
}

type Box = { left: number; top: number; width: number; height: number };

/** Where the tile goes on a TV, in CSS px. Lifted above the bottom text rows when the gap is too small. */
function tvVideoBox(stage: Stage): Box {
    const s = stage.scale;
    const width = Math.max(MIN_WIDTH, DESIGN_WIDTH * s);
    const height = (width * 9) / 16;
    const left = stage.left + ((GAP.left + GAP.right) / 2) * s - width / 2;
    const fits =
        left >= stage.left + GAP.left * s + 16 &&
        left + width <= stage.left + GAP.right * s - 16;
    const bottom = stage.top + (fits ? 1080 - GAP.bottom : GAP.lifted) * s;

    return { left, top: bottom - height, width, height };
}

/** Design px the TV "Now playing" block may use so it always ends left of the tile (860 when the tile fits). */
export function nowSlotWidth(stage: Stage): number {
    const tile = tvVideoBox(stage);

    return Math.min(
        GAP.left - 80,
        Math.floor((tile.left - stage.left) / stage.scale - 80 - 16),
    );
}

/** TV: bottom centre. Phone: the pool's area, the only place a 200px-tall player stays fully in view. */
export function videoRect(stage: Stage): CSSProperties {
    if (stage.layout === 'tv') {
        return { position: 'fixed', ...tvVideoBox(stage) };
    }

    const { x, y, w, h } = stage.band;

    return { position: 'fixed', left: x, top: y, width: w, height: h };
}

/** The Start button never covers the player: above the tile on a TV, just below it on a phone. */
export function startPosition(stage: Stage): CSSProperties {
    if (stage.layout === 'tv') {
        const tile = tvVideoBox(stage);

        return {
            left: tile.left + tile.width / 2,
            top: tile.top - 24,
            translate: '-50% -100%',
        };
    }

    return {
        left: '50%',
        top: stage.band.y + stage.band.h + 16,
        translate: '-50% 0',
    };
}

export function VideoTile({ style }: { style?: CSSProperties }) {
    const host = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const element = host.current;

        if (!element) {
            return;
        }

        // YouTube replaces this node with its iframe, so React must not own it.
        const mount = document.createElement('div');
        element.appendChild(mount);

        let player: YouTubePlayer | null = null;
        let loadedItemId: number | null = null;
        let cancelled = false;

        const sync = () => {
            const room = useRoom.getState();
            const item = room.nowPlaying;

            if (!player || !item) {
                return;
            }

            if (!item.videoId) {
                // No video for this track: skip it, unless nothing in the set has one.
                if (hasVideo(room)) {
                    room.nextTrack();
                }

                return;
            }

            const playing = room.started && !room.paused;

            if (item.id !== loadedItemId) {
                loadedItemId = item.id;

                if (playing) {
                    player.loadVideoById(item.videoId);
                } else {
                    player.cueVideoById(item.videoId);
                }

                return;
            }

            if (playing) {
                player.playVideo();
            } else {
                player.pauseVideo();
            }
        };

        loadYouTube()
            .then((YT) => {
                if (cancelled) {
                    return;
                }

                const created = new YT.Player(mount, {
                    host: 'https://www.youtube-nocookie.com',
                    width: '100%',
                    height: '100%',
                    playerVars: {
                        controls: 0,
                        disablekb: 1,
                        fs: 0,
                        iv_load_policy: 3,
                        playsinline: 1,
                        rel: 0,
                    },
                    events: {
                        onReady: () => {
                            player = created;
                            current = created;
                            sync();
                        },
                        onStateChange: (event) => {
                            if (event.data === ENDED) {
                                useRoom.getState().nextTrack();
                            }
                        },
                        // Removed, private or not embeddable: move on.
                        onError: () => useRoom.getState().nextTrack(),
                        // The browser refused to play with sound: ask for the click again.
                        onAutoplayBlocked: () => useRoom.getState().stop(),
                    },
                });
            })
            .catch(() => {
                // No player (offline, blocked by an extension): the room still shows the set.
            });

        const unsubscribe = useRoom.subscribe((state, prev) => {
            if (
                state.nowPlaying?.id !== prev.nowPlaying?.id ||
                state.started !== prev.started ||
                state.paused !== prev.paused
            ) {
                sync();
            }
        });

        return () => {
            cancelled = true;
            unsubscribe();

            if (current === player) {
                current = null;
            }

            player?.destroy();
            mount.remove();
        };
    }, []);

    return <div ref={host} className="rt-video" style={style} />;
}

/** Call from a click or tap handler: browsers only allow sound that starts inside a user gesture. */
export function startPlayback(): void {
    current?.unMute();
    current?.playVideo();
    useRoom.getState().start();
}

export function StartParty({ style }: { style: CSSProperties }) {
    return (
        <div className="rt-start" style={style}>
            <button
                type="button"
                className="rt-start-button"
                onClick={startPlayback}
                autoFocus
            >
                Start the party
            </button>
            <p className="rt-start-note">The music plays from this screen</p>
        </div>
    );
}
