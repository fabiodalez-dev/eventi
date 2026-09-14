export function wouldEmptyResults(previous, next, push = true) {
    return push && Number(previous) > 0 && next !== undefined && Number(next) === 0;
}

export function removesFilters(previous, next) {
    const before = new URL(previous).searchParams;
    return [...new URL(next).searchParams].every(([key, value]) => {
        if (!value || ['all_dates', 'sort', 'page'].includes(key)) return true;
        if (['category', 'tag', 'access'].includes(key)) {
            return value.split(',').every(item => (before.get(key) ?? '').split(',').includes(item));
        }
        return before.get(key) === value;
    });
}
