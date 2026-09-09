/** Reveal after reading down the page; hide only near its start. */
export function navigationMovement(previous, delta, threshold = 96) {
    if (!Number.isFinite(delta) || delta === 0) return { movement: previous, visible: null };
    const movement = Math.max(0, previous + delta);
    return { movement, visible: movement >= threshold ? true : movement <= 24 ? false : null };
}
