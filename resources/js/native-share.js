/**
 * Condivisione con il menu di sistema, dove esiste. Il pulsante è nascosto in
 * partenza e compare solo qui: senza JavaScript restano i link ai singoli
 * servizi, che funzionano ovunque.
 */

export function nativeShare() {
    for (const button of document.querySelectorAll("[data-share]")) {
        if (!(button instanceof HTMLElement)) {
            continue;
        }

        const payload = {
            title: button.dataset.shareTitle ?? document.title,
            text: button.dataset.shareText ?? "",
            url: button.dataset.shareUrl ?? window.location.href,
        };

        const canShare = typeof navigator.share === "function";
        const canCopy = navigator.clipboard !== undefined;

        if (!canShare && !canCopy) {
            continue;
        }

        button.classList.remove("hidden");

        button.addEventListener("click", async () => {
            try {
                if (canShare) {
                    await navigator.share(payload);
                    document.dispatchEvent(new CustomEvent('content:shared', { detail: { metric: button.dataset.shareMetric } }));

                    return;
                }

                await navigator.clipboard.writeText(payload.url);
                document.dispatchEvent(new CustomEvent('content:shared', { detail: { metric: button.dataset.shareMetric } }));

                const label = button.querySelector('[data-share-label]') ?? button;
                const original = label.textContent;
                const status = button.parentElement.querySelector('[data-share-status]');
                label.textContent = button.dataset.shareCopied ?? original;
                if (status) status.textContent = label.textContent;
                window.setTimeout(() => {
                    label.textContent = original;
                    if (status) status.textContent = "";
                }, 2000);
            } catch {
                /* L'utente ha annullato: non è un errore da mostrare. */
            }
        });
    }
}

