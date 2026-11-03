// CLI evidence capture; add --task6 for customer/admin authorization evidence.
// Credentials stay in memory and all disposable accounts are removed.
import { spawn, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdtemp, readFile, writeFile, mkdir, rm, lstat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as pause } from 'node:timers/promises';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const base = new URL(process.env.SPA_TEST_BASE_URL ?? 'http://localhost/Spa%20%26%20Beauty%20Center%20Website/');
if (!['localhost', '127.0.0.1', '[::1]'].includes(base.hostname)) {
    throw new Error('Evidence capture is restricted to the local XAMPP website.');
}
if (!base.pathname.endsWith('/')) base.pathname += '/';
const output = join(root, 'docs', 'screenshots');
const task6Mode = process.argv.includes('--task6');
const evidenceTask = task6Mode ? 'task6' : 'task4';
const email = `${evidenceTask}-evidence-${randomBytes(16).toString('hex')}@example.test`;
const adminEmail = email.replace('@example.test', '-admin@example.test');
const password = randomBytes(32).toString('base64url');
const adminPassword = randomBytes(32).toString('base64url');
const jar = new Map();
const ensure = (condition, message) => { if (!condition) throw new Error(message); };

// Only the exact random email and its observed id can be removed.
// The inspection/cleanup helper never receives a password.
const databaseCode = String.raw`
try {
    require getcwd() . '/includes/database.php';
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $email = $input['email'] ?? '';
    if (!is_string($email) || !preg_match('/\Atask[46]-evidence-[a-f0-9]{32}(?:-admin)?@example\.test\z/', $email)) {
        throw new RuntimeException('Invalid fixture.');
    }
    $expectedRole = str_ends_with($email, '-admin@example.test') ? 'admin' : 'customer';
    $pdo = databaseConnection();
    $statement = $pdo->prepare('SELECT id, role FROM users WHERE email = :email');
    $statement->execute(['email' => $email]);
    $row = $statement->fetch();
    if (($input['operation'] ?? '') === 'inspect') {
        echo json_encode($row ?: null, JSON_THROW_ON_ERROR);
    } elseif (($input['operation'] ?? '') === 'cleanup') {
        if ($row !== false) {
            if ($row['role'] !== $expectedRole || (string) $row['id'] !== (string) ($input['id'] ?? '')) {
                throw new RuntimeException('Fixture identity changed.');
            }
            $delete = $pdo->prepare('DELETE FROM users WHERE id = :id AND email = :email AND role = :role');
            $delete->execute(['id' => $row['id'], 'email' => $email, 'role' => $expectedRole]);
            if ($delete->rowCount() !== 1) throw new RuntimeException('Fixture cleanup failed.');
        }
        echo 'null';
    } else {
        throw new RuntimeException('Invalid operation.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Evidence database verification failed.' . PHP_EOL);
    exit(1);
}`;

function fixture(operation, id = null, address = email) {
    const result = execFileSync('D:\\xamp\\php\\php.exe', ['-r', databaseCode], {
        cwd: root, input: JSON.stringify({ operation, email: address, id }), encoding: 'utf8',
        windowsHide: true, timeout: 15000, stdio: ['pipe', 'pipe', 'pipe'],
    });
    return JSON.parse(result);
}

async function request(path, fields = null) {
    const response = await fetch(new URL(`public/${path}`, base), {
        method: fields === null ? 'GET' : 'POST', redirect: 'manual',
        headers: { Cookie: [...jar].map(([key, value]) => `${key}=${value}`).join('; ') },
        body: fields === null ? undefined : new URLSearchParams(fields),
        signal: AbortSignal.timeout(15000),
    });
    for (const cookie of response.headers.getSetCookie()) {
        const [pair, ...attributes] = cookie.split(';');
        const separator = pair.indexOf('=');
        const name = pair.slice(0, separator).trim();
        const value = pair.slice(separator + 1);
        const expired = attributes.some(attribute => /^\s*max-age=0\s*$/i.test(attribute)
            || (/^\s*expires=/i.test(attribute) && Date.parse(attribute.split('=').slice(1).join('=')) < Date.now()));
        if (expired) jar.delete(name); else jar.set(name, value);
    }
    return { status: response.status, html: await response.text(), location: response.headers.get('location') };
}

