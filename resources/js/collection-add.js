// The "add to a collection" form posts to the chosen collection, or creates a
// new one when the visitor picks "new". Without JavaScript it still posts to
// the first collection (or creates one): the server validates either way.
export function collectionAdd(root = document) {
    root.querySelectorAll('[data-collection-form]').forEach((form) => {
        const select = form.querySelector('[data-collection-select]');
        const fresh = form.querySelector('[data-collection-new]');
        const name = fresh?.querySelector('input');
        if (!select) return;
        const sync = () => {
            const creating = select.value === 'new';
            fresh.hidden = !creating;
            if (name) name.required = creating;
            form.action = creating ? form.dataset.storeUrl : form.dataset.itemsUrl.replace('__ID__', select.value);
        };
        select.addEventListener('change', sync);
        sync();
    });
}
