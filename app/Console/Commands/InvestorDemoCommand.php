<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvestorDemoCommand extends Command
{
    public const PREFIX = 'investor-demo-v1:';

    protected $signature = 'events:investor-demo {city=padova} {--dry-run} {--allow-production} {--repair-descriptions : Converte le descrizioni HTML del primo import in testo semplice}';

    protected $description = 'Importa 300 eventi dimostrativi in 60 giorni, con fotografie accreditate, senza modificare account o eventi esistenti';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('allow-production')) {
            $this->error('È richiesta l’autorizzazione --allow-production.');

            return self::FAILURE;
        }

        $city = City::query()->active()->where('slug', $this->argument('city'))->firstOrFail();
        $catalog = json_decode(file_get_contents(database_path('seeders/data/investor-events.json')), true, 512, JSON_THROW_ON_ERROR);
        $credits = json_decode(file_get_contents(database_path('seeders/investor-media/credits.json')), true, 512, JSON_THROW_ON_ERROR);
        $categories = Category::query()->get()->keyBy('slug');
        $venues = Venue::query()->approved()->where('city_id', $city->id)->orderBy('id')->get();
        if (count($catalog) !== 300 || count(array_unique(array_column($catalog, 'title'))) !== 300) {
            throw new \RuntimeException('Il catalogo deve contenere 300 titoli unici.');
        }
        foreach ($catalog as $row) {
            if (! isset($categories[$row['category']], $credits[$row['category']]) ||
                ! is_file(database_path('seeders/investor-media/'.$credits[$row['category']]['file'])) ||
                ! $venues->contains(fn ($venue) => $venue->type->value === $row['venue_type'])) {
                throw new \RuntimeException('Categoria, fotografia o locale mancante: '.$row['title']);
            }
        }
        $existing = Event::withTrashed()->where('city_id', $city->id)->where('source_ref', 'like', self::PREFIX.'%')->count();
        $this->info("Catalogo verificato: 300 eventi, 60 giorni, {$existing} già importati.");
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        return Cache::lock('investor-demo:'.$city->id, 3600)->block(5, function () use ($catalog, $credits, $categories, $venues, $city): int {
            // The original anchor is retained on resume, including a partial import.
            $first = Event::withTrashed()->where('city_id', $city->id)->where('source_ref', self::PREFIX.'0000')->first();
            $anchor = $first?->occurrences()->orderBy('starts_at')->first()?->starts_at;
            $start = $anchor ? CarbonImmutable::parse($anchor)->timezone($city->timezone)->startOfDay() : CarbonImmutable::tomorrow($city->timezone);
            $created = 0;
            // Interleave categories over the whole period, not one category per week.
            usort($catalog, fn ($a, $b) => strcmp(hash('sha256', $a['title']), hash('sha256', $b['title'])));
            foreach ($catalog as $index => $row) {
                $ref = self::PREFIX.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
                $event = Event::withTrashed()->where('city_id', $city->id)->where('source_ref', $ref)->first();
                if ($event?->trashed()) {
                    continue; // An editor's deletion is respected.
                }
                if ($this->option('repair-descriptions') && $event?->is_demo && str_starts_with($event->description ?? '', '<p>') && str_contains($event->description, 'Evento dimostrativo')) {
                    $text = str_replace('</p><p>', "\n\n", $event->description);
                    $text = preg_replace('~<a href="([^"]+)">([^<]*)</a>~', '$2 ($1)', $text);
                    $event->update(['description' => html_entity_decode(strip_tags($text))]);
                }
                $credit = $credits[$row['category']];
                if ($event === null) {
                    $matching = $venues->filter(fn ($venue) => $venue->type->value === $row['venue_type'])->values();
                    $venue = $matching[$index % $matching->count()];
                    $hour = match ($row['category']) {
                        'dj-set-nightlife' => 22, 'musica-dal-vivo', 'teatro-e-danza', 'cinema' => 20,
                        'mercatini', 'sport' => 10, 'bambini-e-famiglie', 'arte-e-mostre' => 16, default => 18,
                    };
                    $date = $start->addDays(intdiv($index, 5))->setTime($hour, ($index % 2) * 30);
                    $paid = in_array($row['category'], ['musica-dal-vivo', 'dj-set-nightlife', 'teatro-e-danza', 'cinema', 'corsi-e-workshop', 'food-e-sagre'], true);
                    $description = $row['description']."\n\nEvento dimostrativo: appuntamento fittizio per presentare inCittà, non confermato dal locale. Non è possibile prenotare.";
                    $description .= "\n\nFotografia illustrativa (non dell’evento): ".html_entity_decode(strip_tags($credit['author'])).' — '.$credit['title'].', '.$credit['license'].".\nFonte: ".$credit['source']."\nLicenza: ".$credit['license_url']."\nRitagli e ridimensionamenti automatici nelle anteprime.";
                    $event = DB::transaction(function () use ($city, $venue, $categories, $row, $ref, $date, $paid, $index, $description) {
                        $event = Event::create([
                            'city_id' => $city->id, 'venue_id' => $venue->id, 'category_id' => $categories[$row['category']]->id,
                            'title' => $row['title'], 'description' => $description, 'short_description' => Str::limit($row['description'], 450),
                            'source' => 'manual', 'source_ref' => $ref, 'status' => 'published', 'published_at' => now(),
                            'verification_status' => 'unverified', 'is_demo' => true,
                            'price_type' => $paid ? 'ticket' : 'free', 'price_min' => $paid ? 5 + ($index % 4) * 5 : 0,
                            'currency' => 'EUR', 'booking_required' => false, 'language' => 'it',
                        ]);
                        $event->occurrences()->create(['venue_id' => $venue->id, 'starts_at' => $date->utc(), 'ends_at' => $date->addMinutes(90)->utc(), 'status' => 'scheduled', 'is_all_day' => false, 'booking_enabled' => false]);

                        return $event;
                    });
                    $created++;
                }
                if (! $event->hasMedia('poster')) {
                    $event->addMedia(database_path('seeders/investor-media/'.$credit['file']))->preservingOriginal()->withCustomProperties(['illustrative' => true, 'credit' => $credit])->toMediaCollection('poster');
                }
            }
            $this->info("Importazione conclusa: {$created} nuovi eventi. Gli altri sono stati preservati.");

            return self::SUCCESS;
        });
    }
}
