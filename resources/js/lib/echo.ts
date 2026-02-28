import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Declare Pusher on window for Laravel Echo
declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<any>;
    }
}

// SSR-safe: only touch window/document in the browser
const isServer = typeof window === 'undefined';

let echo: InstanceType<typeof Echo>;

if (isServer) {
    // Stub for SSR so components that import echo don't crash
    echo = {
        private: () => ({ listen: () => {}, stopListening: () => {} }),
        leave: () => {},
    } as unknown as InstanceType<typeof Echo>;
} else {
    window.Pusher = Pusher;

    const csrfToken = document
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
}

export default echo;

