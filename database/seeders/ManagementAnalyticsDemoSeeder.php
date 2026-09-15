<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ContentMetric;
use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\VenueStatus;
use App\Enums\VenueType;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/** Explicit fixtures only: never registered in the normal deployment seeders. */
class ManagementAnalyticsDemoSeeder extends Seeder
{
    public const EMAIL = 'gestore-officina-portici@example.test';

    /** @return array{venue: Venue, organizer: Organizer, password: ?string, events: int} */
    public function seedWeek(City $city, CarbonImmutable $week, ?CarbonImmutable $asOf = null): array
    {
        return DB::transaction(function () use ($city, $week, $asOf): array {
            $password = Str::password(22, symbols: false);
            $owner = User::firstOrCreate(['email' => self::EMAIL], [
                'name' => 'Elena Riva', 'email_verified_at' => now(), 'password' => Hash::make($password),
            ]);
            $newOwner = $owner->wasRecentlyCreated;
            if (! $newOwner && $owner->name !== 'Elena Riva') {
                throw new RuntimeException('L’indirizzo del seed appartiene a un account diverso. Nessuna modifica applicata.');
            }
            $owner->assignRole(['user', 'venue_owner']);
            $venue = Venue::firstOrCreate(['slug' => 'officina-dei-portici'], [
                'city_id' => $city->id, 'name' => 'Officina dei Portici',
                'description' => 'Uno spazio culturale nel cuore di Padova, dedicato alla musica dal vivo, ai laboratori creativi e agli incontri tra persone e storie.',
                'address' => 'Via dei Portici, Padova', 'municipality' => 'Padova', 'province_code' => 'PD',
                'lat' => 45.4092, 'lng' => 11.8767, 'type' => VenueType::Altro,
                'status' => VenueStatus::Approved, 'approved_at' => now(),
            ]);
            if ((int) $venue->city_id !== (int) $city->id || (! $venue->wasRecentlyCreated && ! $owner->venues()->whereKey($venue->id)->exists())) {
                throw new RuntimeException('Il locale esistente non appartiene a questo seed. Nessuna modifica applicata.');
            }
            $owner->venues()->syncWithoutDetaching([$venue->id => ['role' => 'owner']]);
            $organizer = Organizer::firstOrCreate(['slug' => 'collettivo-portici-aperti'], [
                'city_id' => $city->id, 'owner_id' => $owner->id, 'name' => 'Collettivo Portici Aperti',
                'description' => 'Musica, parole e creatività a Padova. Un collettivo che cura incontri e appuntamenti culturali negli spazi della città.', 'is_active' => true,
            ]);
            if ((int) $organizer->owner_id !== (int) $owner->id || (int) $organizer->city_id !== (int) $city->id) {
                throw new RuntimeException('L’organizzatore esistente non appartiene a questo seed. Nessuna modifica applicata.');
            }
            $today = ($asOf ?? CarbonImmutable::now($city->timezone))->startOfDay();
            $key = $week->toDateString();
            $titles = [
                ['Jazz sotto i portici', 'musica-dal-vivo'],
                ['Taccuini di città: disegnare Padova', 'corsi-e-workshop'],
                ['Racconti in cortile', 'libri-e-presentazioni'],
                ['Venerdì in acustico', 'musica-dal-vivo'],
                ['Cinema e conversazioni', 'cinema'],
                ['Domenica tra suoni e parole', 'musica-dal-vivo'],
            ];
            foreach ($titles as $index => [$title, $categorySlug]) {
                $category = Category::where('slug', $categorySlug)->first() ?? Category::firstOrFail();
                $event = Event::firstOrCreate(['slug' => 'portici-'.$key.'-'.Str::slug($title)], [
                    'city_id' => $city->id, 'venue_id' => $venue->id, 'organizer_id' => $organizer->id,
                    'category_id' => $category->id, 'created_by' => $owner->id, 'title' => $title,
                    'description' => $title.'. Un appuntamento di musica e cultura nel cuore di Padova, per ritrovarsi e condividere nuove scoperte. Apertura porte alle 20:00, inizio alle 20:30. Ingresso gratuito.',
                    'short_description' => 'Una serata di musica e cultura a Officina dei Portici. Ingresso gratuito, inizio alle 20:30.',
                    'status' => EventStatus::Published, 'published_at' => $today->subDays(30)->utc(),
                    'price_type' => PriceType::Free,
                ]);
                $start = $week->addDays($index + 1)->setTime(20, 30)->utc();
                $occurrence = $event->occurrences()->firstOrCreate(['starts_at' => $start], ['ends_at' => $start->addHours(2)]);
                for ($day = 0; $day < 30; $day++) {
                    $date = $today->subDays($day)->toDateString();
                    DB::table('event_views_daily')->insertOrIgnore([
                        'event_id' => $event->id, 'date' => $date, ...$this->metrics($index + 1, $day),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('occurrence_views_daily')->insertOrIgnore([
                        'occurrence_id' => $occurrence->id, 'date' => $date, ...$this->metrics($index + 1, $day),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                if ($index < 3) {
                    $placement = [SponsorshipPlacement::ListTop, SponsorshipPlacement::HomeCard, SponsorshipPlacement::MapSheet][$index];
                    $campaign = Sponsorship::firstOrCreate(['notes' => 'analytics-demo:'.$key.':'.$index], [
                        'city_id' => $city->id, 'event_id' => $event->id, 'created_by' => $owner->id,
                        'placement' => $placement, 'status' => SponsorshipStatus::Active,
                        'starts_at' => $today->subDays(30)->utc(), 'ends_at' => $week->endOfWeek()->utc(),
                        'advertiser_name' => $venue->name, 'amount_cents' => 0, 'currency' => 'EUR', 'weight' => 1,
                    ]);
                    for ($day = 0; $day < 30; $day++) {
                        $date = $today->subDays($day);
                        $clicks = 3 + ($day + $index) % 8;
                        $impressions = 100 + ($day * 17 + $index * 59) % 300;
                        $inserted = DB::table('sponsorship_daily_stats')->insertOrIgnore([
                            'sponsorship_id' => $campaign->id, 'day' => $date->toDateString(),
                            'impressions' => $impressions, 'clicks' => $clicks, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        if (! $inserted) {
                            continue;
                        }
                        $campaign->increment('impressions', $impressions);
                        $campaign->increment('clicks', $clicks);
                        for ($click = 0; $click < $clicks; $click++) {
                            DB::table('sponsorship_clicks')->insertOrIgnore([
                                'sponsorship_id' => $campaign->id,
                                'request_key' => hash('sha256', 'analytics-demo:'.$key.':'.$index.':'.$date->toDateString().':'.$click),
                                'channel' => $click % 3 === 0 ? 'android' : 'web', 'placement' => $placement->value,
                                'page' => ['events', 'home', 'map'][$index], 'clicked_at' => $date->addMinutes($click)->utc(),
                            ]);
                        }
                    }
                }
            }
            foreach (['venue' => $venue, 'organizer' => $organizer] as $type => $subject) {
                for ($day = 0; $day < 30; $day++) {
                    $metrics = $this->metrics($type === 'venue' ? 2 : 1, $day);
                    foreach (['ticket_clicks', 'calendar_clicks', 'poster_clicks', 'booking_clicks'] as $metric) {
                        $metrics[$metric] = 0;
                    }
                    DB::table('profile_views_daily')->insertOrIgnore([
                        'profile_type' => $type, 'profile_id' => $subject->id, 'date' => $today->subDays($day)->toDateString(),
                        ...$metrics, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            return ['venue' => $venue, 'organizer' => $organizer, 'password' => $newOwner ? $password : null, 'events' => count($titles)];
        });
    }

    /** @return array<string, int> */
    private function metrics(int $factor, int $day): array
    {
        $metrics = [];
        foreach (ContentMetric::cases() as $index => $metric) {
            $metrics[$metric->value] = $metric === ContentMetric::Views
                ? 45 + $factor * 13 + ($day * 7) % 80 : 1 + ($factor * 3 + $day + $index) % 12;
        }

        return $metrics;
    }
}
