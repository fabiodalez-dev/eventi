<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventFeature;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Services\Ticketing\TicketingService;
use Carbon\CarbonImmutable;
use Database\Seeders\EventFeatureSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class PresentationDemoCommand extends Command
{
    public const PREFIX = 'investor-showcase-v2:';

    protected $signature = 'events:presentation-demo {city=padova} {--new=168} {--dry-run} {--allow-production} {--skip-images} {--reservations : Prepara una lista d’attesa con gli account demo esistenti}';

    protected $description = 'Arricchisce il catalogo MVP e aggiunge date dimostrative senza alterare prenotazioni o account';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('allow-production')) {
            $this->error('Usare --allow-production dopo il backup.');

            return self::FAILURE;
        }
        $count = filter_var($this->option('new'), FILTER_VALIDATE_INT);
        if ($count === false || $count < 0 || $count > 300) {
            $this->error('--new deve essere compreso tra 0 e 300.');

            return self::FAILURE;
        }
        $city = City::query()->active()->where('slug', $this->argument('city'))->firstOrFail();
        $catalog = json_decode((string) file_get_contents(database_path('seeders/data/investor-events.json')), true, 512, JSON_THROW_ON_ERROR);
        usort($catalog, static fn ($a, $b) => strcmp(hash('sha256', $a['title']), hash('sha256', $b['title'])));
        $categories = Category::query()->get()->keyBy('slug');
        $venues = Venue::query()->approved()->where('city_id', $city->id)->orderBy('id')->get();
        if ($venues->isEmpty()) {
            $this->error('Nessun locale approvato nella città.');

            return self::FAILURE;
        }
        $existing = Event::query()->where('city_id', $city->id)->where('status', 'published')->count();
        $this->info("Anteprima: {$existing} eventi esistenti da arricchire, fino a {$count} nuovi eventi, da domani per 14 giorni.");
        foreach (array_slice($catalog, 0, $count) as $row) {
            if (! isset($categories[$row['category']])) {
                $this->error('Categoria mancante: '.$row['category']);

                return self::FAILURE;
            }
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        return Cache::lock('presentation-demo:'.$city->id, 7200)->block(5, function () use ($city, $count, $catalog, $categories, $venues): int {
            // Do not enqueue hundreds of social image jobs during a catalog import.
            $previousOg = config('media.og.enabled');
            config(['media.og.enabled' => false]);
            try {
                (new EventFeatureSeeder)->run();
                $start = CarbonImmutable::tomorrow($city->timezone);
                $created = 0;
                foreach (array_slice($catalog, 0, $count) as $i => $row) {
                    $ref = self::PREFIX.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
                    if (Event::withTrashed()->where('city_id', $city->id)->where('source_ref', $ref)->exists()) {
                        continue;
                    }
                    $matching = $venues->filter(fn ($venue) => $venue->type->value === $row['venue_type'])->values();
                    $venue = $matching->isNotEmpty() ? $matching[$i % $matching->count()] : $venues[$i % $venues->count()];
                    $hour = match ($row['category']) {
                        'dj-set-nightlife' => 22, 'musica-dal-vivo', 'cinema', 'teatro-e-danza' => 20,
                        'sport', 'mercatini' => 10, 'bambini-e-famiglie' => 16, default => 18,
                    };
                    $date = $start->addDays($i % 14)->setTime($hour, ($i % 4) * 15);
                    DB::transaction(function () use ($city, $row, $venue, $categories, $ref, $date): void {
                        $event = Event::create([
                            'city_id' => $city->id, 'venue_id' => $venue->id, 'category_id' => $categories[$row['category']]->id,
                            'title' => $row['title'].': appuntamenti d’autunno', 'description' => $row['description'],
                            'short_description' => Str::limit($row['description'], 180),
                            'source' => 'manual', 'source_ref' => $ref, 'status' => 'published', 'published_at' => now(),
                            'is_demo' => true, 'price_type' => 'free', 'language' => 'it',
                        ]);
                        $event->occurrences()->create(['venue_id' => $venue->id, 'starts_at' => $date->utc(), 'ends_at' => $date->addHours(2)->utc(), 'status' => 'scheduled', 'is_all_day' => false]);
                    });
                    $created++;
                }
                $features = EventFeature::query()->pluck('id', 'slug')->all();
                $updated = 0;
                foreach (Event::query()->with(['category', 'venue', 'media'])->where('city_id', $city->id)->where('status', 'published')->orderBy('id')->lazyById(50) as $event) {
                    $this->enrich($event, $features);
                    $updated++;
                    if ($updated % 50 === 0) {
                        $this->info("Aggiornati {$updated} eventi.");
                    }
                }
                if ($this->option('reservations')) {
                    $this->reservations($city);
                }
                $this->info("Completato: {$created} nuovi eventi; {$updated} schede aggiornate. Account e prenotazioni preservati.");

                return self::SUCCESS;
            } finally {
                config(['media.og.enabled' => $previousOg]);
            }
        });
    }

    /** @param array<string, int> $features */
    private function enrich(Event $event, array $features): void
    {
        $slug = $event->category->slug;
        $family = $slug === 'bambini-e-famiglie';
        $night = $slug === 'dj-set-nightlife';
        $outdoor = in_array($slug, ['sport', 'mercatini', 'food-e-sagre'], true);
        $bookable = ! in_array($slug, ['mercatini', 'politica-e-attivismo', 'comunita-e-assemblee'], true);
        $paid = in_array($slug, ['musica-dal-vivo', 'dj-set-nightlife', 'teatro-e-danza', 'cinema', 'corsi-e-workshop', 'food-e-sagre'], true) && $event->id % 4 !== 0;
        $hasBookings = Booking::query()->whereIn('occurrence_id', $event->occurrences()->select('id'))->exists();
        if ($hasBookings) {
            $paid = $event->price_type->value === 'ticket';
            $bookable = (bool) $event->booking_required;
        }
        $membership = $night || ($event->id % 9 === 0 && $slug === 'musica-dal-vivo');
        $slugs = ['ingresso-senza-gradini', 'bagno-accessibile', 'parcheggio-biciclette', 'acqua-potabile-disponibile'];
        $slugs = [...$slugs, ...match ($slug) {
            'bambini-e-famiglie' => ['adatto-alle-famiglie', 'minori-accompagnati', 'deposito-passeggini', 'fasciatoio', 'area-tranquilla'],
            'dj-set-nightlife' => ['riservato-ai-maggiorenni', 'guardaroba-disponibile', 'suoni-ad-alto-volume'],
            'food-e-sagre' => ['opzioni-vegetariane', 'opzioni-vegane', 'pagamenti-elettronici', 'punti-ristoro'],
            'cinema' => ['sottotitoli-disponibili', 'posti-a-sedere'],
            'sport', 'mercatini' => ['allaperto', 'raggiungibile-con-mezzi-pubblici'],
            default => ['posti-a-sedere', 'raggiungibile-con-mezzi-pubblici'],
        }];
        if ($bookable) {
            $slugs[] = 'prenotazione-obbligatoria';
        }
        $details = array_replace($event->content_details ?? [], [
            'age_groups' => $family ? ['3-5', '6-10'] : ($night ? ['18-plus'] : ['11-17', '18-plus']),
            'stroller' => $family ? 'yes' : ($night ? 'no' : 'yes'), 'changing_table' => $family ? 'yes' : 'no', 'kids_area' => $family ? 'yes' : 'no',
            'membership' => $membership ? 'required' : 'not_required',
            'membership_notes' => $membership ? 'Tessera annuale 10 €, richiedibile all’accoglienza con un documento.' : 'Ingresso aperto anche ai non soci.',
            'accessibility' => 'yes', 'accessibility_notes' => 'Percorso senza gradini e bagno accessibile. Sono disponibili posti riservati e assistenza all’ingresso.',
            'feature_ids' => array_values(array_intersect_key($features, array_flip($slugs))),
            'practical_custom' => [
                ['label' => $family ? 'Materiali inclusi' : ($outdoor ? 'Cosa portare' : 'Accoglienza'), 'icon' => $family ? 'paint-brush' : 'information-circle', 'text' => $family ? 'Colori e materiali sono disponibili. Un adulto accompagna ogni bambino.' : ($outdoor ? 'Porta una borraccia, scarpe comode e una giacca leggera.' : 'Arriva 15 minuti prima: il personale ti aiuta a trovare il tuo posto.')],
                ['label' => $outdoor ? 'In caso di pioggia' : 'Come arrivare', 'icon' => $outdoor ? 'cloud' : 'map-pin', 'text' => $outdoor ? 'Attività trasferite nello spazio coperto del locale; eventuali variazioni compaiono nella scheda.' : 'Usa la mappa per raggiungere l’ingresso principale. Sono disponibili rastrelliere per le biciclette.'],
            ],
        ]);
        $description = preg_split('/\n\n(?:Evento dimostrativo:|Fotografia illustrativa|Appuntamento dimostrativo)/u', (string) $event->description)[0];
        $description = trim(html_entity_decode(strip_tags($description)));
        $attributes = [
            'description' => $description, 'is_demo' => true, 'content_details' => $details,
            'is_outdoor' => $outdoor, 'language' => 'it', 'booking_required' => $bookable,
            'price_type' => $paid ? 'ticket' : 'free', 'price_min' => $paid ? 12 : 0, 'price_max' => $paid ? 18 : null,
            'price_notes' => $paid ? 'Pagamento all’ingresso. Riduzione per studenti e under 26; accompagnatore gratuito.' : 'Partecipazione gratuita.',
            'ticket_url' => null, 'booking_url' => null,
        ];
        if ($hasBookings) {
            foreach (['booking_required', 'price_type', 'price_min', 'price_max', 'price_notes', 'ticket_url', 'booking_url'] as $field) {
                unset($attributes[$field]);
            }
        }
        $event->update($attributes);
        foreach ($event->occurrences()->where('starts_at', '>', now())->get() as $date) {
            // Existing reservations and their settings remain valid, including waitlists.
            if (! Booking::query()->where('occurrence_id', $date->id)->exists()) {
                $date->update([
                    'booking_enabled' => $bookable, 'booking_capacity' => $event->id % 5 === 0 ? null : ($family ? 24 : 80),
                    'booking_limit' => $family ? 5 : 6, 'booking_waitlist' => true,
                    'booking_opens_at' => now()->subDay(), 'booking_closes_at' => $date->starts_at->copy()->subMinutes(15),
                    'cancellation_closes_at' => $date->starts_at->copy()->subHour(),
                    'booking_instructions' => 'Mostra il QR del biglietto all’ingresso. Puoi annullare la prenotazione dal tuo account; libera il posto se non riesci a venire.',
                    'booking_fields' => ['phone' => 'optional'],
                ]);
            }
            if (in_array($slug, ['musica-dal-vivo', 'dj-set-nightlife', 'libri-e-presentazioni'], true) && ! $date->lineups()->exists()) {
                $date->lineups()->create(['name' => $slug === 'musica-dal-vivo' ? 'Quartetto delle Piazze' : ($night ? 'Collettivo Frequenze' : 'Elena Riva'), 'role' => $night ? 'dj' : ($slug === 'musica-dal-vivo' ? 'live' : 'speaker'), 'starts_at' => $date->starts_at, 'sort_order' => 0]);
            }
            if ($bookable) {
                $date->effectiveVenue()?->update(['ticketing_enabled' => true]);
            }
        }
        $tagNames = $family ? ['famiglie', 'bambini', 'laboratorio'] : ($outdoor ? ['all-aperto', 'gratuito'] : ['cultura', 'musica']);
        $event->tags()->syncWithoutDetaching(Tag::query()->whereIn('slug', $tagNames)->pluck('id')->all());
        if ($bookable && ! $hasBookings && ! $event->ticketTiers()->whereNull('occurrence_id')->exists()) {
            foreach ([[$family ? 'Bambino 3–10 anni' : 'Ingresso intero', $paid ? 18 : 0], [$family ? 'Adulto accompagnatore' : 'Studenti e under 26', $paid ? 12 : 0]] as $i => [$name, $price]) {
                $event->ticketTiers()->create(['name' => $name, 'price' => $price, 'currency' => 'EUR', 'status' => 'available', 'sort_order' => $i, 'note' => $paid ? 'Pagamento all’ingresso' : 'Prenotazione gratuita']);
            }
        }
        if (! $this->option('skip-images')) {
            $credits = json_decode((string) file_get_contents(database_path('seeders/investor-media/credits.json')), true, 512, JSON_THROW_ON_ERROR);
            $photoKey = $slug;
            if (in_array($slug, ['bambini-e-famiglie', 'corsi-e-workshop'], true) && preg_match('/disegn|color|pittur|creativ|acquerell/iu', $event->title)) {
                $photoKey = 'bambini-laboratorio';
            } elseif ($slug === 'musica-dal-vivo' && preg_match('/jazz|acustic|quartett|camera/iu', $event->title)) {
                $photoKey = 'musica-acustica';
            }
            $credit = $credits[$photoKey] ?? $credits['altro'];
            $old = $event->getFirstMedia('poster');
            if ($old?->getCustomProperty('credit.file') !== $credit['file'] || $old?->getCustomProperty('credit.presentation_revision') !== ($credit['presentation_revision'] ?? null)) {
                if ($old !== null) {
                    // Keep the former image and all conversions available for rollback.
                    $old->collection_name = 'presentation-previous-posters';
                    $old->save();
                    $event->unsetRelation('media');
                }
                $event->addMedia(database_path('seeders/investor-media/'.$credit['file']))->preservingOriginal()->withCustomProperties(['illustrative' => true, 'credit' => $credit])->toMediaCollection('poster');
            }
        }
    }

    private function reservations(City $city): void
    {
        $reader = User::query()->where('email', 'biglietti@example.test')->first();
        $waiting = User::query()->where('email', 'attesa-ticket@example.test')->first();
        $event = Event::query()->where('city_id', $city->id)->where('source_ref', 'like', self::PREFIX.'%')
            ->whereHas('category', fn ($query) => $query->where('slug', 'corsi-e-workshop'))->first();
        $date = $event?->occurrences()->where('starts_at', '>', now())->first();
        if (! $reader || ! $waiting || ! $date) {
            $this->warn('Lista d’attesa non creata: occorrono gli account demo biglietti/attesa e un nuovo laboratorio futuro.');

            return;
        }
        if (! Booking::query()->where('occurrence_id', $date->id)->exists()) {
            $date->update(['booking_enabled' => true, 'booking_capacity' => 2, 'booking_limit' => 6, 'booking_waitlist' => true]);
        }
        $notifications = Notification::getFacadeRoot();
        Notification::fake();
        try {
            $service = app(TicketingService::class);
            foreach ([[$reader, [['first_name' => 'Giulia', 'last_name' => 'Rossi'], ['first_name' => 'Andrea', 'last_name' => 'Bianchi']]], [$waiting, [['first_name' => 'Luca', 'last_name' => 'Moretti']]]] as [$user, $names]) {
                if (! $service->activeBooking($user, $date)) {
                    $service->reserve($user, $date, $names, (string) Str::uuid(), true, ['first_name' => $names[0]['first_name'], 'last_name' => $names[0]['last_name']]);
                }
            }
            $this->info('Lista d’attesa pronta sulla data #'.$date->id.'. Nessuna notifica inviata.');
        } finally {
            Notification::swap($notifications);
        }
    }
}
