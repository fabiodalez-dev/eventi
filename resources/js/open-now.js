// "Open now" computed in the visitor's browser from the venue's hours, in the
// city's timezone, so a cached page never shows a stale state. Same rule as
// App\Support\OpeningHours: a close time before the open time means after
// midnight, and yesterday's overnight range still counts this morning.
const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

function minutes(value) {
    const match = /^(\d{1,2}):(\d{2})$/.exec(value ?? '');
    return match ? Math.min(1440, Number(match[1]) * 60 + Number(match[2])) : null;
}

function ranges(day) {
    return (Array.isArray(day) ? day : [])
        .map((range) => [minutes(range?.open), minutes(range?.close)])
        .filter(([open, close]) => open !== null && close !== null && open !== close);
}

function clock(total) {
    const value = total % 1440;
    return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
}

export function openingState(hours, now, timeZone) {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        .formatToParts(now).map((part) => [part.type, part.value]));
    const index = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(parts.weekday);
    const minute = Number(parts.hour) * 60 + Number(parts.minute);
    for (const [open, close] of ranges(hours[DAYS[(index + 6) % 7]])) {
        if (close <= open && minute < close) return { open: true, until: clock(close) };
    }
    for (const [open, close] of ranges(hours[DAYS[index]])) {
        if (minute >= open && (close <= open || minute < close)) return { open: true, until: clock(close) };
    }
    return { open: false, until: null };
}

export function openNowBadges(root = document, now = new Date()) {
    root.querySelectorAll('[data-open-now]').forEach((badge) => {
        let hours;
        try { hours = JSON.parse(badge.dataset.hours); } catch { return; }
        const state = openingState(hours, now, badge.dataset.timezone);
        badge.textContent = state.open
            ? (state.until ? badge.dataset.openUntil.replace(':time', state.until) : badge.dataset.openLabel)
            : badge.dataset.closedLabel;
        badge.dataset.state = state.open ? 'open' : 'closed';
        badge.hidden = false;
    });
}
