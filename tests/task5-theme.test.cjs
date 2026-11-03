const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/js/script.js'), 'utf8');
const startTime = Date.UTC(2026, 9, 23, 12);
const month = 30 * 24 * 60 * 60 * 1000;

function boot(options = {}) {
    const clock = options.clock || { now: startTime };
    const jar = options.jar || new Map([['spa_session', { value: 'unchanged-session' }]]);
    for (const [name, value] of Object.entries(options.cookies || {})) {
        jar.set(name, typeof value === 'string' ? { value } : value);
    }
    const storage = new Map([['spa-favorites', '["Swedish Massage"]']]);
    if (options.legacy !== undefined) storage.set('spa-theme', options.legacy);
    const storageWrites = [], removals = [], cookieWrites = [], ready = [];
    const elements = Object.fromEntries(['#theme-toggle', '#theme-icon', '#theme-label'].map(id => [id, {
        attributes: {}, listeners: {}, textContent: '',
        setAttribute(name, value) { this.attributes[name] = value; },
        addEventListener(name, handler) { this.listeners[name] = handler; },
    }]));
    const document = {
        documentElement: { dataset: {} },
        querySelector: selector => elements[selector] || null,
        querySelectorAll: () => [],
        addEventListener(name, handler) { if (name === 'DOMContentLoaded') ready.push(handler); },
    };
    Object.defineProperty(document, 'cookie', {
        get() {
            if (options.cookiesBlocked) throw new Error('Cookies unavailable');
            return [...jar].filter(([, item]) => item.expires === undefined || item.expires > clock.now)
                .map(([name, item]) => `${name}=${item.value}`).join('; ');
        },
        set(cookie) {
            cookieWrites.push(cookie);
            if (options.cookiesBlocked) throw new Error('Cookies unavailable');
            const [pair, ...flags] = cookie.split(';').map(part => part.trim());
            const separator = pair.indexOf('=');
            const name = pair.slice(0, separator), value = pair.slice(separator + 1);
            const attributes = Object.fromEntries(flags.map(flag => {
                const index = flag.indexOf('=');
                return index < 0 ? [flag.toLowerCase(), true] : [flag.slice(0, index).toLowerCase(), flag.slice(index + 1)];
            }));
            const expires = attributes['max-age'] !== undefined
                ? clock.now + Number(attributes['max-age']) * 1000
                : attributes.expires ? Date.parse(attributes.expires) : undefined;
            jar.set(name, { value, expires });
        },
    });
    const location = { protocol: options.https ? 'https:' : 'http:' };
    const window = { location, matchMedia: () => ({ matches: Boolean(options.prefersDark) }) };
    Object.defineProperty(window, 'localStorage', {
        get() {
            if (options.storageBlocked) throw new Error('Storage unavailable');
            return {
                getItem: key => storage.get(key) ?? null,
                setItem(key, value) { storageWrites.push([key, value]); storage.set(key, value); },
                removeItem(key) { removals.push(key); storage.delete(key); },
            };
        },
    });
    class ClockDate extends Date {
        constructor(...args) { super(...(args.length ? args : [clock.now])); }
        static now() { return clock.now; }
    }
    const context = vm.createContext({ document, window, location, Date: ClockDate, alert() {} });
    vm.runInContext(source, context, { filename: 'public/js/script.js' });
    ready.forEach(handler => handler());
    return {
        context, clock, jar, storage, storageWrites, removals, cookieWrites,
        toggle: () => elements['#theme-toggle'].listeners.click(),
        state: () => ({
            theme: document.documentElement.dataset.theme,
            pressed: elements['#theme-toggle'].attributes['aria-pressed'],
            label: elements['#theme-label'].textContent,
            ariaLabel: elements['#theme-toggle'].attributes['aria-label'],
            icon: elements['#theme-icon'].textContent,
        }),
    };
}

function checkState(app, theme) {
    const dark = theme === 'dark';
    assert.deepEqual(app.state(), {
        theme, pressed: String(dark), label: dark ? 'Light mode' : 'Dark mode',
        ariaLabel: dark ? 'Switch to light mode' : 'Switch to dark mode', icon: dark ? '☀' : '☾',
    });
}

