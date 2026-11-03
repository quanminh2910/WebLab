// Native Chrome DevTools evidence; never renders a simulated inspector or credential panel.
import { spawn, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdtemp, readFile, writeFile, mkdir, rm, lstat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as pause } from 'node:timers/promises';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const base = new URL(process.env.SPA_TEST_BASE_URL ?? 'http://localhost/Spa%20%26%20Beauty%20Center%20Website/');
if (!['localhost', '127.0.0.1', '[::1]'].includes(base.hostname) || !['http:', 'https:'].includes(base.protocol)) throw new Error('Only the local XAMPP site may be used.');
if (!base.pathname.endsWith('/')) base.pathname += '/';
const probeOnly = process.argv.includes('--probe-devtools');
const captureAuthenticated = process.argv.includes('--capture-authenticated');
const output = join(root, 'docs', 'screenshots');
const ensure = (condition, message) => { if (!condition) throw new Error(message); };
const hiddenSecrets = new Set();
const email = `task5-evidence-${randomBytes(16).toString('hex')}@example.test`;
const password = randomBytes(32).toString('base64url');
hiddenSecrets.add(password);

// This inspection/cleanup bridge receives only the exact random email and observed id.
const fixtureCode = String.raw`
try {
    require getcwd() . '/includes/database.php';
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $email = $input['email'] ?? '';
    if (!is_string($email) || !preg_match('/\Atask5-evidence-[a-f0-9]{32}@example\.test\z/', $email)) throw new RuntimeException();
    $pdo = databaseConnection();
    $find = $pdo->prepare('SELECT id, role FROM users WHERE email = :email');
    $find->execute(['email' => $email]);
    $row = $find->fetch();
    if (($input['operation'] ?? '') === 'inspect') {
        echo json_encode($row ?: null, JSON_THROW_ON_ERROR);
    } elseif (($input['operation'] ?? '') === 'cleanup') {
        if ($row !== false) {
            if ($row['role'] !== 'customer' || (string) $row['id'] !== (string) ($input['id'] ?? '')) throw new RuntimeException();
            $delete = $pdo->prepare('DELETE FROM users WHERE id = :id AND email = :email AND role = :role');
            $delete->execute(['id' => $row['id'], 'email' => $email, 'role' => 'customer']);
            if ($delete->rowCount() !== 1) throw new RuntimeException();
        }
        echo 'null';
    } else throw new RuntimeException();
} catch (Throwable $error) {
    fwrite(STDERR, 'Temporary evidence account verification failed.' . PHP_EOL); exit(1);
}`;
function fixture(operation, id = null) {
    return JSON.parse(execFileSync('D:\\xamp\\php\\php.exe', ['-r', fixtureCode], {
        cwd: root, input: JSON.stringify({ operation, email, id }), encoding: 'utf8', windowsHide: true,
        stdio: ['pipe', 'pipe', 'pipe'], timeout: 15000,
    }));
}

async function submitNativeForm(app, path, fields, destination) {
    await app.navigate(new URL(`public/${path}`, base).href);
    const previous = (await app.send('Page.getFrameTree')).frameTree.frame.loaderId;
    const submitted = await app.evaluate(`(() => {
        const form = document.querySelector('form');
        if (!form || form.method.toLowerCase() !== 'post') return false;
        for (const [name, value] of Object.entries(${JSON.stringify(fields)})) {
            const input = form.elements.namedItem(name); if (!input) return false;
            input.value = value; input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (!form.checkValidity()) return false;
        form.requestSubmit(); return true;
    })()`);
    ensure(submitted, 'The normal authentication form was not ready for submission.');
    await app.ready(new URL(`public/${destination}`, base).href, null, previous);
    await browserCookies(app);
}

