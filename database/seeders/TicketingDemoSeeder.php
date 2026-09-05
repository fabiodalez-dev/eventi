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
        $venue = Venue::query()->whereIn('slug', ['spazio-demo-biglietteria', 'spazio-delle-erbe'])->first()
            ?? Venue::query()->where('slug', 'circolo-arci-la-fornace')->first();
        if (! $venue) {
            $venue = Venue::query()->create([
                'city_id' => $city->id, 'name' => 'Spazio delle Erbe', 'slug' => 'spazio-delle-erbe',
                'description' => 'Uno spazio culturale nel centro di Padova dedicato a musica acustica, incontri e laboratori in piccoli gruppi. Contenuto dimostrativo per la presentazione della piattaforma.',
                'address' => 'Piazza delle Erbe, Padova', 'lat' => 45.4074, 'lng' => 11.8753,
                'ticketing_enabled' => true, 'capacity' => null,
                'type' => VenueType::Altro, 'status' => VenueStatus::Approved, 'approved_at' => now(),
                'municipality' => 'Padova', 'province_code' => 'PD',
            ]);
        }
        if ($venue->slug === 'spazio-demo-biglietteria') {
            $venue->update(['name' => 'Spazio delle Erbe', 'slug' => 'spazio-delle-erbe', 'description' => 'Uno spazio culturale nel centro di Padova dedicato a musica acustica, incontri e laboratori in piccoli gruppi. Contenuto dimostrativo per la presentazione della piattaforma.']);
        }
        $venue->update(['ticketing_enabled' => true]);
        $owner = $this->user('gestore-ticket@example.test', 'Marta Berti');
        $owner->assignRole('venue_owner');
        $owner->venues()->syncWithoutDetaching([$venue->id => ['role' => 'owner']]);
        $reader = $this->user('biglietti@example.test', 'Giulia Rossi');
        $waiting = $this->user('attesa-ticket@example.test', 'Luca Moretti');
        $notificationFacade = Notification::getFacadeRoot();
        Notification::fake();
        try {
            foreach ([
                ['Jazz in acustico: chitarra e contrabbasso', 'Demo · Concerto su prenotazione', 'musica-dal-vivo', 12, 1, 'Una serata raccolta tra standard jazz e improvvisazioni, con un duo di chitarra e contrabbasso. Dodici posti prenotabili per ascoltare da vicino, a pochi passi dalle piazze di Padova. Apertura porte quindici minuti prima del concerto.'],
                ['Padova raccontata: storie tra piazze e portici', 'Demo · Incontro a prenotazioni illimitate', 'libri-e-presentazioni', null, 2, 'Un incontro aperto a chi ama scoprire la città attraverso racconti, fotografie e ricordi. Dalle botteghe sotto il Salone ai portici del centro, una conversazione su luoghi quotidiani e piccole storie padovane. Prenotazione gratuita, senza un contingente di biglietti prefissato.'],
                ['Taccuini urbani: atelier di illustrazione', 'Demo · Laboratorio con lista d’attesa', 'corsi-e-workshop', 2, 3, 'Un atelier per due partecipanti dedicato al disegno dal vero e al taccuino di viaggio. Si parte da dettagli architettonici di Padova per costruire una piccola pagina illustrata. Materiali di base inclusi, nessuna esperienza richiesta. A posti esauriti è disponibile la lista d’attesa.'],
            ] as [$title, $oldTitle, $categorySlug, $capacity, $days, $description]) {
                $category = Category::query()->where('slug', $categorySlug)->first() ?? Category::query()->firstOrFail();
                $legacy = Event::query()->where('venue_id', $venue->id)->where('title', $oldTitle)->first();
                $legacy?->update(['title' => $title, 'category_id' => $category->id, 'description' => $description."\n\nAppuntamento dimostrativo della piattaforma, non un annuncio di programmazione reale.", 'short_description' => Str::limit($description, 180)]);
                $event = Event::query()->firstOrCreate(['venue_id' => $venue->id, 'title' => $title], [
                    'city_id' => $city->id, 'category_id' => $category->id, 'created_by' => $owner->id,
                    'description' => $description."\n\nAppuntamento dimostrativo della piattaforma, non un annuncio di programmazione reale.",
                    'short_description' => Str::limit($description, 180),
                    'status' => EventStatus::Published, 'published_at' => now(), 'price_type' => PriceType::Free,
                    'booking_required' => true,
                ]);
                $date = $event->occurrences()->first();
                if (! $date) {
                    $start = now($city->timezone)->addDays($days)->setTime(20, 0)->utc();
                    $date = EventOccurrence::query()->create([
                        'event_id' => $event->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2),
                        'booking_enabled' => true, 'booking_capacity' => $capacity, 'booking_waitlist' => true,
                        'booking_limit' => 6, 'booking_instructions' => 'Presenta il biglietto con QR all’ingresso e arriva 15 minuti prima. Se non puoi partecipare, annulla la prenotazione per liberare il posto.',
                        'booking_fields' => $capacity === 12 ? ['address' => 'required', 'phone' => 'optional'] : ['phone' => 'optional'],
                    ]);
                }
                if (! Booking::query()->where('occurrence_id', $date->id)->exists() && $date->starts_at->isFuture()) {
                    app(TicketingService::class)->reserve($reader, $date, [['first_name' => 'Giulia', 'last_name' => 'Rossi'], ['first_name' => 'Andrea', 'last_name' => 'Bianchi']], (string) Str::uuid(), false, ['first_name' => 'Giulia', 'last_name' => 'Rossi', 'address' => 'Via Savonarola 18, Padova']);
                    if ($capacity === 2) {
                        app(TicketingService::class)->reserve($waiting, $date, [['first_name' => 'Luca', 'last_name' => 'Moretti']], (string) Str::uuid(), true, ['first_name' => 'Luca', 'last_name' => 'Moretti']);
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
        if (in_array($user->name, ['Gestore Demo', 'Giulia Demo', 'Luca Demo'], true)) {
            $user->update(['name' => $name]);
        }
        $user->assignRole('user');

        return $user;
    }
}
