<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Due locali distinti nella stessa città, ciascuno con i propri eventi, le
 * proprie occorrenze e le proprie persone, più lo staff globale.
 *
 * È l'impianto dello scenario F di §18: tutto ciò che serve per chiedersi se
 * chi gestisce il locale A riesca a toccare qualcosa del locale B.
 */
final class VenueIsolationScenario
{
    private function __construct(
        public City $city,
        public Category $category,
        public Venue $venueA,
        public Venue $venueB,
        public Event $publishedEventA,
        public Event $draftEventA,
        public Event $publishedEventB,
        public Event $draftEventB,
        public EventOccurrence $occurrenceA,
        public EventOccurrence $occurrenceB,
        public User $ownerA,
        public User $editorA,
        public User $ownerB,
        public User $plainUser,
        public User $moderator,
        public User $admin,
    ) {}

    public static function make(): self
    {
        (new RolesAndPermissionsSeeder)->run();

        $city = City::factory()->padova()->create();
        $category = Category::factory()->create([
            'name' => 'Musica dal vivo',
            'default_duration_minutes' => 180,
            'supports_ongoing' => true,
            'is_nightlife' => false,
        ]);

        $venueA = Venue::factory()->approved()->create([
            'city_id' => $city->getKey(),
            'name' => 'Circolo Aurora',
        ]);
        $venueB = Venue::factory()->approved()->create([
            'city_id' => $city->getKey(),
            'name' => 'Teatro Belzoni',
        ]);

        $ownerA = self::member($venueA, UserRole::VenueOwner, VenueRole::Owner);
        $editorA = self::member($venueA, UserRole::VenueEditor, VenueRole::Editor);
        $ownerB = self::member($venueB, UserRole::VenueOwner, VenueRole::Owner);

        $plainUser = self::withRole(UserRole::User);
        $moderator = self::withRole(UserRole::Moderator);
        $admin = self::withRole(UserRole::Admin);

        $publishedEventA = self::event($city, $category, $venueA, $ownerA, EventStatus::Published, 'Concerto di musica popolare');
        $draftEventA = self::event($city, $category, $venueA, $ownerA, EventStatus::Draft, 'Bozza del circolo Aurora');
        $publishedEventB = self::event($city, $category, $venueB, $ownerB, EventStatus::Published, 'Reading su poesia contemporanea');
        $draftEventB = self::event($city, $category, $venueB, $ownerB, EventStatus::Draft, 'Bozza del teatro Belzoni');

        return new self(
            city: $city,
            category: $category,
            venueA: $venueA,
            venueB: $venueB,
            publishedEventA: $publishedEventA,
            draftEventA: $draftEventA,
            publishedEventB: $publishedEventB,
            draftEventB: $draftEventB,
            occurrenceA: self::occurrence($publishedEventA, '2026-09-11 19:00:00'),
            occurrenceB: self::occurrence($publishedEventB, '2026-09-12 19:00:00'),
            ownerA: $ownerA,
            editorA: $editorA,
            ownerB: $ownerB,
            plainUser: $plainUser,
            moderator: $moderator,
            admin: $admin,
        );
    }

    /**
     * Persona che gestisce un locale: ruolo globale (§3) più riga nella pivot
     * `venue_user`. Servono entrambi — il ruolo dà il permesso, la pivot dice
     * su quale locale.
     */
    private static function member(Venue $venue, UserRole $role, VenueRole $pivotRole): User
    {
        $user = self::withRole($role);

        $user->venues()->attach($venue, [
            'role' => $pivotRole->value,
            'invited_at' => CarbonImmutable::now('UTC'),
            'accepted_at' => CarbonImmutable::now('UTC'),
        ]);

        return $user;
    }

    private static function withRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    private static function event(
        City $city,
        Category $category,
        Venue $venue,
        User $creator,
        EventStatus $status,
        string $title,
    ): Event {
        return Event::factory()->create([
            'city_id' => $city->getKey(),
            'category_id' => $category->getKey(),
            'venue_id' => $venue->getKey(),
            'created_by' => $creator->getKey(),
            'title' => $title,
            'status' => $status,
            'published_at' => $status === EventStatus::Published ? CarbonImmutable::now('UTC') : null,
        ]);
    }

    private static function occurrence(Event $event, string $startsAtUtc): EventOccurrence
    {
        return EventOccurrence::factory()->create([
            'event_id' => $event->getKey(),
            'starts_at' => CarbonImmutable::parse($startsAtUtc, 'UTC'),
            'ends_at' => null,
            'doors_at' => null,
        ]);
    }
}
