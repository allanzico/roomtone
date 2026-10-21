/**
 * The YouTube IFrame Player API, loaded once from YouTube. Only the parts the host screen uses are typed.
 * https://developers.google.com/youtube/iframe_api_reference
 */

export type YouTubePlayer = {
    playVideo(): void;
    pauseVideo(): void;
    loadVideoById(videoId: string): void;
    cueVideoById(videoId: string): void;
    unMute(): void;
    destroy(): void;
};

type PlayerOptions = {
    host: string;
    width: string;
    height: string;
    playerVars: Record<string, number>;
    events: {
        onReady: () => void;
        onStateChange: (event: { data: number }) => void;
        onError: (event: { data: number }) => void;
        onAutoplayBlocked: () => void;
    };
};

type YouTubeApi = {
    Player: new (element: HTMLElement, options: PlayerOptions) => YouTubePlayer;
};

declare global {
    interface Window {
        YT?: Partial<YouTubeApi>;
        onYouTubeIframeAPIReady?: () => void;
    }
}

export const ENDED = 0;

let loading: Promise<YouTubeApi> | null = null;

export function loadYouTube(): Promise<YouTubeApi> {
    loading ??= new Promise((resolve, reject) => {
        const ready = () => {
            const Player = window.YT?.Player;

            if (Player) {
                resolve({ Player });
            }
        };

        if (window.YT?.Player) {
            ready();

            return;
        }

        window.onYouTubeIframeAPIReady = ready;

        const script = document.createElement('script');
        script.src = 'https://www.youtube.com/iframe_api';
        script.async = true;
        script.onerror = () => {
            loading = null;
            reject(new Error('Could not load the YouTube player.'));
        };
        document.head.appendChild(script);
    });

    return loading;
}
