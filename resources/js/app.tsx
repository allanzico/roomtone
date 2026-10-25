import { createInertiaApp } from '@inertiajs/react';
import { initializeTheme } from '@/hooks/use-appearance';
import { configureEcho } from '@laravel/echo-react';

// Realtime from Laravel Reverb: guests landing in the room, set and island updates, the DJ's answers.
// Without VITE_REVERB_HOST (the Docker image) the socket goes to the page's own address, where the web server
// passes /app on to Reverb.
const secure = window.location.protocol === 'https:';
const reverbPort = Number(
    import.meta.env.VITE_REVERB_PORT ||
        window.location.port ||
        (secure ? 443 : 80),
);
configureEcho({
    broadcaster: 'reverb',
    wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS: import.meta.env.VITE_REVERB_HOST
        ? import.meta.env.VITE_REVERB_SCHEME === 'https'
        : secure,
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Every page draws its own full-screen layout.
    layout: () => null,
    strictMode: true,
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
