function passed(needs) {
    if (needs.scope?.result !== 'success') return false;
    const { web, android } = needs.scope.outputs ?? {};
    if (![web, android].every(value => ['true', 'false'].includes(value))) return false;
    const required = [];
    if (web === 'true') required.push('quality', 'tests', 'lighthouse');
    if (android === 'true') required.push('android');
    return required.every(job => needs[job]?.result === 'success');
}

if (require.main === module) {
    if (!passed(JSON.parse(process.env.NEEDS_JSON))) process.exit(1);
}

module.exports = { passed };
