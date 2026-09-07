const { execFileSync } = require('node:child_process');
const { appendFileSync } = require('node:fs');

const pages = ['/', '/eventi', '/locali', '/sitemap.xml', '/api/v1/home', '/admin/login'];

function siteUrl(value) {
    const url = new URL(value);
    if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash || url.pathname !== '/') {
        throw new Error('PUBLIC_SITE_URL must be an HTTPS origin without credentials');
    }
    return url.origin;
}

function request(url) {
    const result = execFileSync('curl', [
        '--ipv4', '--fail', '--silent', '--show-error', '--connect-timeout', '5',
        '--max-time', '10', '--write-out', '\n%{http_code}', url,
    ], { encoding: 'utf8', timeout: 12000, maxBuffer: 8 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] });
    const separator = result.lastIndexOf('\n');
    if (result.slice(separator + 1) !== '200') throw new Error('Expected HTTP 200');
    return result.slice(0, separator);
}

function verify(base, sha, get = request) {
    if (!/^[a-f0-9]{40}$/.test(sha)) throw new Error('Invalid expected commit');
    base = siteUrl(base);
    const release = JSON.parse(get(`${base}/release-status?expected=${sha}`));
    if (release.sha !== sha) throw new Error('Server has not confirmed the expected commit');
    for (const path of pages) get(base + path);
    // Do not accept pages sampled across two different releases.
    if (JSON.parse(get(`${base}/release-status?expected=${sha}`)).sha !== sha) {
        throw new Error('Release changed during verification');
    }
}

async function main() {
    const base = siteUrl(process.env.PUBLIC_SITE_URL || 'https://eventi.fabiodalez.it');
    const sha = process.env.EXPECTED_SHA;
    if (!/^[a-f0-9]{40}$/.test(sha || '')) throw new Error('Invalid expected commit');
    let verified = false;
    for (let attempt = 1; attempt <= 12; attempt++) {
        try {
            verify(base, sha);
            verified = true;
            break;
        } catch (error) {
            console.log(`Attempt ${attempt}: ${error.stderr?.toString().trim() || error.message}`);
            if (attempt < 12) await new Promise(resolve => setTimeout(resolve, 5000));
        }
    }
    if (process.env.GITHUB_OUTPUT) appendFileSync(process.env.GITHUB_OUTPUT, `verified=${verified}\n`);
    if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY,
        `${verified ? 'Verified release' : 'Verification unsuccessful on this runner'}: ${sha}\n`);
    if (!verified) {
        console.log('::warning::Release not verified from this runner. A fresh runner may retry; the final gate remains mandatory.');
        process.exitCode = 1;
    }
}

if (require.main === module) main().catch(error => { console.error(error.message); process.exitCode = 1; });
module.exports = { verify, siteUrl, pages };
