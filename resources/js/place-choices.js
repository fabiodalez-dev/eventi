export function matchesPlace(label, search) {
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('it').trim();
    return normalize(label).includes(normalize(search));
}

export function placeChoices(root = document) {
    root.querySelectorAll('[data-place-choices]').forEach(group => {
        const input = group.querySelector('[data-place-search]');
        input?.addEventListener('input', () => {
            group.querySelectorAll('[data-place-option]').forEach(option => {
                option.style.display = matchesPlace(option.textContent, input.value) ? '' : 'none';
            });
        });
    });
}
