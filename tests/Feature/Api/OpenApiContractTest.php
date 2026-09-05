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

    expect($document['openapi'] ?? null)->toBe('3.1.0')
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

    expect($document['paths']['/occurrences/{occurrence}/bookings']['post']['responses'])->toHaveKey('201')
        ->and($document['paths']['/me/bookings']['get']['security'])->toBe([['http' => []]])
        ->and($document['paths']['/me/bookings']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['items']['$ref'])->toBe('#/components/schemas/MobileBooking')
        ->and($document['paths']['/occurrences/{occurrence}/booking']['get']['responses'])->not->toHaveKey('304')
        ->and($document['components']['schemas']['MobileBooking']['properties']['can_cancel']['type'])->toBe('boolean');

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

    $inspect($document);
});
