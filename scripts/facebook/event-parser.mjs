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
    const hosts = [...new Map([
        ...(event.event_hosts_that_can_view_guestlist ?? []),
        ...(event.parent_if_exists_or_self?.event_accepted_cohosts?.nodes ?? []),
    ].map(host => [host.id ?? host.url ?? host.name, host])).values()];
    const links = [...new Set((event.event_description.ranges ?? []).map(range => cleanUrl(range.entity?.external_url ?? range.entity?.url)).filter(Boolean))];
    const end = instant(event.end_timestamp ?? event.current_end_timestamp);
    return {
        id, url: `https://www.facebook.com/events/${id}/`,
        title: event.name ?? null, description: event.event_description.text,
        starts_at: instant(event.current_start_timestamp ?? event.start_timestamp), ends_at: end,
        date_label: event.day_time_sentence ?? event.start_time_formatted ?? null,
        is_online: event.is_online ?? false, is_cancelled: event.is_canceled ?? false,
        frequency: event.parent_if_exists_or_self?.event_frequency ?? null,
        cover: cover?.full_image ? { ...cover.full_image, url: cleanUrl(cover.full_image.uri), caption: cover.accessibility_caption ?? null } : null,
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
