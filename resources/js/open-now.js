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

// Extraordinary closures (App\Support\VenueClosures): calendar days in the
// city's timezone, both ends included, that override the weekly hours.
export function closureOn(closures, now, timeZone) {
    const day = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
    return (Array.isArray(closures) ? closures : []).find((closure) => typeof closure?.from === 'string' && typeof closure?.to === 'string'
        && closure.from <= day && day <= closure.to) ?? null;
}

export function openingState(hours, now, timeZone, closures = []) {
    const closure = closureOn(closures, now, timeZone);
    if (closure) return { open: false, until: null, closure };
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        .formatToParts(now).map((part) => [part.type, part.value]));
    const index = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(parts.weekday);
    const minute = Number(parts.hour) * 60 + Number(parts.minute);
    hours = hours ?? {};
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
        let closures;
        try { hours = JSON.parse(badge.dataset.hours || '{}'); closures = JSON.parse(badge.dataset.closures || '[]'); } catch { return; }
        const state = openingState(hours, now, badge.dataset.timezone, closures);
        if (!state.closure && Object.keys(hours).length === 0) return;
        const until = state.closure
            ? new Intl.DateTimeFormat('it-IT', { day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(`${state.closure.to}T12:00:00Z`))
            : null;
        badge.textContent = state.closure
            ? badge.dataset.closureLabel.replace(':date', until)
            : state.open
                ? (state.until ? badge.dataset.openUntil.replace(':time', state.until) : badge.dataset.openLabel)
                : badge.dataset.closedLabel;
        badge.dataset.state = state.open ? 'open' : 'closed';
        badge.hidden = false;
    });
}

// Closures that have ended disappear even from a cached venue page.
export function hidePastClosures(root = document, now = new Date()) {
    root.querySelectorAll('[data-venue-closures]').forEach((section) => {
        const day = new Intl.DateTimeFormat('en-CA', { timeZone: section.dataset.timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
        let visible = 0;
        section.querySelectorAll('[data-closure-ends]').forEach((item) => {
            item.hidden = item.dataset.closureEnds < day;
            visible += item.hidden ? 0 : 1;
        });
        section.hidden = visible === 0;
    });
}