class Commands {
    constructor(socket) {
        this.socket = socket; this.pending = new Map(); this.sequence = 0; this.loaded = new Set();
        socket.addEventListener('message', event => {
            const result = JSON.parse(event.data);
            if (result.method === 'Page.lifecycleEvent' && result.params.name === 'load') this.loaded.add(result.params.loaderId);
            const task = this.pending.get(result.id); if (!task) return;
            this.pending.delete(result.id); clearTimeout(task.timer);
            if (result.error) task.reject(new Error(`Chrome command failed: ${task.method}`)); else task.done(result.result);
        });
        socket.addEventListener('close', () => {
            for (const task of this.pending.values()) { clearTimeout(task.timer); task.reject(new Error('Chrome connection closed.')); }
            this.pending.clear();
        });
    }
    static async open(url) {
        const socket = new WebSocket(url);
        await new Promise((done, reject) => {
            const timer = setTimeout(() => reject(new Error('Chrome connection timed out.')), 15000);
            socket.addEventListener('open', () => { clearTimeout(timer); done(); }, { once: true });
            socket.addEventListener('error', () => { clearTimeout(timer); reject(new Error('Chrome connection failed.')); }, { once: true });
        });
        return new Commands(socket);
    }
    send(method, params = {}) {
        return new Promise((done, reject) => {
            const id = ++this.sequence;
            const timer = setTimeout(() => { this.pending.delete(id); reject(new Error(`Chrome command timed out: ${method}`)); }, 25000);
            this.pending.set(id, { done, reject, timer, method }); this.socket.send(JSON.stringify({ id, method, params }));
        });
    }
    async evaluate(expression) {
        const result = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) {
            const diagnostic = await this.send('Runtime.evaluate', { expression: 'globalThis.__task5Phase', returnByValue: true });
            const phase = String(diagnostic.result?.value ?? 'unknown').replace(/[^a-z-]/g, '').slice(0, 40);
            const className = result.exceptionDetails.exception?.className;
            const kind = /^[A-Za-z]*Error$/.test(className ?? '') ? className : 'Error';
            throw new Error(`Native browser inspection failed at ${phase} (${kind}); no page contents were logged.`);
        }
        return result.result?.value;
    }
    async enable(width = 1400, height = 1100) {
        await this.send('Page.enable'); await this.send('Network.enable');
        await this.send('Page.setLifecycleEventsEnabled', { enabled: true });
        await this.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
    }
    async ready(url, expected = null, previous = null) {
        for (let attempt = 0; attempt < 250; attempt++) {
            const frame = (await this.send('Page.getFrameTree')).frameTree.frame;
            if (frame.loaderId !== previous && (!expected || frame.loaderId === expected) && this.loaded.has(frame.loaderId)
                && await this.evaluate(`location.href === ${JSON.stringify(url)} && document.readyState === 'complete'`)) return;
            await pause(100);
        }
        throw new Error('The requested page did not finish loading.');
    }
    async navigate(url) {
        const navigation = await this.send('Page.navigate', { url });
        ensure(navigation.loaderId && !navigation.errorText, 'A new browser document did not begin navigation.');
        await this.ready(url, navigation.loaderId);
    }
    async reload() {
        const frame = (await this.send('Page.getFrameTree')).frameTree.frame;
        await this.send('Page.reload', { ignoreCache: true }); await this.ready(frame.url, null, frame.loaderId);
    }
    async screenshot(filename, width) {
        await this.send('Page.bringToFront');
        await this.send('Overlay.setShowViewportSizeOnResize', { show: false });
        await this.evaluate('document.fonts.ready.then(() => true)');
        await pause(1100); // Let Chrome's transient viewport-size badge fade.
        const dimensions = await this.send('Page.getLayoutMetrics');
        const size = dimensions.cssContentSize ?? dimensions.contentSize;
        const image = await this.send('Page.captureScreenshot', { format: 'png', fromSurface: true, captureBeyondViewport: true,
            clip: { x: 0, y: 0, width, height: Math.max(1100, Math.ceil(size.height)), scale: 1 } });
        await writeFile(join(output, filename), Buffer.from(image.data, 'base64'));
        console.log(`Saved docs/screenshots/${filename}`);
    }
}

async function startBrowser(profile) {
    const process = spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
        '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-background-networking',
        '--disable-sync', '--remote-debugging-port=0', '--remote-allow-origins=devtools://devtools', `--user-data-dir=${profile}`, 'about:blank',
    ], { windowsHide: true, stdio: 'ignore', cwd: root });
    await new Promise((done, reject) => { process.once('spawn', done); process.once('error', () => reject(new Error('Chrome could not be started.'))); });
    for (let attempt = 0; attempt < 150; attempt++) {
        ensure(process.exitCode === null, 'Chrome closed during startup.');
        let port;
        try { port = Number((await readFile(join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]); } catch { /* Await isolated Chrome. */ }
        if (port > 0) return { process, port };
        await pause(100);
    }
    process.kill(); throw new Error('Chrome did not expose its local debugging port.');
}