function tokenFrom(html) {
    const match = html.match(/<input[^>]+name="csrf_token"[^>]+value="([a-f0-9]+)"/i);
    ensure(match, 'A normal authentication form did not provide a CSRF token.');
    return match[1];
}

async function logout() {
    const page = await request('logout.php');
    ensure(page.status === 200, 'Could not open normal logout confirmation.');
    const result = await request('logout.php', { csrf_token: tokenFrom(page.html) });
    ensure(result.status === 303, 'Normal logout did not invalidate the evidence session.');
}

async function authenticate(address, secret) {
    const loginPage = await request('login.php');
    ensure(loginPage.status === 200, 'Could not open normal login.');
    const previousSession = jar.get('spa_session');
    const result = await request('login.php', { csrf_token: tokenFrom(loginPage.html), email: address, password: secret });
    ensure(result.status === 303 && result.location?.endsWith('dashboard.php'), 'Normal evidence login failed.');
    loggedIn = true;
    const session = jar.get('spa_session');
    ensure(session && session !== previousSession, 'The evidence login did not rotate its session.');
    const cookie = await commands.send('Network.setCookie', {
        name: 'spa_session', value: session, url: base.href, path: '/',
        httpOnly: true, secure: base.protocol === 'https:', sameSite: 'Lax',
    });
    ensure(cookie.success, 'The evidence browser could not receive its legitimate login session.');
}

function createTemporaryAdministrator() {
    execFileSync('D:\\xamp\\php\\php.exe', ['tools/create-admin.php'], {
        cwd: root, encoding: 'utf8', windowsHide: true, timeout: 15000, stdio: ['pipe', 'pipe', 'pipe'],
        input: JSON.stringify({ full_name: 'Evidence Administrator', email: adminEmail,
            password: adminPassword, password_confirmation: adminPassword }),
    });
}

class BrowserCommands {
    constructor(socket) {
        this.socket = socket;
        this.sequence = 0;
        this.pending = new Map();
        socket.addEventListener('message', event => {
            const message = JSON.parse(event.data);
            const task = this.pending.get(message.id);
            if (!task) return;
            clearTimeout(task.timer);
            this.pending.delete(message.id);
            if (message.error) task.reject(new Error(`Browser command failed: ${task.method}`));
            else task.resolve(message.result);
        });
        socket.addEventListener('close', () => {
            for (const task of this.pending.values()) {
                clearTimeout(task.timer);
                task.reject(new Error('The evidence browser closed unexpectedly.'));
            }
            this.pending.clear();
        });
    }

    send(method, params = {}) {
        return new Promise((resolveCommand, reject) => {
            const id = ++this.sequence;
            const timer = setTimeout(() => {
                this.pending.delete(id);
                reject(new Error(`Browser command timed out: ${method}`));
            }, 20000);
            this.pending.set(id, { resolve: resolveCommand, reject, timer, method });
            this.socket.send(JSON.stringify({ id, method, params }));
        });
    }
}

async function connectBrowser(profile, browser) {
    const deadline = Date.now() + 15000;
    let port;
    while (Date.now() < deadline) {
        ensure(browser.exitCode === null, 'Chrome closed before evidence capture began.');
        try {
            const activePort = await readFile(join(profile, 'DevToolsActivePort'), 'utf8');
            port = Number(activePort.split('\n')[0]);
            if (Number.isInteger(port) && port > 0) break;
        } catch { /* Chrome has not published its local debugging port yet. */ }
        await pause(100);
    }
    ensure(port, 'Chrome did not expose its local evidence capture port.');
    const response = await fetch(`http://127.0.0.1:${port}/json/list`, { signal: AbortSignal.timeout(5000) });
    const target = (await response.json()).find(tab => tab.type === 'page');
    ensure(target?.webSocketDebuggerUrl, 'Chrome did not create the evidence tab.');
    const socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolveConnection, reject) => {
        const timer = setTimeout(() => reject(new Error('Chrome evidence connection timed out.')), 10000);
        socket.addEventListener('open', () => { clearTimeout(timer); resolveConnection(); }, { once: true });
        socket.addEventListener('error', () => { clearTimeout(timer); reject(new Error('Chrome evidence connection failed.')); }, { once: true });
    });
    const commands = new BrowserCommands(socket);
    await commands.send('Page.enable');
    await commands.send('Network.enable');
    await commands.send('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1100, deviceScaleFactor: 1, mobile: false });
    await commands.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'light' }] });
    return commands;
}

