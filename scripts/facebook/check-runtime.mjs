import { openBrowser } from './browser-runtime.mjs';
let browser;
const stop = async () => { await browser?.close().catch(() => {}); process.exit(1); };
const timer = setTimeout(stop, 45000);
process.once('SIGTERM', stop);
process.once('SIGINT', stop);
try {
    browser = await openBrowser();
    const page = await browser.context.newPage();
    await page.setContent('<title>Chromium check</title><script>window.runtimeCheck = 6 * 7;</script>');
    if (await page.evaluate(() => window.runtimeCheck) !== 42 || await page.title() !== 'Chromium check') {
        throw new Error('Chromium could not render the page and execute JavaScript.');
    }
    process.stdout.write(JSON.stringify({ chromium: true, javascript: true }));
} catch (error) {
    process.stderr.write(error.message + '\n');
    process.exitCode = 1;
} finally {
    clearTimeout(timer);
    await browser?.close();
}