test('valid decoded cookie wins over legacy storage and system preference', () => {
    const app = boot({ cookies: { spa_theme: '%64ark' }, legacy: 'light' });
    checkState(app, 'dark');
    assert.equal(app.cookieWrites.length, 0);
    assert.equal(app.storage.get('spa-favorites'), '["Swedish Massage"]');
});

test('valid legacy preference migrates once and remains available on the next page', () => {
    const app = boot({ legacy: 'light', prefersDark: true });
    checkState(app, 'light');
    assert.equal(app.cookieWrites.length, 1);
    assert.deepEqual(app.removals, ['spa-theme']);
    assert.equal(app.storage.has('spa-theme'), false);
    const next = boot({ jar: app.jar, clock: app.clock, prefersDark: true });
    checkState(next, 'light');
    assert.equal(next.cookieWrites.length, 0);
    assert.deepEqual(next.removals, []);
});

test('malformed and invalid cookie values fall back to a valid legacy value', () => {
    for (const value of ['blue', '%E0%A4%A', 'DARK', 'dark%3Brole%3Dadmin']) {
        const app = boot({ cookies: { spa_theme: value }, legacy: 'dark' });
        checkState(app, 'dark');
        assert.equal(app.cookieWrites.length, 1);
        assert.equal(app.jar.get('spa_theme').value, 'dark');
    }
});

test('missing or invalid preferences use the current system setting', () => {
    for (const prefersDark of [false, true]) {
        const app = boot({ legacy: 'not-a-theme', cookies: { other_spa_theme: 'dark', spa_theme: 'invalid' }, prefersDark });
        checkState(app, prefersDark ? 'dark' : 'light');
        assert.equal(app.cookieWrites.length, 0);
    }
});

test('toggle persists only the theme for 30 days with appropriate cookie flags', () => {
    for (const https of [false, true]) {
        const app = boot({ https });
        app.toggle();
        checkState(app, 'dark');
        const cookie = app.cookieWrites[0];
        assert.match(cookie, /^spa_theme=dark(?:;|$)/);
        assert.match(cookie, /(?:^|;\s*)Max-Age=2592000(?:;|$)/i);
        assert.match(cookie, /(?:^|;\s*)Path=\/(?:;|$)/i);
        assert.match(cookie, /(?:^|;\s*)SameSite=Lax(?:;|$)/i);
        assert.equal(/(?:^|;\s*)Secure(?:;|$)/i.test(cookie), https);
        const expires = cookie.match(/(?:^|;\s*)Expires=([^;]+)/i);
        assert.ok(expires && Math.abs(Date.parse(expires[1]) - (startTime + month)) < 1000);
        assert.equal(app.jar.get('spa_session').value, 'unchanged-session');
        assert.equal(app.storage.get('spa-favorites'), '["Swedish Massage"]');
        assert.deepEqual(app.storageWrites, []);
        app.toggle();
        checkState(app, 'light');
        assert.equal(app.jar.get('spa_theme').value, 'light');
        assert.ok(app.cookieWrites.every(write => write.startsWith('spa_theme=')));
    }
});

test('expired theme cookie falls back to the system preference on a later visit', () => {
    const app = boot();
    app.toggle();
    app.clock.now += month + 1000;
    const later = boot({ jar: app.jar, clock: app.clock });
    checkState(later, 'light');
    assert.equal(later.cookieWrites.length, 0);
});

test('blocked browser storage and cookies preserve in-page theme interaction', () => {
    for (const options of [{ cookiesBlocked: true }, { storageBlocked: true }, { cookiesBlocked: true, storageBlocked: true }]) {
        const app = boot({ ...options, prefersDark: true });
        checkState(app, 'dark');
        assert.doesNotThrow(() => app.toggle());
        checkState(app, 'light');
        assert.equal(app.jar.get('spa_session').value, 'unchanged-session');
        assert.equal(app.storage.get('spa-favorites'), '["Swedish Massage"]');
    }
});

test('theme persistence helpers reject values outside the dark/light allowlist', () => {
    const app = boot();
    for (const value of ['admin', '', 'dark; Path=/admin', null, {}]) {
        app.context.rememberTheme(value);
    }
    assert.equal(app.cookieWrites.length, 0);
});
