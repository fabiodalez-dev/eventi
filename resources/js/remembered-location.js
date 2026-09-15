export function rememberedLocation() {
    const panel = document.querySelector('[data-remembered-location]');
    if (!panel || panel.dataset.bound) return;
    panel.dataset.bound = '1';
    const status = panel.querySelector('[role="status"]');
    const use = panel.querySelector('[data-location-use]');
    const forget = panel.querySelector('[data-location-forget]');
    let busy = false;
    panel.querySelector('[data-nearby-radius]')?.addEventListener('change', event => {
        const url = new URL(window.location.href);
        url.searchParams.set('nearby_radius', event.target.value);
        url.hash = 'sezione-vicino';
        window.location.assign(url.toString());
    });
    async function send(method, body) {
        const response = await fetch(panel.dataset.endpoint, {
            method, credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: body ? JSON.stringify(body) : undefined,
        });
        if (!response.ok) throw new Error('Location request failed');
        return (await response.json()).data;
    }
    async function locate() {
        if (busy || !navigator.geolocation) return;
        busy = true;
        use.disabled = forget.disabled = true;
        status.textContent = panel.dataset.loading;
        try {
            const position = await new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, reject,
                { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }));
            try {
                const saved = await send('POST', { lat: position.coords.latitude, lng: position.coords.longitude, remember: true });
                // Only reload when the approximate location changes, never in a refresh loop.
                if (panel.dataset.position !== `${saved.lat},${saved.lng}`) window.location.reload();
                else status.textContent = panel.dataset.saved;
                forget.hidden = false;
            } catch { status.textContent = panel.dataset.failed; }
        } catch { status.textContent = panel.dataset.unavailable; }
        finally { busy = false; use.disabled = forget.disabled = false; }
    }
    use.addEventListener('click', locate);
    forget.addEventListener('click', async () => {
        if (busy) return;
        busy = true;
        use.disabled = forget.disabled = true;
        try { await send('DELETE'); window.location.reload(); }
        catch { status.textContent = panel.dataset.failed; busy = false; use.disabled = forget.disabled = false; }
    });
    // Consent to remembering is separate from the operating-system permission.
    async function restore() {
        if (!panel.dataset.position) return;
        if (panel.dataset.authenticated === '1') {
            const [lat, lng] = panel.dataset.position.split(',').map(Number);
            busy = true;
            use.disabled = forget.disabled = true;
            // Synchronize before allowing deletion or a newer GPS update.
            try { await send('POST', { lat, lng, remember: true, observed_at: Number(panel.dataset.savedAt) }); }
            catch { /* The saved fallback remains available offline. */ }
            finally { busy = false; use.disabled = forget.disabled = false; }
        }
        if (navigator.permissions) {
            try {
                const permission = await navigator.permissions.query({ name: 'geolocation' });
                if (permission.state === 'granted') locate();
            } catch { /* Unsupported Permissions API: keep the explicit button. */ }
        }
    }
    restore();
}