async function pageReady(commands, path) {
    const url = new URL(`public/${path}`, base).href;
    await commands.send('Page.navigate', { url });
    const expression = `location.href === ${JSON.stringify(url)} && document.readyState === 'complete' && Array.from(document.images).every(image => image.complete && image.naturalWidth > 0)`;
    const deadline = Date.now() + 20000;
    while (Date.now() < deadline) {
        const state = await commands.send('Runtime.evaluate', { expression, returnByValue: true });
        if (state.result?.value === true) {
            await commands.send('Runtime.evaluate', { expression: 'document.fonts.ready.then(() => true)', awaitPromise: true, returnByValue: true });
            await pause(200);
            return;
        }
        await pause(100);
    }
    throw new Error(`The ${path} evidence page did not finish loading.`);
}

async function screenshot(commands, path, filename) {
    await pageReady(commands, path);
    const styles = await commands.send('Runtime.evaluate', {
        expression: "Array.from(document.styleSheets).some(sheet => sheet.href && sheet.href.includes('bootstrap@5.3.0'))",
        returnByValue: true,
    });
    ensure(styles.result?.value === true, 'Bootstrap did not load; refusing to save incomplete visual evidence.');
    const metrics = await commands.send('Page.getLayoutMetrics');
    const size = metrics.cssContentSize ?? metrics.contentSize;
    const result = await commands.send('Page.captureScreenshot', {
        format: 'png', captureBeyondViewport: true, fromSurface: true,
        clip: { x: 0, y: 0, width: 1400, height: Math.max(1100, Math.ceil(size.height)), scale: 1 },
    });
    await writeFile(join(output, filename), Buffer.from(result.data, 'base64'));
    console.log(`Saved docs/screenshots/${filename}`);
}

let profile;
let browser;
let commands;
let confirmedAbsent = false;
let attemptedRegistration = false;
let fixtureId = null;
let adminConfirmedAbsent = false;
let attemptedAdminCreation = false;
let adminFixtureId = null;
let loggedIn = false;
let failure = null;
const cleanupErrors = [];

