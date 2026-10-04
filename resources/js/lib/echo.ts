import type Echo from 'laravel-echo';

/**
 * Reverb (WebSocket) client, created on first use and only when the
 * VITE_REVERB_* variables are set. Without them the bell falls back to polling.
 */
type ReverbEcho = Echo<'reverb'>;

let instance: Promise<ReverbEcho | null> | null = null;

function env(name: string): string {
    const value = import.meta.env[name];

    return typeof value === 'string' ? value : '';
}

function xsrfToken(): string {
    const match = /(?:^|;\s*)XSRF-TOKEN=([^;]+)/.exec(document.cookie);

    return match ? decodeURIComponent(match[1]) : '';
}

export function realtimeEnabled(): boolean {
    return env('VITE_REVERB_APP_KEY') !== '';
}

export function getEcho(): Promise<ReverbEcho | null> {
    if (!realtimeEnabled()) {
        return Promise.resolve(null);
    }

    instance ??= Promise.all([import('laravel-echo'), import('pusher-js')])
        .then(([{ default: EchoClient }, { default: Pusher }]) => {
            const scheme = env('VITE_REVERB_SCHEME') || 'https';
            const port = Number(env('VITE_REVERB_PORT') || (scheme === 'https' ? 443 : 80));

            return new EchoClient({
                broadcaster: 'reverb',
                Pusher,
                key: env('VITE_REVERB_APP_KEY'),
                wsHost: env('VITE_REVERB_HOST') || window.location.hostname,
                wsPort: port,
                wssPort: port,
                forceTLS: scheme === 'https',
                enabledTransports: ['ws', 'wss'],
                auth: { headers: { 'X-XSRF-TOKEN': xsrfToken() } },
            });
        })
        .catch(() => null);

    return instance;
}
