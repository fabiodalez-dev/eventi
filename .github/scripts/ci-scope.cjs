const { execFileSync } = require('node:child_process');
const { appendFileSync, readFileSync } = require('node:fs');

// Unknown paths and release builds fail closed: run the complete suite.
function scope(eventName, paths) {
    if (eventName !== 'pull_request' || paths.length === 0) {
        return { web: true, android: true };
    }

    let web = false;
    let android = false;
    for (const path of paths) {
        if (/^(docs\/|README\.md$|LICENSE$|\.impeccable\/)/.test(path)) continue;
        if (path.startsWith('android/')) {
            android = true;
        } else if (/^(app\/|routes\/|config\/|bootstrap\/|database\/|tests\/|composer\.|\.env)/.test(path)) {
            // Backend changes can change the mobile API contract.
            web = android = true;
        } else if (/^(resources\/|public\/|lang\/|package\.json$|package-lock\.json$|vite\.config\.|lighthouserc\.)/.test(path)) {
            web = true;
        } else {
            web = android = true;
        }
    }
    return { web, android };
}

if (require.main === module) {
    const event = JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8'));
    const paths = process.env.GITHUB_EVENT_NAME === 'pull_request'
        ? execFileSync('git', ['diff', '--name-only', '-z', '--no-renames', event.pull_request.base.sha, 'HEAD'], { encoding: 'utf8' }).split('\0').filter(Boolean)
        : [];
    const result = scope(process.env.GITHUB_EVENT_NAME, paths);
    appendFileSync(process.env.GITHUB_OUTPUT, Object.entries(result).map(([key, value]) => `${key}=${value}\n`).join(''));
    console.log(result);
}

module.exports = { scope };
