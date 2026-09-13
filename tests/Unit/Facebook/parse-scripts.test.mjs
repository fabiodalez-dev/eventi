import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const script = fileURLToPath(new URL('../../../scripts/facebook/parse-scripts.mjs', import.meta.url));
const url = 'https://www.facebook.com/events/123/';
const run = (input, target = url) => spawnSync(process.execPath, [script, target], { input, encoding: 'utf8', timeout: 10000 });

test('parses stdin JSON without executing page JavaScript or requiring Playwright', () => {
    const result = run(JSON.stringify(['process.exit(42)', JSON.stringify({id:'123',name:'Città',event_description:{text:'Descrizione completa'}})]));
    assert.equal(result.status,0,result.stderr);
    assert.equal(JSON.parse(result.stdout).description,'Descrizione completa');
});
test('rejects malformed input, recommendations and preview-only data', () => {
    for(const input of ['bad JSON','{}','[null]', JSON.stringify([JSON.stringify({id:'999',name:'Altro',event_description:{text:'Altro'}})]),JSON.stringify(['{"id":"123","name":"Anteprima"}'])]){
        const result=run(input);assert.equal(result.status,1);assert.equal(result.stdout,'');
    }
});
test('refuses non-Facebook input and oversized stdin', () => {
    assert.equal(run('[]','https://evil.test/events/123/').status,1);
    assert.equal(run(' '.repeat(24*1024*1024+1)).status,1);
});
test('the HTTP runtime probe checks parsing without starting a browser', () => {
    const result=run('', '--check');assert.equal(result.status,0);assert.deepEqual(JSON.parse(result.stdout),{http_parser:true});
});
