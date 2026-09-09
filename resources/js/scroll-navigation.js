/** Accumulate intentional direction changes, ignoring tiny scroll jitter. */
export function navigationMovement(previous, delta, threshold = 16) {
    if (!Number.isFinite(delta) || delta === 0) return { movement: previous, visible: null };
    const movement = Math.sign(delta) === Math.sign(previous) ? previous + delta : delta;
    return Math.abs(movement) >= threshold
        ? { movement: 0, visible: movement < 0 }
        : { movement, visible: null };
}
