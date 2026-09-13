// Event-specific extraction: suggested events must never replace the requested ID.
export function facebookEventId(input) {
    if (typeof input !== 'string' || input.length > 2048) throw new Error('Link Facebook non valido.');
    const url = new URL(input);
    if (url.protocol !== 'https:' || url.username || url.password || url.port ||
        !['facebook.com', 'www.facebook.com', 'm.facebook.com', 'web.facebook.com'].includes(url.hostname)) {
        throw new Error('Inserisci un link HTTPS a un evento Facebook.');
    }
    const match = url.pathname.match(/^\/events\/(?:[^/]+\/)*([0-9]+)\/?$/);
    if (!match) throw new Error('Il link deve aprire un singolo evento Facebook.');
    return match[1];
}

export function collectEventNodes(scripts, id) {
    const nodes = [];
    const walk = (value) => {
        if (!value || typeof value !== 'object') return;
        if (value.id === id) nodes.push(value);
        for (const child of Object.values(value)) walk(child);
    };
    for (const script of scripts) {
        try { walk(JSON.parse(script)); } catch { /* A non-JSON script is not event data. */ }
    }
    return nodes;
}

// Accept only complete, explicit Italian ranges. Never infer an end from duration.
function endFromLabel(label, start) {
    if (typeof label !== 'string' || !start) return null;
    const match = label.match(/^(\d{1,2}) (gen|feb|mar|apr|mag|giu|lug|ago|set|ott|nov|dic) alle ore (\d{2}):(\d{2}) - (?:(\d{1,2}) (gen|feb|mar|apr|mag|giu|lug|ago|set|ott|nov|dic) alle ore )?(\d{2}):(\d{2}) (CEST|CET)$/i);
    if (!match) return null;
    const months = ['gen','feb','mar','apr','mag','giu','lug','ago','set','ott','nov','dic'];
    const parts = date => Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/Rome', year: 'numeric', month: 'numeric', day: 'numeric',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(date).map(part => [part.type, Number(part.value)]));
    const first = parts(new Date(start));
    const startMonth = months.indexOf(match[2].toLowerCase()) + 1;
    if (first.month !== startMonth || first.day !== +match[1] || first.hour !== +match[3] || first.minute !== +match[4]) return null;
    const month = match[6] ? months.indexOf(match[6].toLowerCase()) + 1 : startMonth;
    const day = +(match[5] ?? match[1]);
    const year = first.year + (month < startMonth ? 1 : 0);
    const hour = +match[7], minute = +match[8];
    const offset = match[9].toUpperCase() === 'CEST' ? 2 : 1;
    const end = new Date(Date.UTC(year, month - 1, day, hour - offset, minute));
    const actual = parts(end);
    if (actual.year !== year || actual.month !== month || actual.day !== day || actual.hour !== hour || actual.minute !== minute || end <= new Date(start)) return null;
    return end.toISOString();
}

export function parseEventNodes(nodes, id) {
    const event = {};
    const merge = (target, value) => {
        for (const [key, item] of Object.entries(value)) {
            if (key.startsWith('__') || key === 'constructor' || key === 'prototype' || item === null || item === undefined) continue;
            if (typeof item === 'object' && !Array.isArray(item)) {
                target[key] = merge(typeof target[key] === 'object' && target[key] !== null ? target[key] : {}, item);
            } else target[key] = item;
        }
        return target;
    };
    for (const node of nodes) if (node.id === id) merge(event, node);
    if (!event.name?.trim() || !event.event_description?.text?.trim()) {
        throw new Error('Facebook non ha fornito i dettagli completi di questo evento pubblico. Riprova oppure compila il modulo manualmente.');
    }
    const instant = value => Number.isFinite(Number(value)) && Number(value) > 0 ? new Date(Number(value) * 1000).toISOString() : null;
    const cleanUrl = value => {
        try {
            const url = new URL(value);
            if (url.hostname === 'l.facebook.com') return cleanUrl(url.searchParams.get('u'));
            return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : null;
        } catch { return null; }
    };
    const place = event.event_place;
    const cover = event.cover_media_renderer?.cover_photo?.photo;
    const coverArea = candidate => Number.isFinite(candidate?.width) && Number.isFinite(candidate?.height)
        && candidate.width > 0 && candidate.height > 0 ? candidate.width * candidate.height : 0;
    const coverImage = [cover?.full_image, cover?.viewer_image, cover?.image]
        .filter(candidate => candidate && cleanUrl(candidate.uri) && coverArea(candidate) <= 40000000)
        .sort((a, b) => coverArea(b) - coverArea(a))[0];
    const hostMap = new Map();
    for (const host of [
        ...(event.event_hosts_that_can_view_guestlist ?? []),
        ...(event.parent_if_exists_or_self?.event_accepted_cohosts?.nodes ?? []),
    ]) {
        const key = host.id ?? host.url ?? host.name;
        hostMap.set(key, merge(hostMap.get(key) ?? {}, host));
    }
    const hosts = [...hostMap.values()];
    const links = [...new Set((event.event_description.ranges ?? []).map(range => cleanUrl(range.entity?.external_url ?? range.entity?.url)).filter(Boolean))];
    const start = instant(event.current_start_timestamp ?? event.start_timestamp);
    const end = instant(event.end_timestamp ?? event.current_end_timestamp) ?? endFromLabel(event.day_time_sentence, start);
    return {
        id, url: `https://www.facebook.com/events/${id}/`,
        title: event.name ?? null, description: event.event_description.text,
        starts_at: start, ends_at: end,
        date_label: event.day_time_sentence ?? event.start_time_formatted ?? null,
        is_online: event.is_online ?? false, is_cancelled: event.is_canceled ?? false,
        frequency: event.parent_if_exists_or_self?.event_frequency ?? null,
        cover: coverImage ? { ...coverImage, url: cleanUrl(coverImage.uri), caption: cover.accessibility_caption ?? null } : null,
        venue: place ? { id: place.id ?? null, name: place.name ?? place.contextual_name, address: event.one_line_address ?? place.address?.full_address ?? null, latitude: place.location?.latitude ?? null, longitude: place.location?.longitude ?? null, url: cleanUrl(place.url) } : null,
        hosts: hosts.map(host => ({ id: host.id, name: host.name, url: cleanUrl(host.url) })),
        ticket_url: cleanUrl(event.event_buy_ticket_url), price_info: event.price_info ?? event.event_price_and_duration_context_row_info ?? null,
        responded_count: event.event_connected_users_public_responded?.count ?? null,
        interested_count: event.event_connected_users_public_interested?.count ?? null,
        going_count: event.event_connected_users_public_going?.count ?? null,
        categories: event.discovery_categories ?? [], keywords: event.catkit_keywords ?? [],
        description_photos: event.description_photos ?? [], lineup: event.event_lineups?.edges ?? [],
        external_links: links, fetched_at: new Date().toISOString(),
    };
}
