const { test } = require('node:test');
const assert = require('node:assert/strict');
const { passed } = require('./ci-gate.cjs');

const complete = () => ({
    scope: { result: 'success', outputs: { web: 'true', android: 'true' } },
    quality: { result: 'success' }, tests: { result: 'success' },
    lighthouse: { result: 'success' }, android: { result: 'success' },
});

test('all required checks pass', () => assert.equal(passed(complete()), true));
test('explicit Lighthouse pause allows only an intentional skip', () => {
    const needs = complete();
    needs.lighthouse.result = 'skipped';
    assert.equal(passed(needs, true), true);
    for (const result of ['failure', 'cancelled', undefined]) {
        needs.lighthouse.result = result;
        assert.equal(passed(needs, true), false);
    }
    needs.lighthouse.result = 'skipped';
    for (const job of ['scope', 'quality', 'tests', 'android']) {
        const broken = structuredClone(needs);
        broken[job].result = 'skipped';
        assert.equal(passed(broken, true), false);
    }
});
for (const job of ['scope', 'quality', 'tests', 'lighthouse', 'android']) {
    for (const result of ['failure', 'cancelled', 'skipped']) {
        test(`${job} ${result} blocks release`, () => {
            const needs = complete();
            needs[job].result = result;
            assert.equal(passed(needs), false);
        });
    }
}
test('intentional Android skip allowed', () => {
    const needs = complete();
    needs.scope.outputs.android = 'false';
    needs.android.result = 'skipped';
    assert.equal(passed(needs), true);
});
test('missing scope outputs fail closed', () => {
    const needs = complete();
    delete needs.scope.outputs;
    assert.equal(passed(needs), false);
});
