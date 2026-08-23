<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Venue;
use Illuminate\Database\Seeder;

/**
 * Associa le locandine di riempimento agli eventi e le copertine ai locali.
 *
 * Le immagini stanno in database/seeders/media, sei per ciascuna categoria,
 * in formato 3:4 come prescrive §11.4 del piano. La scelta è deterministica
 * (id dell'evento modulo sei) e non casuale: rieseguire il seeder produce
 * sempre lo stesso abbinamento, così una schermata catturata ieri corrisponde
 * ancora al sito di oggi.
 *
 * Provenienza e licenze in docs/CREDITS.md. Sono immagini di riempimento:
 * in produzione le locandine le caricano i locali.
 */
class MediaSeeder extends Seeder
{
    /**
     * Quante varianti esistono per ogni categoria.
     */
    private const VARIANTS = 6;

    /**
     * Slug di ripiego quando una categoria non ha immagini proprie.
     */
    private const FALLBACK = 'altro';

    public function run(): void
    {
        $directory = database_path('seeders/media');

        if (! is_dir($directory)) {
            $this->command->warn("Cartella immagini assente: {$directory}. Nessuna locandina associata.");

            return;
        }

        $this->seedEventPosters($directory);
        $this->seedVenueCovers($directory);
    }

    private function seedEventPosters(string $directory): void
    {
        $events = Event::with('category')->get();
        $attached = 0;

        foreach ($events as $event) {
            if ($event->getFirstMedia('poster') !== null) {
                continue;
            }

            $path = $this->imageFor($directory, $event->category?->slug, $event->id);

            if ($path === null) {
                continue;
            }

            $event
                ->addMedia($path)
                ->preservingOriginal()
                ->usingFileName(sprintf('locandina-%d.jpg', $event->id))
                ->toMediaCollection('poster');

            $attached++;
        }

        $this->command->info("Locandine associate a {$attached} eventi.");
    }

    private function seedVenueCovers(string $directory): void
    {
        $venues = Venue::all();
        $attached = 0;

        foreach ($venues as $venue) {
            if ($venue->getFirstMedia('cover') !== null) {
                continue;
            }

            $path = $this->imageFor($directory, self::FALLBACK, $venue->id);

            if ($path === null) {
                continue;
            }

            $venue
                ->addMedia($path)
                ->preservingOriginal()
                ->usingFileName(sprintf('copertina-%d.jpg', $venue->id))
                ->toMediaCollection('cover');

            $attached++;
        }

        $this->command->info("Copertine associate a {$attached} locali.");
    }

    /**
     * Percorso dell'immagine per una categoria, scelto in modo deterministico.
     */
    private function imageFor(string $directory, ?string $categorySlug, int $seed): ?string
    {
        $variant = ($seed % self::VARIANTS) + 1;

        foreach ([$categorySlug, self::FALLBACK] as $slug) {
            if ($slug === null) {
                continue;
            }

            $path = sprintf('%s/%s-%d.jpg', $directory, $slug, $variant);

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
