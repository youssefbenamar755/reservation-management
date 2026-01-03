import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Declare Pusher on window for Laravel Echo
declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo;
    }
}

// Type definition for Echo to fix TypeScript issues
declare module 'laravel-echo' {
    interface Echo {
        private(channel: string): Channel;
        leave(channel: string): void;
    }
    
    interface Channel {
        listen(event: string, callback: (data: any) => void): Channel;
        stopListening(event: string): Channel;
    }
}

// Make Pusher available globally
window.Pusher = Pusher;

// Get CSRF token from meta tag
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

// Initialize Echo
const echo = new Echo({
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

// Make Echo available globally
window.Echo = echo;

export default echo;

