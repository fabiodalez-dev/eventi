const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');

test('web tests install required packages only from signed Ubuntu sources', () => {
    const workflow = readFileSync('.github/workflows/ci.yml', 'utf8');
    const job = workflow.split('\n  tests:\n')[1].split('\n  lighthouse:\n')[0];
    assert.match(job, /runs-on: ubuntu-24\.04/);
    assert.match(job, /test -s \/etc\/apt\/sources\.list\.d\/ubuntu\.sources/);
    assert.match(job, /Dir::Etc::sourcelist=\/etc\/apt\/sources\.list\.d\/ubuntu\.sources/);
    assert.match(job, /Dir::Etc::sourceparts=-/);
    assert.match(job, /libheif1 libheif-plugin-aomenc mariadb-client/);
    const aptCommands = job.split('\n').filter(line => line.includes('sudo apt-get'));
    assert.equal(aptCommands.length, 2);
    for (const line of aptCommands) assert.ok(line.includes('"${ubuntu_sources[@]}"'));
    assert.doesNotMatch(job, /--allow-unauthenticated|AllowInsecureRepositories|Check-Valid-Until|trusted=yes/);
});
