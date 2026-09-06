import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url);
function load(path, globals = {}) {
    const source = readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
    const { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });
    const module = { exports: {} };
    runInNewContext(outputText, { module, exports: module.exports, require, setTimeout, clearTimeout, ...globals });
    return module.exports;
}
const notification = (id = 'new-order') => ({ id, type: 'order', message: 'New order #15', data: { order_id: 42 } });

function harness() {
    let current = [];
    let sounds = 0;
    const timers = new Map();
    let timerId = 0;
    const { createOrderAlerts } = load('lib/orderAlerts.ts', {
        setTimeout: (callback) => { timers.set(++timerId, callback); return timerId; },
        clearTimeout: (id) => timers.delete(id),
    });
    const alerts = createOrderAlerts({ onChange: (value) => { current = value; }, onSound: () => { sounds++; } });
    return { alerts, timers, get current() { return current; }, get sounds() { return sounds; } };
}

test('a new order gives one popup with its local order link and one sound', () => {
    const h = harness();
    h.alerts.receive(notification());
    h.alerts.receive(notification());
    assert.equal(h.current.length, 1);
    assert.equal(h.current[0].url, '/orders/42');
    assert.equal(h.current[0].message, 'New order #15');
    assert.equal(h.sounds, 1);
    h.alerts.dispose();
});

test('read notifications, submissions, and invalid order targets do not alert', () => {
    const h = harness();
    h.alerts.receive({ ...notification(), type: 'form_submission' });
    h.alerts.receive({ ...notification(), read_at: '2026-09-06T12:00:00Z' });
    for (const id of [0, -1, 'https://example.com', null, 1.5]) h.alerts.receive({ ...notification(), data: { order_id: id } });
    assert.equal(h.current.length, 0);
    assert.equal(h.sounds, 0);
});

test('bursts keep the three newest popups and dismissing does not permit duplicate alerts', () => {
    const h = harness();
    for (let i = 1; i <= 5; i++) h.alerts.receive(notification(String(i)));
    assert.equal(h.current.map((n) => n.id).join(','), '3,4,5');
    assert.equal(h.timers.size, 3);
    h.alerts.dismiss('5');
    h.alerts.receive(notification('5'));
    assert.equal(h.current.length, 2);
    assert.equal(h.sounds, 5);
    h.alerts.dispose();
});

test('popups expire and unmount cancels pending work', () => {
    const h = harness();
    h.alerts.receive(notification());
    h.timers.values().next().value();
    assert.equal(h.current.length, 0);
    h.alerts.receive(notification('second'));
    h.alerts.dispose();
    assert.equal(h.timers.size, 0);
    h.alerts.receive(notification('after-dispose'));
    assert.equal(h.sounds, 2);
});

function soundHarness({ blocked = false, storageFails = false } = {}) {
    const values = new Map();
    let tones = 0;
    class AudioContext {
        state = 'suspended';
        currentTime = 0;
        destination = {};
        async resume() { if (blocked) throw new Error('Blocked audio'); this.state = 'running'; }
        createOscillator() { return { frequency: {}, connect() {}, disconnect() {}, start() { tones++; }, stop() {} }; }
        createGain() { return { gain: { setValueAtTime() {}, linearRampToValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {}, disconnect() {} }; }
    }
    const window = { AudioContext, localStorage: {
        getItem(key) { if (storageFails) throw new Error('Storage disabled'); return values.get(key); },
        setItem(key, value) { if (storageFails) throw new Error('Storage disabled'); values.set(key, value); },
    } };
    const { useOrderSound } = load('composables/useOrderSound.ts', { window, AudioContext });
    return { sound: useOrderSound(), values, get tones() { return tones; } };
}

test('sound requires opt-in, persists per user, and can be muted', async () => {
    const h = soundHarness();
    h.sound.initialize(7);
    h.sound.play();
    assert.equal(h.tones, 0);
    await h.sound.toggle();
    assert.equal(h.tones, 2);
    assert.equal(h.values.get('wphub:order-sound:7'), 'on');
    h.sound.play();
    assert.equal(h.tones, 2, 'bursts do not overlap tones');
    await h.sound.toggle();
    h.sound.play(true);
    assert.equal(h.tones, 2);
    h.sound.initialize(8);
    assert.equal(h.sound.enabled.value, false);
});

test('blocked audio and unavailable storage do not break notifications', async () => {
    const h = soundHarness({ blocked: true, storageFails: true });
    h.sound.initialize(7);
    await h.sound.toggle();
    await h.sound.unlock();
    h.sound.play();
    assert.equal(h.tones, 0);
});

test('sound initialization is safe during server rendering', () => {
    const { useOrderSound } = load('composables/useOrderSound.ts');
    const sound = useOrderSound();
    sound.initialize(7);
    assert.equal(sound.available.value, false);
});
