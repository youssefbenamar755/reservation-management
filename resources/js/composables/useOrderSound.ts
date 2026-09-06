import { ref } from 'vue';

const enabled = ref(false);
const available = ref(false);
let userId: number | null = null;
let context: AudioContext | null = null;
let lastPlayed = 0;

function preferenceKey() {
    return `wphub:order-sound:${userId}`;
}

export function useOrderSound() {
    function initialize(uid: number) {
        if (typeof window === 'undefined') return;
        available.value = typeof window.AudioContext === 'function';
        if (userId === uid) return;
        userId = uid;
        enabled.value = false;
        try { enabled.value = window.localStorage.getItem(preferenceKey()) === 'on'; } catch { /* Storage is optional. */ }
    }

    async function unlock() {
        if (!enabled.value || !available.value) return;
        try {
            context ??= new AudioContext();
            if (context.state === 'suspended') await context.resume();
        } catch { /* A blocked sound must never interrupt the visual alert. */ }
    }

    function play(preview = false) {
        if (!enabled.value || context?.state !== 'running') return;
        const now = Date.now();
        if (!preview && now - lastPlayed < 1500) return;
        lastPlayed = now;
        try {
            const start = context.currentTime;
            for (const [offset, frequency] of [[0, 660], [0.16, 880]]) {
                const oscillator = context.createOscillator();
                const gain = context.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.value = frequency;
                gain.gain.setValueAtTime(0, start + offset);
                gain.gain.linearRampToValueAtTime(0.12, start + offset + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.001, start + offset + 0.18);
                oscillator.connect(gain);
                gain.connect(context.destination);
                oscillator.onended = () => { oscillator.disconnect(); gain.disconnect(); };
                oscillator.start(start + offset);
                oscillator.stop(start + offset + 0.2);
            }
        } catch { /* Keep notifications working even when audio is unavailable. */ }
    }

    async function toggle() {
        enabled.value = !enabled.value;
        try { window.localStorage.setItem(preferenceKey(), enabled.value ? 'on' : 'off'); } catch { /* Storage is optional. */ }
        if (enabled.value) {
            await unlock();
            play(true);
        }
    }

    return { enabled, available, initialize, unlock, play, toggle };
}
