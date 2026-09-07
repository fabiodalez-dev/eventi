const { test } = require('node:test');
const assert = require('node:assert/strict');
const { verify, siteUrl, pages } = require('./verify-release.cjs');
const sha = 'a'.repeat(40);
test('requires the exact commit and all public pages, with a final commit recheck', () => {
    const urls = [];
    verify('https://example.test', sha, url => { urls.push(url); return JSON.stringify({ sha }); });
    assert.equal(urls.length, pages.length + 2);
    assert.deepEqual(urls.slice(1, -1), pages.map(path => 'https://example.test' + path));
});
test('a different commit cannot pass', () => {
    assert.throws(() => verify('https://example.test', sha, () => JSON.stringify({ sha: 'b'.repeat(40) })));
});
test('a TLS or HTTP failure on any page fails verification', () => {
    assert.throws(() => verify('https://example.test', sha, url => {
        if (url.endsWith('/admin/login')) throw new Error('TLS failure');
        return JSON.stringify({ sha });
    }), /TLS failure/);
});
test('malformed status responses cannot pass', () => {
    assert.throws(() => verify('https://example.test', sha, () => '<html>maintenance</html>'));
});
test('a concurrent release cannot pass', () => {
    let statuses = 0;
    assert.throws(() => verify('https://example.test', sha, url => {
        if (url.includes('/release-status')) statuses++;
        return JSON.stringify({ sha: statuses > 1 ? 'b'.repeat(40) : sha });
    }), /changed/);
});
test('rejects insecure origins and credentials', () => {
    for (const url of ['http://example.test', 'https://user:pass@example.test', 'https://example.test/path', 'https://example.test/?token=x']) {
        assert.throws(() => siteUrl(url));
    }
});
