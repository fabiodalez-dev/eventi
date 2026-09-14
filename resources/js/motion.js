import { gsap } from 'gsap';

const reduced = () => matchMedia('(prefers-reduced-motion: reduce)').matches;

export function transitionResults(region, from, to, duration, signal) {
    if (reduced() || signal.aborted) return Promise.resolve();
    const targets = [...region.querySelectorAll('[data-filter-transition]')];
    return new Promise(resolve => {
        let tween;
        const finish = () => {
            signal.removeEventListener('abort', cancel);
            gsap.set(targets, { clearProps: 'opacity' });
            resolve();
        };
        const cancel = () => { tween?.kill(); finish(); };
        tween = gsap.fromTo(targets, { opacity: from }, {
            opacity: to, duration: duration / 1000, ease: 'power2.out',
            onComplete: finish, onInterrupt: finish,
        });
        signal.addEventListener('abort', cancel, { once: true });
    });
}

export function revealPanel(panel) {
    if (!panel || reduced()) return;
    gsap.fromTo(panel, { y: 12, opacity: 0.7 }, {
        y: 0, opacity: 1, duration: 0.24, ease: 'power2.out',
        overwrite: true, clearProps: 'transform,opacity',
    });
}

export function startMotion() {
    const media = gsap.matchMedia();
    media.add('(prefers-reduced-motion: no-preference)', () => {
        const controller = new AbortController();
        const { signal } = controller;
        document.addEventListener('toggle', event => {
            if (event.target.matches?.('[data-catalog-filters]') && event.target.open) {
                const panel = event.target.querySelector('[data-filter-panel]');
                if (panel) context.add(() => revealPanel(panel));
            }
        }, { signal, capture: true });
        const seen = new WeakSet();
        const context = gsap.context(() => {});
        const observer = new IntersectionObserver(entries => {
            const incoming = entries.filter(entry => entry.isIntersecting && entry.intersectionRatio >= 0.15).map(entry => entry.target);
            incoming.forEach(card => observer.unobserve(card));
            if (!incoming.length) return;
            context.add(() => gsap.fromTo(incoming, { y: 10, opacity: 0.65 }, {
                y: 0, opacity: 1, duration: 0.36,
                stagger: { each: 0.035, amount: Math.min(0.12, incoming.length * 0.035) },
                ease: 'power2.out', clearProps: 'transform,opacity',
            }));
        }, { threshold: 0.15, rootMargin: '0px 0px -96px 0px' });
        const scan = () => {
            document.querySelectorAll('.event-card').forEach(card => {
                if (seen.has(card)) return;
                seen.add(card);
                // The first viewport is content, not an entrance animation.
                if (card.getBoundingClientRect().top >= innerHeight) observer.observe(card);
            });
        };
        scan();
        const mutations = new MutationObserver(records => {
            if (records.some(record => record.addedNodes.length)) scan();
            for (const record of records) for (const node of record.removedNodes) {
                if (!(node instanceof Element)) continue;
                const cards = [node, ...node.querySelectorAll('.event-card')];
                cards.forEach(card => observer.unobserve(card));
                gsap.killTweensOf(cards);
            }
        });
        mutations.observe(document.querySelector('main') ?? document.body, { childList: true, subtree: true });
        const hover = (event, entering) => {
            if (!matchMedia('(hover: hover) and (pointer: fine)').matches) return;
            const card = event.target.closest('.event-card');
            if (!card || card.contains(event.relatedTarget)) return;
            const photo = card.querySelector('[data-catalog-poster] img');
            const arrow = card.querySelector('[data-card-arrow] svg');
            context.add(() => {
                if (photo) gsap.to(photo, { scale: entering ? 1.025 : 1, duration: 0.3, ease: 'power2.out', overwrite: 'auto' });
                if (arrow) gsap.to(arrow, {
                    x: entering ? 7 : 0,
                    scale: entering ? 1.08 : 1,
                    duration: entering ? 0.24 : 0.18,
                    ease: 'power2.out',
                    overwrite: 'auto',
                });
            });
        };
        document.addEventListener('pointerover', event => hover(event, true), { signal });
        document.addEventListener('pointerout', event => hover(event, false), { signal });
        document.addEventListener('saved:changed', event => {
            const icons = [...document.querySelectorAll('[data-save]')]
                .filter(form => Number(form.dataset.saveId) === Number(event.detail.id))
                .map(form => form.querySelector('[data-save-icon]')).filter(Boolean);
            context.add(() => gsap.fromTo(icons, { scale: 0.85 }, {
                scale: 1, duration: 0.2, ease: 'power2.out', overwrite: true, clearProps: 'transform',
            }));
        }, { signal });
        return () => { controller.abort(); mutations.disconnect(); observer.disconnect(); context.revert(); };
    });
}


let flipModule;
export async function captureCards(region) {
    if (reduced() || region.contains(document.activeElement) && document.activeElement.closest('.event-card')) return null;
    const cards = [...region.querySelectorAll('[data-flip-id]')].filter(card => {
        const rect = card.getBoundingClientRect();
        return rect.bottom > 0 && rect.top < innerHeight;
    }).slice(0, 12);
    if (!cards.length) return null;
    flipModule ??= import('gsap/Flip').then(({ Flip }) => { gsap.registerPlugin(Flip); return Flip; });
    const Flip = await flipModule;
    return { Flip, state: Flip.getState(cards), ids: new Set(cards.map(card => card.dataset.flipId)) };
}
export function animateCards(snapshot, region) {
    if (!snapshot || reduced()) return;
    const targets = [...region.querySelectorAll('[data-flip-id]')].filter(card => snapshot.ids.has(card.dataset.flipId));
    snapshot.Flip.from(snapshot.state, { targets, duration: 0.22, ease: 'power2.out', scale: true, prune: true });
}
