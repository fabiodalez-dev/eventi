const { test } = require('node:test');
const assert = require('node:assert/strict');
const { scope } = require('./ci-scope.cjs');

for (const [name, event, paths, expected] of [
    ['release is complete even for web-only changes', 'push', ['resources/css/app.css'], { web: true, android: true }],
    ['manual is complete', 'workflow_dispatch', [], { web: true, android: true }],
    ['frontend skips Android', 'pull_request', ['resources/views/events/show.blade.php', 'package-lock.json'], { web: true, android: false }],
    ['Android skips web', 'pull_request', ['android/app/build.gradle.kts'], { web: false, android: true }],
    ['backend protects API consumers', 'pull_request', ['app/Http/Resources/EventResource.php'], { web: true, android: true }],
    ['docs only', 'pull_request', ['docs/FRONTEND-CACHE.md', 'README.md'], { web: false, android: false }],
    ['mixed changes', 'pull_request', ['android/app/build.gradle.kts', 'resources/css/app.css'], { web: true, android: true }],
    ['unknown fails closed', 'pull_request', ['new-runtime-file'], { web: true, android: true }],
    ['workflow changes run everything', 'pull_request', ['.github/workflows/ci.yml'], { web: true, android: true }],
    ['empty diff runs everything', 'pull_request', [], { web: true, android: true }],
    ['root runtime PHP runs everything', 'pull_request', ['artisan'], { web: true, android: true }],
]) {
    test(name, () => assert.deepEqual(scope(event, paths), expected));
}
