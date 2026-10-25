import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
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
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name === 'HostRoom':
            case name === 'Join':
            case name === 'Guest':
            case name === 'Setup':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
