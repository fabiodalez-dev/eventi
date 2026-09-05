<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Enums\VenueStatus;
use App\Enums\VenueType;
use App\Models\Booking;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Services\Ticketing\TicketingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class TicketingDemoSeeder extends Seeder
{
    public function run(bool $publicDemo = false): void
    {
        // Production requires the explicit ticketing:demo --force command.
        if (! $publicDemo && ! app()->environment(['local', 'testing'])) {
            return;
        }
        $city = City::query()->firstOrFail();
        $category = Category::query()->firstOrFail();
        $venue = Venue::query()->where('slug', 'spazio-demo-biglietteria')->first();
        if (! $venue) {
            $venue = Venue::query()->create([
                'city_id' => $city->id, 'name' => 'Spazio Demo Biglietteria', 'slug' => 'spazio-demo-biglietteria',
                'description' => 'Locale dimostrativo per provare prenotazioni e biglietti. Gli eventi non sono reali.',
                'address' => 'Piazza delle Erbe, Padova', 'lat' => 45.4074, 'lng' => 11.8753,
                'ticketing_enabled' => true, 'capacity' => null,
                'type' => VenueType::Altro, 'status' => VenueStatus::Approved, 'approved_at' => now(),
                'municipality' => 'Padova', 'province_code' => 'PD',
            ]);
        }
        $owner = $this->user('gestore-ticket@example.test', 'Gestore Demo');
        $owner->assignRole('venue_owner');
        $owner->venues()->syncWithoutDetaching([$venue->id => ['role' => 'owner']]);
        $reader = $this->user('biglietti@example.test', 'Giulia Demo');
        $waiting = $this->user('attesa-ticket@example.test', 'Luca Demo');
        $notificationFacade = Notification::getFacadeRoot();
        Notification::fake();
        try {
            foreach ([['Demo · Concerto su prenotazione', 12, 1], ['Demo · Incontro a prenotazioni illimitate', null, 2], ['Demo · Laboratorio con lista d’attesa', 2, 3]] as [$title, $capacity, $days]) {
                $event = Event::query()->firstOrCreate(['venue_id' => $venue->id, 'title' => $title], [
                    'city_id' => $city->id, 'category_id' => $category->id, 'created_by' => $owner->id,
                    'description' => 'Evento dimostrativo, non reale. Prova la prenotazione nominativa, mostra il QR nel profilo e annulla un biglietto per liberare il posto. Il gestore può consultare la lista e registrare gli ingressi.',
                    'short_description' => 'Esempio dimostrativo del sistema di prenotazioni gratuite.',
                    'status' => EventStatus::Published, 'published_at' => now(), 'price_type' => PriceType::Free,
                    'booking_required' => true,
                ]);
                $date = $event->occurrences()->first();
                if (! $date) {
                    $start = now($city->timezone)->addDays($days)->setTime(20, 0)->utc();
                    $date = EventOccurrence::query()->create([
                        'event_id' => $event->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2),
                        'booking_enabled' => true, 'booking_capacity' => $capacity, 'booking_waitlist' => true,
                        'booking_limit' => 6, 'booking_instructions' => 'Porta il QR e arriva 15 minuti prima. Esempio dimostrativo: nessun evento reale.',
                        'booking_fields' => $capacity === 12 ? ['address' => 'required', 'phone' => 'optional'] : ['phone' => 'optional'],
                    ]);
                }
                if (! Booking::query()->where('occurrence_id', $date->id)->exists() && $date->starts_at->isFuture()) {
                    app(TicketingService::class)->reserve($reader, $date, [['first_name' => 'Giulia', 'last_name' => 'Demo'], ['first_name' => 'Andrea', 'last_name' => 'Demo']], (string) Str::uuid(), false, ['first_name' => 'Giulia', 'last_name' => 'Demo', 'address' => 'Indirizzo dimostrativo, non reale']);
                    if ($capacity === 2) {
                        app(TicketingService::class)->reserve($waiting, $date, [['first_name' => 'Luca', 'last_name' => 'Demo']], (string) Str::uuid(), true, ['first_name' => 'Luca', 'last_name' => 'Demo']);
                    }
                }
            }
        } finally {
            Notification::swap($notificationFacade);
        }
    }

    private function user(string $email, string $name): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make('DemoTicket-2026!'), 'email_verified_at' => now()]);
        $user->assignRole('user');

        return $user;
    }
}