async function browserCookies(app) {
    const result = await app.send('Network.getCookies', { urls: [base.href] });
    for (const cookie of result.cookies) if (cookie.name === 'spa_session') hiddenSecrets.add(cookie.value);
    return result.cookies;
}
function themeMetadata(cookie) {
    ensure(cookie?.name === 'spa_theme' && cookie.value === 'dark', 'A genuine dark preference cookie is required.');
    ensure(cookie.path === '/' && cookie.sameSite === 'Lax' && cookie.httpOnly === false && cookie.secure === (base.protocol === 'https:'), 'The preference cookie attributes are incorrect.');
    const remainingSeconds = Math.round(cookie.expires - Date.now() / 1000);
    ensure(Math.abs(remainingSeconds - 2592000) < 300, 'The preference must expire approximately 30 days after selection.');
    return { name: cookie.name, value: cookie.value, domain: cookie.domain, path: cookie.path,
        expiresUTC: new Date(cookie.expires * 1000).toISOString(), remainingSeconds, sameSite: cookie.sameSite, httpOnly: cookie.httpOnly, secure: cookie.secure };
}

async function prepareInspector(front) {
    const status = await front.evaluate(`(async () => {
        globalThis.__task5Nodes = root => {
            const roots = [root], nodes = [];
            for (let i = 0; i < roots.length; i++) {
                for (const node of roots[i].querySelectorAll('*')) {
                    nodes.push(node); if (node.shadowRoot) roots.push(node.shadowRoot);
                }
            }
            return nodes;
        };
        globalThis.__task5Phase = 'native-modules';
        const UI = await import('devtools://devtools/bundled/ui/legacy/legacy.js');
        const Application = await import('devtools://devtools/bundled/panels/application/application.js');
        const SDK = await import('devtools://devtools/bundled/core/sdk/sdk.js');
        globalThis.__task5Phase = 'application-panel';
        await UI.ViewManager.ViewManager.instance().showView('resources');
        const target = SDK.TargetManager.TargetManager.instance().primaryPageTarget();
        const panel = Application.ResourcesPanel.ResourcesPanel.instance();
        if (!target || typeof panel.showCookies !== 'function') return { reason: 'application-target-not-ready' };
        panel.showCookies(target, ${JSON.stringify(base.origin)});
        globalThis.__task5CookiePanel = panel;
        globalThis.__task5Phase = 'screencast-button';
        if (!globalThis.__task5ScreencastDisabled) {
            let toggle;
            for (let attempt = 0; attempt < 50; attempt++) {
                toggle = __task5Nodes(document).find(node => /toggle.*screencast|screencast.*toggle/i.test(
                    (node.getAttribute('title') || '') + ' ' + (node.getAttribute('aria-label') || '') + ' ' + (node.getAttribute('data-tooltip-content') || '')));
                if (toggle) break;
                await new Promise(done => setTimeout(done, 100));
            }
            if (toggle) {
                (toggle.closest('button, [role="button"]') || toggle).click();
                globalThis.__task5ScreencastDisabled = true;
            } else {
                const Screencast = await import('devtools://devtools/bundled/panels/screencast/screencast.js');
                const Type = Screencast.ScreencastApp?.ScreencastApp || Screencast.ScreencastApp;
                const instance = typeof Type?.instance === 'function' ? Type.instance() : null;
                return { reason: 'native-screencast-toggle-not-found', moduleNames: Object.keys(Screencast),
                    methodNames: instance ? Object.getOwnPropertyNames(Object.getPrototypeOf(instance)) : [] };
            }
        }
        return true;
    })()`);
    ensure(status === true, 'Native inspector setup did not finish: ' + JSON.stringify(status));
    let filtered = false;
    for (let attempt = 0; attempt < 100; attempt++) {
        filtered = await front.evaluate(`(() => {
            globalThis.__task5Phase = 'native-filter';
            const view = __task5CookiePanel.visibleView;
            if (!view?.element) return false;
            const nodes = __task5Nodes(view.element);
            const input = nodes.find(node => node.matches('devtools-toolbar-input[type="filter"]'));
            if (!input) return false;
            if (input.item?.setValue) input.item.setValue('spa_theme', true); else input.value = 'spa_theme';
            input.dispatchEvent(new CustomEvent('change', { detail: 'spa_theme' }));
            return true;
        })()`);
        if (filtered) break;
        await pause(100);
    }
    ensure(filtered, 'The native Cookies filter control could not be found.');
    await front.evaluate(`(() => {
        const view = __task5CookiePanel.visibleView;
        const tables = [view.cookiesTable, view.cookieTable, view._cookiesTable, view.view];
        const grids = tables.filter(Boolean).flatMap(table => [table.dataGrid, table._dataGrid, table]);
        const grid = grids.find(candidate => typeof candidate?.autoSizeColumns === 'function');
        if (grid) {
            grid.autoSizeColumns(5, 35);
            return { resized: true };
        }
        const gridsInDOM = __task5Nodes(view.element).filter(node => /data-grid/i.test(node.tagName));
        const nativeGrid = gridsInDOM.find(node => node.tagName === 'DEVTOOLS-DATA-GRID');
        if (!nativeGrid) return { resized: false };
        nativeGrid.resizeMethod = 'last';
        return true;
    })()`);
    // Use the actual column-resizer handles; do not alter displayed cookie data.
    for (const [index, width] of [[0, 150], [1, 100], [4, 310]]) {
        const resize = await front.evaluate(`(() => {
            const view = __task5CookiePanel.visibleView.element;
            const handles = __task5Nodes(view).filter(node => node.classList.contains('data-grid-resizer'));
            const handle = handles[${index}]?.getBoundingClientRect(); if (!handle) return null;
            const x = handle.x + handle.width / 2;
            const left = ${index} === 0 ? view.getBoundingClientRect().left : (() => {
                const previous = handles[${index} - 1].getBoundingClientRect(); return previous.x + previous.width / 2;
            })();
            return { x, y: handle.y + 12, delta: ${width} - (x - left) };
        })()`);
        ensure(resize, 'The native cookie column-resizer handle was not available.');
        if (Math.abs(resize.delta) < 3) continue;
        const start = { x: resize.x, y: resize.y }, end = { x: resize.x + resize.delta, y: resize.y };
        await front.send('Input.dispatchMouseEvent', { type: 'mouseMoved', ...start });
        await front.send('Input.dispatchMouseEvent', { type: 'mousePressed', button: 'left', buttons: 1, clickCount: 1, ...start });
        await front.send('Input.dispatchMouseEvent', { type: 'mouseMoved', buttons: 1, ...end });
        await front.send('Input.dispatchMouseEvent', { type: 'mouseReleased', button: 'left', clickCount: 1, ...end });
    }
    for (let attempt = 0; attempt < 100; attempt++) {
        const selected = await front.evaluate(`(() => {
            globalThis.__task5Phase = 'native-row-selection';
            const nodes = __task5Nodes(__task5CookiePanel.visibleView.element);
            const cell = nodes.find(node => node.matches('td') && node.textContent.trim() === 'spa_theme');
            if (!cell) return false;
            const bounds = cell.getBoundingClientRect();
            return { x: bounds.left + bounds.width / 2, y: bounds.top + bounds.height / 2 };
        })()`);
        if (selected) {
            await front.send('Page.bringToFront');
            await front.send('Input.dispatchMouseEvent', { type: 'mousePressed', button: 'left', clickCount: 1, ...selected });
            await front.send('Input.dispatchMouseEvent', { type: 'mouseReleased', button: 'left', clickCount: 1, ...selected });
            return;
        }
        await pause(100);
    }
    throw new Error('The filtered preference-cookie row was not rendered by native DevTools.');
}

