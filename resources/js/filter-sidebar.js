import { matchesPlace } from './place-choices.js';

const key = node => node.nodeType === 1
    ? node.getAttribute('data-filter-key') || node.id || (node.getAttribute('name') ? `${node.tagName}:${node.getAttribute('name')}:${node.type === 'checkbox' ? node.value : ''}` : null)
    : null;

// Reconcile the server's dependent choices without replacing focused controls,
// the form, its accordion or the user's local search text.
export function patchFilters(current, next) {
    if (current.nodeType !== next.nodeType || current.nodeName !== next.nodeName) {
        current.replaceWith(next.cloneNode(true));
        return;
    }
    if (current.nodeType !== 1) {
        if (current.textContent !== next.textContent) current.textContent = next.textContent;
        return;
    }
    if (current.matches('[data-option-search-ui]')) return;
    for (const attribute of [...current.attributes]) {
        if (attribute.name === 'open' && current.tagName === 'DETAILS') continue;
        if (!next.hasAttribute(attribute.name)) current.removeAttribute(attribute.name);
    }
    for (const attribute of next.attributes) {
        if (attribute.name === 'open' && current.tagName === 'DETAILS') continue;
        if (current.getAttribute(attribute.name) !== attribute.value) current.setAttribute(attribute.name, attribute.value);
    }
    const previous = [...current.childNodes];
    const used = new Set();
    let cursor = current.firstChild;
    for (const child of next.childNodes) {
        const childKey = key(child);
        const match = previous.find(node => !used.has(node) && node.nodeName === child.nodeName
            && (childKey ? key(node) === childKey : !key(node)));
        if (match) {
            used.add(match);
            if (match !== cursor) current.insertBefore(match, cursor);
            patchFilters(match, child);
            cursor = match.nextSibling;
        } else {
            const inserted = child.cloneNode(true);
            current.insertBefore(inserted, cursor);
        }
    }
    for (const node of previous) if (!used.has(node)) node.remove();
    if (current.matches('input, textarea, select')) {
        current.value = next.value;
        if (current.type === 'checkbox') current.checked = next.checked;
    }
}

export function searchFilterOptions(group) {
    const input = group.querySelector('[data-option-search]');
    const select = group.querySelector('select');
    if (!input || !select) return;
    group.querySelector('[data-option-search-ui]').hidden = false;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-haspopup', 'listbox');
    input.removeAttribute('aria-activedescendant');
    let matches = 0;
    for (const option of select.options) {
        const visible = !option.value || matchesPlace(option.textContent, input.value);
        option.hidden = !visible;
        option.removeAttribute('data-keyboard-active');
        if (option.value && visible) matches++;
    }
    // Searching opens an inline native list, with standard keyboard interaction.
    if (input.value.trim() || group.hasAttribute('data-keyboard-open')) {
        input.setAttribute('aria-expanded', 'true');
        select.size = Math.min(6, Math.max(2, matches + 1));
        select.setAttribute('data-search-expanded', '');
    } else {
        input.setAttribute('aria-expanded', 'false');
        select.removeAttribute('size');
        select.removeAttribute('data-search-expanded');
    }
    group.querySelector('[data-option-search-empty]').hidden = matches > 0;
}

export function searchableFilters(root = document) {
    root.querySelectorAll('[data-searchable-filter]').forEach(searchFilterOptions);
}

export function navigateFilterOptions(event) {
    const input = event.target;
    if (!input.matches('[data-option-search]') || !['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].includes(event.key)) return false;
    const group = input.closest('[data-searchable-filter]');
    const select = group.querySelector('select');
    const options = [...select.options].filter(option => !option.hidden && !option.disabled);
    if (!options.length) return false;
    event.preventDefault();
    const active = options.findIndex(option => option.hasAttribute('data-keyboard-active'));
    if (event.key === 'Escape') {
        options.forEach(option => option.removeAttribute('data-keyboard-active'));
        input.removeAttribute('aria-activedescendant');
        input.setAttribute('aria-expanded', 'false');
        group.removeAttribute('data-keyboard-open');
        select.removeAttribute('size');
        select.removeAttribute('data-search-expanded');
        return true;
    }
    if (event.key === 'Enter') {
        if (active >= 0) {
            select.value = options[active].value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        return true;
    }
    group.setAttribute('data-keyboard-open', '');
    select.size = Math.min(6, Math.max(2, options.length));
    select.setAttribute('data-search-expanded', '');
    input.setAttribute('aria-expanded', 'true');
    const index = active < 0
        ? (event.key === 'ArrowDown' ? (options[0]?.value === '' && options.length > 1 ? 1 : 0) : options.length - 1)
        : Math.max(0, Math.min(options.length - 1, active + (event.key === 'ArrowDown' ? 1 : -1)));
    options.forEach(option => option.removeAttribute('data-keyboard-active'));
    const option = options[index];
    option.id ||= `${select.id}-option-${[...select.options].indexOf(option)}`;
    option.setAttribute('data-keyboard-active', '');
    input.setAttribute('aria-activedescendant', option.id);
    option.scrollIntoView({ block: 'nearest' });
    return true;
}