try {
    ensure(fixture('inspect') === null, 'The random evidence address must be absent before registration.');
    confirmedAbsent = true;
    if (task6Mode) {
        ensure(fixture('inspect', null, adminEmail) === null, 'The random administrator address must be absent before setup.');
        adminConfirmedAbsent = true;
    }
    await mkdir(output, { recursive: true });
    profile = await mkdtemp(join(tmpdir(), `spa-${evidenceTask}-evidence-`));
    browser = spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
        '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-background-networking', '--disable-sync', '--remote-debugging-port=0',
        `--user-data-dir=${profile}`, '--window-size=1400,1100', 'about:blank',
    ], { cwd: root, windowsHide: true, stdio: 'ignore' });
    await new Promise((resolveSpawn, reject) => {
        browser.once('spawn', resolveSpawn);
        browser.once('error', () => reject(new Error('Could not launch the evidence browser.')));
    });
    commands = await connectBrowser(profile, browser);
    if (!task6Mode) {
        await screenshot(commands, 'register.php', 'task4-registration.png');
        await screenshot(commands, 'login.php', 'task4-login.png');
    }

    const registerPage = await request('register.php');
    ensure(registerPage.status === 200, 'Could not open normal customer registration.');
    attemptedRegistration = true;
    const registration = await request('register.php', {
        csrf_token: tokenFrom(registerPage.html), full_name: task6Mode ? 'Evidence Customer' : 'Evidence Guest', email,
        password, password_confirmation: password,
    });
    ensure(registration.status === 303 && registration.location?.endsWith('login.php'), 'Normal evidence registration failed.');
    const registered = fixture('inspect');
    ensure(registered?.role === 'customer', 'Evidence registration must create only a customer.');
    fixtureId = registered.id;

    await authenticate(email, password);
    await pageReady(commands, 'dashboard.php');
    const dashboard = await commands.send('Runtime.evaluate', {
        expression: "document.querySelector('.auth-dashboard')?.textContent.includes('Customer') === true",
        returnByValue: true,
    });
    ensure(dashboard.result?.value === true, 'The evidence dashboard must show the temporary customer account.');
    await screenshot(commands, 'dashboard.php', task6Mode ? 'task6-customer-dashboard.png' : 'task4-dashboard.png');
    if (task6Mode) {
        ensure((await request('manage_services.php')).status === 403, 'The customer must be denied direct access to service management.');
        await screenshot(commands, 'manage_services.php', 'task6-customer-access-denied.png');
    } else {
        await screenshot(commands, 'logout.php', 'task4-logout.png');
    }
    await logout();
    loggedIn = false;
    if (task6Mode) {
        attemptedAdminCreation = true;
        createTemporaryAdministrator();
        const administrator = fixture('inspect', null, adminEmail);
        ensure(administrator?.role === 'admin', 'The CLI setup must create the temporary administrator.');
        adminFixtureId = administrator.id;
        await authenticate(adminEmail, adminPassword);
        ensure((await request('manage_services.php')).status === 200, 'The administrator must be able to view service management.');
        await screenshot(commands, 'dashboard.php', 'task6-admin-dashboard.png');
        await screenshot(commands, 'manage_services.php', 'task6-admin-services.png');
        await logout();
        loggedIn = false;
    }
} catch (error) {
    failure = error;
} finally {
    if (loggedIn) {
        try { await logout(); } catch { cleanupErrors.push('The evidence login session could not be explicitly closed.'); }
    }
    if (confirmedAbsent && attemptedRegistration) {
        try {
            const remaining = fixture('inspect');
            if (remaining !== null) {
                ensure(remaining.role === 'customer', 'Refusing cleanup of a non-customer record.');
                if (fixtureId === null) fixtureId = remaining.id;
                ensure(String(remaining.id) === String(fixtureId), 'Refusing cleanup after the fixture id changed.');
                fixture('cleanup', fixtureId);
            }
            ensure(fixture('inspect') === null, 'The temporary evidence account remains in the database.');
        } catch { cleanupErrors.push(`Temporary evidence customer cleanup failed; inspect the ${evidenceTask}-evidence address before retrying.`); }
    }
    if (adminConfirmedAbsent && attemptedAdminCreation) {
        try {
            const remaining = fixture('inspect', null, adminEmail);
            if (remaining !== null) {
                ensure(remaining.role === 'admin', 'Refusing cleanup of a changed administrator record.');
                if (adminFixtureId === null) adminFixtureId = remaining.id;
                ensure(String(remaining.id) === String(adminFixtureId), 'Refusing cleanup after the administrator fixture id changed.');
                fixture('cleanup', adminFixtureId, adminEmail);
            }
            ensure(fixture('inspect', null, adminEmail) === null, 'The temporary administrator remains in the database.');
        } catch { cleanupErrors.push('Temporary evidence administrator cleanup failed; inspect the task6-evidence address before retrying.'); }
    }
    if (commands) {
        try { await commands.send('Browser.close'); } catch { /* Closing Chrome may close CDP before its reply. */ }
        commands.socket.close();
    }
    if (browser && browser.exitCode === null) {
        browser.kill();
        await Promise.race([new Promise(resolveExit => browser.once('exit', resolveExit)), pause(2000)]);
    }
    if (profile) {
        try {
            // This is the exact mkdtemp profile, never a computed parent or an existing browser profile.
            ensure(dirname(resolve(profile)) === resolve(tmpdir())
                && /^spa-task[46]-evidence-[A-Za-z0-9]+$/.test(basename(profile)), 'Unsafe temporary profile path.');
            const details = await lstat(profile);
            ensure(details.isDirectory() && !details.isSymbolicLink(), 'Unsafe temporary profile type.');
            await rm(profile, { recursive: true, force: true, maxRetries: 6, retryDelay: 500 });
        } catch { cleanupErrors.push('The isolated temporary browser profile could not be removed.'); }
    }
}

if (failure || cleanupErrors.length) {
    console.error(failure ? failure.message : 'Evidence cleanup did not finish.');
    for (const message of cleanupErrors) console.error(message);
    process.exitCode = 1;
} else {
    console.log(task6Mode
        ? 'Four Task 6 screenshots saved; temporary customer and administrator, login sessions, and browser profile removed.'
        : 'Four Task 4 screenshots saved; temporary customer, login session, and browser profile removed.');
}
