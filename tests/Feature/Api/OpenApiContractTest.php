<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

it('espone un contratto OpenAPI utilizzabile da un generatore Android', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $staff = User::factory()->create();
    $staff->assignRole(UserRole::Admin->value);

    $document = $this->actingAs($staff)->getJson('/docs/api.json')->assertOk()->json();

    // Conta la famiglia 3.1, che è quella che il generatore Android sa leggere: Scramble 0.13.49 dichiara 3.1.2, una semplice revisione.
    expect($document['openapi'] ?? null)->toStartWith('3.1.')
        ->and(count($document['paths'] ?? []))->toBeGreaterThanOrEqual(54)
        ->and($document['paths']['/events']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['items']['$ref'] ?? null)
        ->toBe('#/components/schemas/MobileOccurrence')
        ->and($document['paths']['/events']['get']['responses'])->toHaveKeys(['304', '429'])
        ->and($document['paths']['/events']['get']['security'] ?? null)
        ->toBe([[], ['http' => []]])
        ->and($document['components']['schemas'])->toHaveKeys([
            'MobileOccurrence',
            'MobileEvent',
            'MobileVenue',
            'ApiErrorEnvelope',
            'MobileBooking',
        ]);

    // Le rotte e i campi aggiunti per l'app il 19/11/2026 (pacchetto api-app).
    expect($document['paths'])->toHaveKeys(['/management/dates/{occurrence}/bookings', '/reports/content/{type}/{id}', '/me/venue-applications'])
        ->and(collect($document['paths']['/calendar']['get']['parameters'])->pluck('name')->all())->toContain('month', 'personal')
        ->and($document['paths']['/reports/content/{type}/{id}']['post']['responses'])->toHaveKey('204')
        // Senza `security` propria vale quella del documento: il token.
        ->and($document['paths']['/me/venue-applications']['get']['security'] ?? $document['security'])->toBe([['http' => []]])
        ->and($document['components']['schemas']['MobileEvent']['properties'])->toHaveKey('share_links')
        ->and($document['components']['schemas']['MobileVenue']['properties'])->toHaveKeys(['claimable', 'claim_url'])
        ->and($document['components']['schemas']['MobileBooking']['properties'])->toHaveKey('poster');

    // Le fondamenta dell'app di gestione (25/11/2026): identità, opzioni, immagini.
    expect($document['paths'])->toHaveKeys([
        '/management/subjects',
        '/management/options',
        '/management/venues/{venue}/events/{event}/poster',
        '/management/organizers/{organizer}/events/{event}/poster',
        '/management/venues/{venue}/media',
        '/management/venues/{venue}/media/{collection}',
        '/management/venues/{venue}/media/gallery',
        '/management/venues/{venue}/media/gallery/{media}',
        '/management/venues/{venue}/media/gallery/order',
    ])
        ->and(collect($document['paths']['/management/venues/{venue}/media/{collection}']['post']['parameters'])->pluck('name')->all())->toBe(['venue', 'collection'])
        ->and($document['paths']['/management/venues/{venue}/events/{event}/poster']['post']['requestBody']['content'])->toHaveKey('multipart/form-data')
        ->and($document['paths']['/management/venues/{venue}/media/gallery']['post']['responses'])->toHaveKey('201');

    // P8 (25/11/2026): collaboratori del locale, «Il tuo accesso», inviti ricevuti.
    expect($document['paths'])->toHaveKeys([
        '/management/venues/{venue}/members',
        '/management/venues/{venue}/members/{member}',
        '/management/venues/{venue}/members/{member}/resend',
        '/management/venues/{venue}/members/{member}/invitation',
        '/management/venues/{venue}/access',
        '/management/venues/{venue}/access/leave',
        '/me/invitations',
        '/me/invitations/venues/{venue}',
        '/me/invitations/checkin/{invitation}',
    ])->and($document['paths'])->not->toHaveKey('/management/organizers/{organizer}/members');
    // P9 (25/11/2026): il calendario collegato del locale, solo sotto `venues`.
    expect($document['paths'])->toHaveKeys([
        '/management/venues/{venue}/import',
        '/management/venues/{venue}/import/preview',
        '/management/venues/{venue}/import/run',
        '/management/venues/{venue}/import/pause',
        '/management/venues/{venue}/import/resume',
    ])
        ->and($document['paths']['/management/venues/{venue}/import'])->toHaveKeys(['get', 'post', 'delete'])
        ->and($document['paths'])->not->toHaveKey('/management/organizers/{organizer}/import');
    // Il profilo del locale e dell'organizzazione (P4).
    expect($document['paths'])->toHaveKeys([
        '/management/venues/{venue}/profile',
        '/management/venues/{venue}/notices',
        '/management/venues/{venue}/contact/resend',
        '/management/organizers/{organizer}/profile',
        '/management/organizers/{organizer}/contact/resend',
    ])
        ->and($document['paths']['/management/venues/{venue}/profile'])->toHaveKeys(['get', 'patch'])
        ->and($document['paths']['/management/venues/{venue}/notices']['post']['responses'])->toHaveKey('201');
    // Le date e le ricorrenze (P2): annulla, elimina, «Ripeti» ed esaurito sulla prossima data solo per il locale.
    expect($document['paths'])->toHaveKeys([
        '/management/venues/{venue}/events/{event}/dates',
        '/management/venues/{venue}/events/{event}/dates/{occurrence}',
        '/management/venues/{venue}/events/{event}/dates/{occurrence}/status',
        '/management/venues/{venue}/events/{event}/dates/{occurrence}/poster.pdf',
        '/management/venues/{venue}/events/{event}/recurrence',
        '/management/venues/{venue}/events/{event}/next-date/sold-out',
        '/management/organizers/{organizer}/events/{event}/dates',
        '/management/organizers/{organizer}/events/{event}/dates/{occurrence}',
        '/management/organizers/{organizer}/events/{event}/dates/{occurrence}/poster.pdf',
    ])
        ->and($document['paths']['/management/venues/{venue}/events/{event}/dates/{occurrence}'])->toHaveKeys(['get', 'patch', 'delete'])
        ->and($document['paths']['/management/organizers/{organizer}/events/{event}/dates/{occurrence}'])->not->toHaveKey('delete')
        ->and($document['paths'])->not->toHaveKey('/management/organizers/{organizer}/events/{event}/recurrence');

    // P13 (25/11/2026): registrare o rivendicare dall'app, solo con il token e mai in cache.
    expect($document['paths'])->toHaveKeys([
        '/venue-applications/lookup',
        '/me/venue-applications/options',
        '/me/venue-applications/{application}/confirm',
        '/me/venue-applications/{application}',
        '/me/organizer-applications',
        '/venues/{slug}/claim',
    ])
        ->and($document['paths']['/me/venue-applications'])->toHaveKeys(['get', 'post'])
        ->and($document['paths']['/me/venue-applications']['post']['requestBody']['content'])->toHaveKey('multipart/form-data')
        ->and($document['paths']['/me/venue-applications']['post']['responses'])->toHaveKey('201')
        ->and($document['paths']['/venues/{slug}/claim'])->toHaveKeys(['get', 'post'])
        ->and($document['paths']['/venues/{slug}/claim']['get']['security'])->toBe([['http' => []]])
        ->and($document['paths']['/venues/{slug}/claim']['get']['responses'])->not->toHaveKey('304')
        ->and($document['paths']['/venue-applications/lookup']['get']['security'])->toBe([['http' => []]])
        ->and($document['paths']['/venue-applications/lookup']['get']['responses'])->not->toHaveKey('304');

    // Le sponsorizzazioni (P6): pagina, scelta, ordini, campagne e statistiche, per i due soggetti.
    foreach (['/management/venues/{venue}', '/management/organizers/{organizer}'] as $subject) {
        expect($document['paths'])->toHaveKeys(array_map(fn (string $path): string => $subject.'/sponsorships'.$path, [
            '', '/events', '/catalog', '/campaigns', '/campaigns/{campaign}', '/campaigns/{campaign}/stop',
            '/orders', '/orders/{order}', '/orders/{order}/retry', '/orders/{order}/cancel', '/orders/{order}/reconcile',
            '/grants/{grant}/events', '/analytics', '/analytics/clicks', '/analytics/campaigns',
        ]));
    }
    expect($document['paths']['/management/venues/{venue}/sponsorships/orders'])->toHaveKey('post')
        ->and($document['paths']['/management/venues/{venue}/sponsorships/orders/{order}'])->toHaveKey('delete');

    expect($document['paths']['/occurrences/{occurrence}/bookings']['post']['responses'])->toHaveKey('201')
        ->and($document['paths']['/me/bookings']['get']['security'])->toBe([['http' => []]])
        ->and($document['paths']['/me/bookings']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['items']['$ref'])->toBe('#/components/schemas/MobileBooking')
        ->and($document['paths']['/occurrences/{occurrence}/booking']['get']['responses'])->not->toHaveKey('304')
        ->and($document['components']['schemas']['MobileBooking']['properties']['can_cancel']['type'])->toBe('boolean');

    // P5 (25/11/2026): statistiche, tabelle ed esportazioni, pagina di un soggetto, date di un evento, link brevi, canali propri del locale.
    expect($document['paths'])->toHaveKeys([
        '/management/venues/{venue}/analytics/summary',
        '/management/venues/{venue}/analytics/report',
        '/management/venues/{venue}/analytics/report/table',
        '/management/venues/{venue}/analytics/report/export',
        '/management/venues/{venue}/analytics/events',
        '/management/venues/{venue}/analytics/subjects/{type}/{id}',
        '/management/venues/{venue}/analytics/subjects/{type}/{id}/table',
        '/management/venues/{venue}/analytics/subjects/{type}/{id}/export',
        '/management/venues/{venue}/analytics/channels',
        '/management/venues/{venue}/events/{event}/dates/analytics',
        '/management/venues/{venue}/events/{event}/dates/{occurrence}/analytics',
        '/management/venues/{venue}/events/{event}/share-links',
        '/management/organizers/{organizer}/analytics/summary',
        '/management/organizers/{organizer}/analytics/subjects/{type}/{id}',
    ])->and($document['paths'])->not->toHaveKey('/management/organizers/{organizer}/analytics/channels')
        ->and($document['paths']['/management/venues/{venue}/analytics/channels'])->toHaveKeys(['get', 'post']);

    // P12 (25/11/2026): gli avvisi a chi gestisce il soggetto e lo slug di un evento del pannello.
    expect($document['paths'])->toHaveKeys([
        '/management/venues/{venue}/notifications',
        '/management/venues/{venue}/events/by-slug/{slug}',
        '/management/organizers/{organizer}/notifications',
        '/management/organizers/{organizer}/events/by-slug/{slug}',
    ])->and(collect($document['paths']['/management/venues/{venue}/notifications']['get']['parameters'])->pluck('name')->all())->toContain('cursor', 'limit');

    $inspect = function (mixed $node) use (&$inspect): void {
        if (! is_array($node)) {
            return;
        }

        if (array_key_exists('properties', $node) && is_array($node['properties'])) {
            expect($node['properties'])->not->toHaveKey('');
        }

        if (array_key_exists('required', $node) && is_array($node['required'])) {
            expect($node['required'])->not->toContain(null, '');
        }

        foreach ($node as $child) {
            $inspect($child);
        }
    };

    foreach ($document['paths'] as $path => $methods) {
        if (! str_starts_with($path, '/management/')) {
            continue;
        }
        foreach ($methods as $operation) {
            expect($operation['security'])->toBe([['http' => []]])
                ->and($operation['responses'])->not->toHaveKey('304');
        }
    }

    $inspect($document);
});

it('genera tutte le rotte senza errori di analisi OpenAPI', function (): void {
    $this->artisan('scramble:analyze')->assertSuccessful();
});