async function assertPrivateInspector(front) {
    const result = await front.evaluate(`(() => {
        globalThis.__task5Phase = 'privacy-and-columns';
        const forbidden = ${JSON.stringify(['spa_session', ...hiddenSecrets])};
        const nodes = __task5Nodes(document).filter(node => node.getClientRects().length && getComputedStyle(node).visibility !== 'hidden');
        const texts = nodes.flatMap(node => [
            Array.from(node.childNodes).filter(child => child.nodeType === Node.TEXT_NODE).map(child => child.textContent).join(' '),
            typeof node.value === 'string' ? node.value : '', node.getAttribute('title') || '', node.getAttribute('aria-label') || ''
        ]);
        const safe = !texts.some(text => forbidden.some(secret => secret && text.includes(secret)));
        const filter = nodes.find(node => node.matches('devtools-toolbar-input[type="filter"]'));
        const themeVisible = nodes.some(node => node.matches('td') && node.textContent.trim() === 'spa_theme');
        const filterValue = typeof filter?.value === 'string' ? filter.value
            : typeof filter?.item?.value === 'function' ? filter.item.value() : null;
        const view = __task5CookiePanel.visibleView.element.getBoundingClientRect();
        const headers = nodes.filter(node => node.matches('th, [role="columnheader"]'));
        const labels = [/name/i, /value/i, /^path$/i, /expires/i, /http.?only/i, /^secure$/i, /same.?site/i];
        const columnsVisible = labels.every(label => headers.some(header => {
            const rect = header.getBoundingClientRect();
            return label.test(header.textContent.trim()) && rect.width > 0 && rect.left >= 0 && rect.right <= innerWidth + 1;
        }));
        return { safe, themeVisible, filtered: filterValue === 'spa_theme', wideEnough: view.width >= 1200, columnsVisible };
    })()`);
    ensure(result.safe && result.themeVisible && result.filtered, 'Privacy validation rejected the inspector capture; no screenshot was saved.');
    ensure(result.wideEnough && result.columnsVisible, 'Cookie inspection columns are not readable across the native panel; no screenshot was saved.');
}

