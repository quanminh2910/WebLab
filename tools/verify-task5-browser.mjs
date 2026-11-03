// Local browser checks only: never creates accounts or changes application records.
import { spawn } from 'node:child_process';
import { mkdtemp, readFile, writeFile, mkdir, rm, lstat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as pause } from 'node:timers/promises';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const base = new URL(process.env.SPA_TEST_BASE_URL ?? 'http://localhost/Spa%20%26%20Beauty%20Center%20Website/');
if (!['localhost', '127.0.0.1', '[::1]'].includes(base.hostname) || !['http:', 'https:'].includes(base.protocol)) throw new Error('Only a local HTTP website can be checked.');
if (!base.pathname.endsWith('/')) base.pathname += '/';
const ensure = (condition, message) => { if (!condition) throw new Error(message); };
let browser, profile, socket, send, failure;
const pending = new Map();
const loadedLoaders = new Set();
let sequence = 0;

async function connect() {
    let port;
    for (let attempt = 0; attempt < 150; attempt++) {
        ensure(browser.exitCode === null, 'Chrome closed before verification began.');
        try { port = Number((await readFile(join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]); } catch { /* Await Chrome startup. */ }
        if (port > 0) break;
        await pause(100);
    }
    ensure(port, 'Chrome did not expose its local verification port.');
    const response = await fetch(`http://127.0.0.1:${port}/json/list`, { signal: AbortSignal.timeout(5000) });
    const target = (await response.json()).find(tab => tab.type === 'page');
    ensure(target?.webSocketDebuggerUrl, 'The browser verification tab was not created.');
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((done, reject) => {
        const timer = setTimeout(() => reject(new Error('Browser connection timed out.')), 10000);
        socket.addEventListener('open', () => { clearTimeout(timer); done(); }, { once: true });
        socket.addEventListener('error', () => { clearTimeout(timer); reject(new Error('Browser connection failed.')); }, { once: true });
    });
    socket.addEventListener('message', event => {
        const result = JSON.parse(event.data);
        if (result.method === 'Page.lifecycleEvent' && result.params.name === 'load') loadedLoaders.add(result.params.loaderId);
        const task = pending.get(result.id);
        if (!task) return;
        pending.delete(result.id); clearTimeout(task.timer);
        if (result.error) task.reject(new Error(`Browser command failed: ${task.method}`)); else task.done(result.result);
    });
    socket.addEventListener('close', () => {
        for (const task of pending.values()) { clearTimeout(task.timer); task.reject(new Error('Browser closed.')); }
        pending.clear();
    });
    send = (method, params = {}) => new Promise((done, reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Browser command timed out: ${method}`)); }, 20000);
        pending.set(id, { done, reject, timer, method }); socket.send(JSON.stringify({ id, method, params }));
    });
    await send('Page.enable'); await send('Network.enable');
    await send('Page.setLifecycleEventsEnabled', { enabled: true });
    await send('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1100, deviceScaleFactor: 1, mobile: false });
    await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'light' }] });
}

async function evaluate(expression) {
    const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    ensure(!result.exceptionDetails, 'A browser verification expression failed.');
    return result.result?.value;
}
async function ready(url, expectedLoader = null, previousLoader = null) {
    for (let attempt = 0; attempt < 200; attempt++) {
        const frame = (await send('Page.getFrameTree')).frameTree.frame;
        if (frame.loaderId && frame.loaderId !== previousLoader && (!expectedLoader || frame.loaderId === expectedLoader)
            && loadedLoaders.has(frame.loaderId)
            && await evaluate(`location.href === ${JSON.stringify(url)} && document.readyState === 'complete'`)) return;
        await pause(100);
    }
    throw new Error('The requested verification page did not finish loading.');
}
async function navigate(path) {
    const url = new URL(path, base).href;
    const navigation = await send('Page.navigate', { url });
    ensure(navigation.loaderId && !navigation.errorText, 'A new verification document did not begin navigation.');
    await ready(url, navigation.loaderId);
}
async function reload() {
    const previous = (await send('Page.getFrameTree')).frameTree.frame;
    await send('Page.reload', { ignoreCache: true });
    await ready(previous.url, null, previous.loaderId);
}
async function themeCookie() {
    const result = await send('Network.getCookies', { urls: [base.href] });
    // Select only this non-sensitive preference; never log or save session cookies.
    return result.cookies.find(cookie => cookie.name === 'spa_theme' && cookie.path === '/');
}
function checkCookie(cookie, value) {
    ensure(cookie?.value === value, 'The selected theme was not written to spa_theme.');
    ensure(cookie.path === '/' && cookie.sameSite === 'Lax' && cookie.httpOnly === false, 'Theme cookie attributes are incorrect.');
    ensure(cookie.secure === (base.protocol === 'https:'), 'Theme cookie Secure setting does not match HTTPS.');
    ensure(Math.abs(cookie.expires - Date.now() / 1000 - 2592000) < 120, 'Theme cookie must expire in approximately 30 days.');
}

try {
    profile = await mkdtemp(join(tmpdir(), 'spa-task5-browser-'));
    browser = spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
        '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-background-networking', '--disable-sync', '--remote-debugging-port=0',
        `--user-data-dir=${profile}`, '--window-size=1400,1100', 'about:blank',
    ], { cwd: root, windowsHide: true, stdio: 'ignore' });
    await new Promise((done, reject) => { browser.once('spawn', done); browser.once('error', () => reject(new Error('Could not launch Chrome.'))); });
    await connect(); await navigate('public/login.php');
    ensure(await evaluate('document.documentElement.dataset.theme') === 'light', 'A fresh browser should follow the emulated light system preference.');
    for (const value of ['dark', 'light', 'dark']) {
        await evaluate("document.querySelector('#theme-toggle').click()");
        ensure(await evaluate('document.documentElement.dataset.theme') === value, 'The theme toggle did not update the page.');
        checkCookie(await themeCookie(), value);
    }
    await reload();
    ensure(await evaluate('document.documentElement.dataset.theme') === 'dark', 'Reload did not preserve the selected theme.');
    await navigate('pages/item_details.php?service=swedish-massage');
    ensure(await evaluate('document.documentElement.dataset.theme') === 'dark', 'Details navigation did not preserve the selected theme.');
    for (const path of ['public/index.php', 'public/login.php', 'pages/item_details.php?service=swedish-massage']) {
        const response = await fetch(new URL(path, base), { headers: { Cookie: 'spa_theme=dark' }, signal: AbortSignal.timeout(10000) });
        ensure(response.status === 200 && /<html\b[^>]*\bdata-theme=["']dark["']/i.test(await response.text()), `PHP did not render the saved theme immediately on ${path}.`);
    }
    await evaluate("localStorage.removeItem('spa-theme')");
    await send('Network.setCookie', { name: 'spa_theme', value: 'invalid', url: base.href, path: '/', sameSite: 'Lax', secure: base.protocol === 'https:' });
    await navigate('public/login.php');
    ensure(await evaluate('document.documentElement.dataset.theme') === 'light', 'An invalid preference cookie should fall back to the system theme.');
    await send('Network.deleteCookies', { name: 'spa_theme', url: base.href, path: '/' });
    await evaluate("localStorage.setItem('spa-theme', 'dark')"); await navigate('public/login.php');
    ensure(await evaluate('document.documentElement.dataset.theme') === 'dark', 'A legacy saved preference did not migrate.');
    ensure(await evaluate("localStorage.getItem('spa-theme')") === null, 'Successful migration must remove the old preference key.');
    checkCookie(await themeCookie(), 'dark');
    await evaluate("localStorage.setItem('spa-theme', 'light')"); await navigate('public/login.php');
    ensure(await evaluate('document.documentElement.dataset.theme') === 'dark', 'A valid cookie must take priority over legacy storage.');
    ensure(await evaluate("Array.from(document.styleSheets).some(sheet => sheet.href?.includes('bootstrap@5.3.0'))"), 'Bootstrap did not load; refusing incomplete visual evidence.');
    await evaluate('document.fonts.ready.then(() => true)');
    const metrics = await send('Page.getLayoutMetrics');
    const image = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, fromSurface: true,
        clip: { x: 0, y: 0, width: 1400, height: Math.max(1100, Math.ceil((metrics.cssContentSize ?? metrics.contentSize).height)), scale: 1 } });
    await mkdir(join(root, 'docs', 'screenshots'), { recursive: true });
    await writeFile(join(root, 'docs', 'screenshots', 'task5-dark-mode.png'), Buffer.from(image.data, 'base64'));
    const cookie = await themeCookie(); checkCookie(cookie, 'dark');
    console.log(JSON.stringify({ name: cookie.name, value: cookie.value, expirySeconds: Math.round(cookie.expires - Date.now() / 1000), path: cookie.path, sameSite: cookie.sameSite, httpOnly: cookie.httpOnly, secure: cookie.secure }));
    console.log('PASS: theme toggle, cookie attributes, reload, details, PHP first paint, invalid-cookie fallback, legacy migration, and cookie priority. Saved docs/screenshots/task5-dark-mode.png');
} catch (error) { failure = error; }
finally {
    if (send) { try { await send('Browser.close'); } catch { /* CDP may close before replying. */ } socket.close(); }
    if (browser && browser.exitCode === null) { browser.kill(); await Promise.race([new Promise(done => browser.once('exit', done)), pause(2000)]); }
    if (profile) {
        try {
            ensure(dirname(resolve(profile)) === resolve(tmpdir()) && /^spa-task5-browser-[A-Za-z0-9]+$/.test(basename(profile)), 'Unsafe temporary profile path.');
            const info = await lstat(profile); ensure(info.isDirectory() && !info.isSymbolicLink(), 'Unsafe temporary profile type.');
            await rm(profile, { recursive: true, force: true, maxRetries: 6, retryDelay: 500 });
        } catch { failure ??= new Error('The isolated temporary browser profile could not be removed.'); }
    }
}
if (failure) { console.error(failure.message); process.exitCode = 1; }
