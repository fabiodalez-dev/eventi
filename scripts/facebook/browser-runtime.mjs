import { chromium } from 'playwright';
import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';

// Every invocation uses a fresh profile. The hosting fallback only listens on loopback.
export async function openBrowser() {
    if (process.env.FACEBOOK_IMPORT_SINGLE_PROCESS !== '1') {
        const channel = process.env.FACEBOOK_IMPORT_BROWSER_CHANNEL;
        const browser = await chromium.launch({ headless: true, ...(channel ? { channel } : {}) });
        const context = await browser.newContext({ locale: 'it-IT', timezoneId: 'Europe/Rome', serviceWorkers: 'block' });
        return { context, close: () => browser.close() };
    }
    const directory = await mkdtemp(tmpdir() + '/facebook-import-');
    const child = spawn(process.env.FACEBOOK_IMPORT_CHROMIUM_BINARY || chromium.executablePath(), [
        '--headless', '--no-sandbox', '--single-process', '--no-zygote', '--disable-gpu',
        '--disable-dev-shm-usage', '--lang=it-IT', '--remote-debugging-address=127.0.0.1',
        '--remote-debugging-port=0', '--user-data-dir=' + directory, 'about:blank',
    ], { stdio: ['ignore', 'ignore', 'pipe'], env: { ...process.env, TZ: 'Europe/Rome' } });
    let browser;
    let closed = false;
    const close = async () => {
        if (closed) return;
        closed = true;
        await browser?.close().catch(() => {});
        if (child.exitCode === null && child.signalCode === null) {
            await new Promise(resolve => {
                const timer = setTimeout(() => { child.kill('SIGKILL'); resolve(); }, 2000);
                child.once('exit', () => { clearTimeout(timer); resolve(); });
                child.kill('SIGTERM');
            });
        }
        await rm(directory, { recursive: true, force: true });
    };
    try {
        const endpoint = await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Chromium startup timed out.')), 15000);
            let output = '';
            child.stderr.on('data', chunk => {
                output = (output + chunk.toString()).slice(-8192);
                const match = output.match(/DevTools listening on (ws:\/\/127\.0\.0\.1:\d+\/devtools\/browser\/[\w-]+)/);
                if (match) { clearTimeout(timer); resolve(match[1]); }
            });
            child.once('error', error => { clearTimeout(timer); reject(error); });
            child.once('exit', () => { clearTimeout(timer); reject(new Error('Chromium exited before it was ready. ' + output.slice(-1000))); });
        });
        browser = await chromium.connectOverCDP(endpoint, { timeout: 15000 });
        const context = browser.contexts()[0];
        await context.setExtraHTTPHeaders({ 'Accept-Language': 'it-IT,it;q=0.9' });
        await context.addInitScript(() => {
            if (navigator.serviceWorker) {
                navigator.serviceWorker.register = () => Promise.reject(new Error('Service workers disabled during import.'));
            }
        });
        return { context, close };
    } catch (error) {
        await close();
        throw error;
    }
}
