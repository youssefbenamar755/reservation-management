import type EchoType from 'laravel-echo';
import type PusherType from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof PusherType;
        Echo: EchoType<any>;
    }
}

export let echo: EchoType<any> | null = null;

let initPromise: Promise<EchoType<any> | null> | null = null;

export async function getEcho(): Promise<EchoType<any> | null> {
    if (typeof window === 'undefined') {
        return null;
    }

    if (echo) {
        return echo;
    }

    if (initPromise) {
        return initPromise;
    }

    initPromise = (async () => {
        const [{ default: Echo }, { default: Pusher }] = await Promise.all([
            import('laravel-echo'),
            import('pusher-js'),
        ]);

        window.Pusher = Pusher;

        const csrfToken =
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') || '';

        echo = new Echo({
            broadcaster: 'pusher',
            key: import.meta.env.VITE_PUSHER_APP_KEY || '',
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
            forceTLS: true,
            encrypted: true,
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                },
            },
        });

        window.Echo = echo;

        return echo;
    })().finally(() => {
        initPromise = null;
    });

    return initPromise;
}

export { echo as default };