let profile, processHandle, browser, app, front;
let failure = null;
let confirmedAbsent = false, attemptedRegistration = false, fixtureId = null;
let loggedIn = false, lastAuthenticatedSession = null, lastLogoutToken = null;
const summaries = {};
const cleanupErrors = [];
try {
    ensure(probeOnly !== captureAuthenticated, 'Choose --probe-devtools or --capture-authenticated.');
    await mkdir(output, { recursive: true });
    profile = await mkdtemp(join(tmpdir(), 'spa-task5-evidence-'));
    const started = await startBrowser(profile); processHandle = started.process;
    const version = await (await fetch(`http://127.0.0.1:${started.port}/json/version`)).json();
    browser = await Commands.open(version.webSocketDebuggerUrl);
    const targets = await (await fetch(`http://127.0.0.1:${started.port}/json/list`)).json();
    const page = targets.find(target => target.type === 'page'); ensure(page, 'The application tab was not created.');
    app = await Commands.open(page.webSocketDebuggerUrl); await app.enable();
    await app.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'light' }] });
    await app.navigate(new URL('public/login.php', base).href);
    await app.evaluate("document.querySelector('#theme-toggle').click()");
    const originalCookie = (await browserCookies(app)).find(cookie => cookie.name === 'spa_theme');
    themeMetadata(originalCookie);
    const inspectorURL = `devtools://devtools/bundled/inspector.html?ws=127.0.0.1:${started.port}/devtools/page/${page.id}`;
    const created = await browser.send('Target.createTarget', { url: inspectorURL });
    front = await Commands.open(`ws://127.0.0.1:${started.port}/devtools/page/${created.targetId}`); await front.enable(1800, 1100);
    for (let attempt = 0; attempt < 150; attempt++) {
        if (await front.evaluate("document.readyState === 'complete'")) break;
        await pause(100);
    }
    const capture = async stage => {
        const cookie = (await browserCookies(app)).find(item => item.name === 'spa_theme');
        ensure(cookie?.value === originalCookie.value && cookie?.expires === originalCookie.expires, 'Navigation or logout changed the saved preference or its original expiry.');
        summaries[stage] = { capturedAtUTC: new Date().toISOString(), ...themeMetadata(cookie) };
        await prepareInspector(front); await assertPrivateInspector(front);
        await front.screenshot(`task5-cookie-${stage}.png`, 1800);
        const safePage = await app.evaluate(`(() => {
            const secrets = ${JSON.stringify([...hiddenSecrets])};
            const texts = [document.body.innerText, ...Array.from(document.querySelectorAll('input:not([type="hidden"])')).map(input => input.value)];
            return document.documentElement.dataset.theme === 'dark' && !texts.some(text => secrets.some(secret => secret && text.includes(secret)));
        })()`);
        ensure(safePage, 'The page capture did not meet theme/privacy checks.');
        await app.screenshot(`task5-page-${stage}.png`, 1400);
    };
    await capture('stored');
    if (captureAuthenticated) {
        await app.reload();
        ensure(await app.evaluate('document.documentElement.dataset.theme') === 'dark', 'Reload did not preserve the selected theme.');
        await app.navigate(new URL('pages/item_details.php?service=swedish-massage', base).href);
        await capture('persisted');

        ensure(fixture('inspect') === null, 'The random evidence email must not already exist.');
        confirmedAbsent = true; attemptedRegistration = true;
        await submitNativeForm(app, 'register.php', { full_name: 'Cookie Evidence Customer', email, password, password_confirmation: password }, 'login.php');
        const registered = fixture('inspect'); ensure(registered?.role === 'customer', 'Normal registration did not create the temporary customer.');
        fixtureId = registered.id;
        const anonymousSession = (await browserCookies(app)).find(cookie => cookie.name === 'spa_session')?.value;
        await submitNativeForm(app, 'login.php', { email, password }, 'dashboard.php');
        loggedIn = true;
        lastAuthenticatedSession = (await browserCookies(app)).find(cookie => cookie.name === 'spa_session')?.value;
        ensure(lastAuthenticatedSession && lastAuthenticatedSession !== anonymousSession, 'Normal login did not rotate the authentication session.');
        lastLogoutToken = await app.evaluate("document.querySelector('input[name=csrf_token]')?.value");
        if (lastLogoutToken) hiddenSecrets.add(lastLogoutToken);
        ensure(await app.evaluate(`document.querySelector('.auth-dashboard')?.textContent.includes(${JSON.stringify(email)}) === true`), 'The temporary customer did not reach its authenticated dashboard.');
        await submitNativeForm(app, 'logout.php', {}, 'login.php?logged_out=1');
        loggedIn = false;
        const navigation = await app.send('Page.navigate', { url: new URL('public/dashboard.php', base).href });
        await app.ready(new URL('public/login.php', base).href, navigation.loaderId);
        ensure(await app.evaluate("!document.querySelector('.auth-dashboard') && !!document.querySelector('#login-heading')"), 'The protected dashboard did not redirect to login after logout.');
        await capture('after-logout');
    }
} catch (error) { failure = error; }
finally {
    if (loggedIn) {
        try {
            ensure(lastAuthenticatedSession && lastLogoutToken, 'A normal logout token is required for cleanup.');
            const result = await fetch(new URL('public/logout.php', base), {
                method: 'POST', redirect: 'manual', headers: { Cookie: `spa_session=${lastAuthenticatedSession}` },
                body: new URLSearchParams({ csrf_token: lastLogoutToken }), signal: AbortSignal.timeout(10000),
            });
            ensure(result.status === 303, 'Normal session cleanup failed.');
        } catch { cleanupErrors.push('The temporary authentication session could not be explicitly closed.'); }
    }
    if (confirmedAbsent && attemptedRegistration) {
        try {
            const remaining = fixture('inspect');
            if (remaining !== null) {
                ensure(remaining.role === 'customer', 'Refusing cleanup after the fixture role changed.');
                if (fixtureId === null) fixtureId = remaining.id;
                ensure(String(remaining.id) === String(fixtureId), 'Refusing cleanup after the fixture id changed.');
                fixture('cleanup', fixtureId);
            }
            ensure(fixture('inspect') === null, 'The temporary evidence account remains.');
        } catch { cleanupErrors.push('Exact temporary-customer cleanup failed; inspect the task5-evidence address before retrying.'); }
    }
    if (browser) { try { await browser.send('Browser.close'); } catch { /* Closing Chrome may close CDP before its reply. */ } }
    for (const client of [app, front, browser]) client?.socket.close();
    if (processHandle && processHandle.exitCode === null) { processHandle.kill(); await Promise.race([new Promise(done => processHandle.once('exit', done)), pause(2000)]); }
    if (profile) {
        try {
            ensure(dirname(resolve(profile)) === resolve(tmpdir()) && /^spa-task5-evidence-[A-Za-z0-9]+$/.test(basename(profile)), 'Unsafe temporary browser profile path.');
            const info = await lstat(profile); ensure(info.isDirectory() && !info.isSymbolicLink(), 'Unsafe temporary browser profile type.');
            await rm(profile, { recursive: true, force: true, maxRetries: 6, retryDelay: 500 });
        } catch { failure ??= new Error('The isolated evidence browser profile could not be removed.'); }
    }
}
if (failure || cleanupErrors.length) {
    console.error(failure ? failure.message : 'Evidence cleanup did not finish.');
    for (const message of cleanupErrors) console.error(message);
    process.exitCode = 1;
} else if (captureAuthenticated) {
    await writeFile(join(output, 'task5-cookie-metadata.json'), JSON.stringify(summaries, null, 2) + '\n');
    console.log('Three native-cookie/page evidence pairs saved; normal registration/login/logout verified and temporary customer/profile removed.');
} else console.log(JSON.stringify(summaries.stored));
