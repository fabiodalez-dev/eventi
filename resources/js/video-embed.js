// The video frame stays ours until the visitor asks for it: only then the
// provider's player is loaded. Nothing leaves the page before that click.
export function videoEmbed(root = document) {
    root.querySelectorAll('[data-video-embed]').forEach((section) => {
        const frame = section.querySelector('[data-video-frame]');
        const play = section.querySelector('[data-video-play]');
        if (!frame || !play) return;
        play.addEventListener('click', () => {
            const iframe = document.createElement('iframe');
            iframe.src = `${section.dataset.embedUrl}${section.dataset.embedUrl.includes('?') ? '&' : '?'}autoplay=1`;
            iframe.title = section.dataset.embedTitle || '';
            iframe.allow = 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen';
            iframe.allowFullscreen = true;
            iframe.referrerPolicy = 'strict-origin-when-cross-origin';
            iframe.className = 'absolute inset-0 size-full';
            frame.replaceChildren(iframe);
        }, { once: true });
    });
}
