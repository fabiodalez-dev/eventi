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

    // Le fondamenta della redazione nell'app (26/11/2026): banco, elenchi, sblocco.
    expect($document['paths'])->toHaveKeys(['/editorial/desk', '/editorial/options', '/editorial/unlock'])
        ->and($document['paths']['/editorial/desk'])->toHaveKey('get')
        ->and($document['paths']['/editorial/unlock'])->toHaveKey('post');

    // R1 della redazione (26/11/2026): il riepilogo.
    expect($document['paths'])->toHaveKey('/editorial/dashboard')
        ->and($document['paths']['/editorial/dashboard'])->toHaveKey('get')
        ->and($document['paths']['/editorial/dashboard']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['properties'])->toHaveKeys(['city', 'sections']);
    // R5 (26/11/2026): gli utenti nella redazione dell'app.
    expect($document['paths'])->toHaveKeys([
        '/editorial/users',
        '/editorial/users/options',
        '/editorial/users/{user}',
        '/editorial/users/{user}/credentials',
        '/editorial/users/{user}/roles',
        '/editorial/users/{user}/restore',
        '/editorial/users/{user}/community/suspend',
        '/editorial/users/{user}/community/unsuspend',
        '/editorial/users/{user}/community/handle',
        '/editorial/users/{user}/whatsapp',
        '/editorial/users/{user}/whatsapp/history',
    ])
        ->and(array_keys($document['paths']['/editorial/users/{user}']))->toContain('get', 'patch', 'delete')
        ->and($document['paths']['/editorial/users/{user}/roles'])->toHaveKey('put');
    // R6 (26/11/2026): la moderazione della redazione, segnalazioni, recensioni, commenti.
    expect($document['paths'])->toHaveKeys([
        '/editorial/reports',
        '/editorial/reports/{report}',
        '/editorial/reviews',
        '/editorial/reviews/{review}/moderate',
        '/editorial/reviews/{review}/reply',
        '/editorial/comments',
        '/editorial/comments/{comment}',
        '/editorial/comments/{comment}/hide',
        '/editorial/comments/{comment}/restore',
    ])
        ->and($document['paths']['/editorial/reports/{report}'])->toHaveKeys(['get', 'patch', 'delete'])
        ->and($document['paths']['/editorial/comments/{comment}'])->toHaveKeys(['get', 'delete'])
        ->and($document['paths']['/editorial/reviews/{review}/reply'])->toHaveKey('delete');
    // R4 della redazione (26/11/2026): locali, richieste di iscrizione, organizzatori.
    expect($document['paths'])->toHaveKeys([
        '/editorial/venues',
        '/editorial/venues/export',
        '/editorial/venues/choices',
        '/editorial/venues/locate',
        '/editorial/venues/{venue}',
        '/editorial/venues/{venue}/form',
        '/editorial/venues/{venue}/approve',
        '/editorial/venues/{venue}/reject',
        '/editorial/venues/{venue}/suspend',
        '/editorial/venues/{venue}/verify',
        '/editorial/venues/{venue}/ticketing',
        '/editorial/venues/{venue}/events',
        '/editorial/venues/{venue}/activity',
        '/editorial/venues/{venue}/activity/{activity}/restore',
        '/editorial/venues/{venue}/media',
        '/editorial/venues/{venue}/media/{collection}',
        '/editorial/venues/{venue}/media/gallery',
        '/editorial/venues/{venue}/media/gallery/{media}',
        '/editorial/venues/{venue}/media/gallery/order',
        '/editorial/venues/{venue}/members',
        '/editorial/venues/{venue}/members/candidates',
        '/editorial/venues/{venue}/members/invite',
        '/editorial/venues/{venue}/members/{user}',
        '/editorial/applications',
        '/editorial/applications/{application}',
        '/editorial/applications/{application}/image',
        '/editorial/applications/{application}/approve',
        '/editorial/applications/{application}/reject',
        '/editorial/applications/{application}/notes',
        '/editorial/organizers',
        '/editorial/organizers/people',
        '/editorial/organizers/{organizer}',
    ])
        ->and($document['paths']['/editorial/venues/{venue}'])->toHaveKeys(['get', 'patch', 'delete'])
        ->and($document['paths']['/editorial/venues'])->toHaveKeys(['get', 'post'])
        ->and($document['paths']['/editorial/organizers/{organizer}'])->toHaveKeys(['get', 'patch'])
        ->and($document['paths']['/editorial/organizers/{organizer}'])->not->toHaveKey('delete');
    // R2 della redazione (26/11/2026): eventi, decisioni, cronologia, proposte dal pubblico.
    expect($document['paths'])->toHaveKeys([
        '/editorial/events',
        '/editorial/events/filters',
        '/editorial/events/export',
        '/editorial/events/{event}',
        '/editorial/events/{event}/preview',
        '/editorial/events/{event}/publish',
        '/editorial/events/{event}/unpublish',
        '/editorial/events/{event}/reject',
        '/editorial/events/{event}/cancel',
        '/editorial/events/{event}/feature',
        '/editorial/events/{event}/duplicate',
        '/editorial/events/{event}/merge-candidates',
        '/editorial/events/{event}/merge',
        '/editorial/events/{event}/restore',
        '/editorial/events/bulk-delete',
        '/editorial/events/{event}/activity',
        '/editorial/events/{event}/activity/{activity}/restore',
        '/editorial/submissions',
        '/editorial/submissions/{submission}',
        '/editorial/submissions/{submission}/publish',
        '/editorial/submissions/{submission}/reject',
    ])
        ->and($document['paths']['/editorial/events/{event}'])->toHaveKeys(['get', 'delete'])
        ->and($document['paths']['/editorial/events/{event}/merge'])->toHaveKey('post');
    // R12 (26/11/2026): sistema della redazione, sorgenti di import, invii previsti, cron.
    expect($document['paths'])->toHaveKeys([
        '/editorial/import-sources',
        '/editorial/import-sources/form',
        '/editorial/import-sources/venues',
        '/editorial/import-sources/{source}',
        '/editorial/import-sources/{source}/preview',
        '/editorial/import-sources/{source}/run',
        '/editorial/import-sources/{source}/runs',
        '/editorial/scheduled-notifications',
        '/editorial/scheduled-notifications/{notification}/cancel',
        '/editorial/scheduled-operations',
    ])
        ->and($document['paths']['/editorial/import-sources'])->toHaveKeys(['get', 'post'])
        ->and($document['paths']['/editorial/import-sources/{source}'])->toHaveKeys(['get', 'patch', 'delete'])
        ->and($document['paths']['/editorial/scheduled-notifications/{notification}/cancel'])->toHaveKey('post');

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
        ->and($document['paths']['/venues/{slug}/claim']['post']['security'])->toBe([['http' => []]])
        ->and($document['paths']['/venues/{slug}/claim']['get']['responses'])->not->toHaveKey('304')
        ->and($document['paths']['/venue-applications/lookup']['get']['responses'])->not->toHaveKey('304');

    // Q2 (26/11/2026): le tre letture del modulo sono pubbliche (l'ospite compila prima di avere
    // l'account), con il token facoltativo; lo scambio del collegamento dice se chiude una registrazione.
    foreach (['/venue-applications/lookup', '/me/venue-applications/options', '/venues/{slug}/claim'] as $path) {
        expect($document['paths'][$path]['get']['security'])->toBe([[], ['http' => []]])
            ->and($document['paths'][$path]['get']['responses'])->not->toHaveKey('401');
    }
    expect($document['paths']['/auth/magic-link/exchange']['post']['responses']['200']['content']['application/json']['schema']['properties']['data']['properties'])
        ->toHaveKey('registration');

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

    // P10 (25/11/2026): lo studio delle grafiche social del locale e l'anteprima della grafica di una data, per i due soggetti.
    expect($document['paths'])->toHaveKeys([
        '/management/venues/{venue}/social/catalog',
        '/management/venues/{venue}/social/calendar',
        '/management/venues/{venue}/social/day',
        '/management/venues/{venue}/social/events',
        '/management/venues/{venue}/social/batches',
        '/management/venues/{venue}/social/batches/{batch}',
        '/management/venues/{venue}/social/batches/{batch}/images/{index}',
        '/management/venues/{venue}/social/batches/{batch}/zip',
        '/management/venues/{venue}/events/{event}/dates/{occurrence}/social-preview',
        '/management/organizers/{organizer}/events/{event}/dates/{occurrence}/social-preview',
    ])->and($document['paths'])->not->toHaveKey('/management/organizers/{organizer}/social/catalog')
        ->and($document['paths']['/management/venues/{venue}/social/batches'])->toHaveKeys(['get', 'post']);
    // P11 (25/11/2026): il riepilogo dei due soggetti, il programma in PDF solo del locale.
    expect($document['paths'])->toHaveKeys([
        '/management/venues/{venue}/dashboard',
        '/management/venues/{venue}/dashboard/programme',
        '/management/organizers/{organizer}/dashboard',
    ])->and($document['paths'])->not->toHaveKey('/management/organizers/{organizer}/dashboard/programme')
        ->and(collect($document['paths']['/management/venues/{venue}/dashboard/programme']['get']['parameters'])->pluck('name')->all())->toContain('week');
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

it('tiene docs/openapi.json e il conteggio di docs/API.md allineati alle rotte vere', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $staff = User::factory()->create();
    $staff->assignRole(UserRole::Admin->value);
    $operations = fn (array $document): array => collect($document['paths'])
        ->flatMap(fn (array $methods, string $path): array => array_map(fn (string $method): string => strtoupper($method).' '.$path,
            array_values(array_intersect(array_keys($methods), ['get', 'post', 'put', 'patch', 'delete']))))
        ->sort()->values()->all();

    $live = $operations($this->actingAs($staff)->getJson('/docs/api.json')->assertOk()->json());
    $versioned = $operations(json_decode((string) file_get_contents(base_path('docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR));

    // Il JSON versionato è quello che legge chi genera il client dell'app: se manca una rotta, va rigenerato.
    expect(array_values(array_diff($live, $versioned)))->toBe([])
        ->and(array_values(array_diff($versioned, $live)))->toBe([]);
    expect((string) file_get_contents(base_path('docs/API.md')))->toContain('**'.count($versioned).' operazioni**');
});
