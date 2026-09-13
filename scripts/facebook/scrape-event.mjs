import { openBrowser } from './browser-runtime.mjs';
import { collectEventNodes, facebookEventId, parseEventNodes } from './event-parser.mjs';

let browser;
const stop = async () => { await browser?.close().catch(() => {}); process.exit(1); };
const deadline = setTimeout(stop, 50000);
process.once('SIGTERM', stop);
process.once('SIGINT', stop);
try {
    const id = facebookEventId(process.argv[2]);
    browser = await openBrowser();
    const context = browser.context;
    // Fixed public Meta hosts only, including redirects and subresources. No user session.
    await context.route('**/*', async route => {
        const url = new URL(route.request().url());
        const allowed = url.protocol === 'https:' && !url.port && ['facebook.com', 'fbcdn.net', 'fbsbx.com'].some(host => url.hostname === host || url.hostname.endsWith('.' + host));
        if (!allowed || ['image', 'media', 'font'].includes(route.request().resourceType())) await route.abort();
        else await route.continue();
    });
    const page = await context.newPage();
    await page.goto(`https://www.facebook.com/events/${id}/?locale=it_IT`, { waitUntil: 'domcontentloaded', timeout: 25000 });
    await page.waitForFunction(id => {
        return [...document.querySelectorAll('script[type="application/json"]')].some(script => script.textContent.includes('"id":"' + id + '"') && script.textContent.includes('event_description'));
    }, id, { timeout: 20000 });
    const scripts = await page.locator('script[type="application/json"]').allTextContents();
    if (scripts.reduce((size, value) => size + value.length, 0) > 12 * 1024 * 1024) throw new Error('Pagina Facebook troppo grande.');
    const result = parseEventNodes(collectEventNodes(scripts, id), id);
    process.stdout.write(JSON.stringify(result));
} catch (error) {
    process.stderr.write(error.message + '\n');
    process.exitCode = 1;
} finally {
    clearTimeout(deadline);
    await browser?.close();
}
