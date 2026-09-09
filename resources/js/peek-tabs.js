/** Reserve a visible fragment of the next tab when the row overflows. */
export function peekTabs() {
    document.querySelectorAll('[data-peek-tabs], .scroll-row').forEach(row => {
        const update = () => {
            const children = [...row.children];
            children.forEach(child => child.style.marginInlineEnd = '');
            if (row.scrollWidth <= row.clientWidth || children.length < 2) return;
            const first = children[0].offsetLeft;
            const next = children.findIndex(child => child.offsetLeft - first + child.offsetWidth > row.clientWidth - 28);
            if (next < 1) return;
            const extra = row.clientWidth - 28 - (children[next].offsetLeft - first);
            if (extra > 0) children[next - 1].style.marginInlineEnd = `${extra}px`;
        };
        new ResizeObserver(update).observe(row);
        document.fonts?.ready.then(update);
        update();
    });
}
