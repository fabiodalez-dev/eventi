<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use App\Support\DateFormatter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Drivers\ImageDriver;
use Spatie\Image\Drivers\Imagick\ImagickDriver;
use Spatie\Image\Enums\AlignPosition;
use Spatie\Image\Enums\Fit;
use Throwable;

/**
 * L'anteprima social 1200×630 di §12.1: locandina, titolo, data e marchio,
 * composti **con `spatie/image`** e non con un browser senza schermo.
 *
 * La differenza non è di gusto. Un browser headless per disegnare un
 * rettangolo con dentro tre righe di testo significa installare Chromium sul
 * server — trecento megabyte, un processo che va sorvegliato e una superficie
 * d'attacco nuova — per fare ciò che ImageMagick fa da solo in un decimo di
 * secondo. Su uno spazio condiviso senza root non si potrebbe nemmeno
 * installare (D3).
 *
 * La data la chiede a `EventOccurrenceQuery` come chiunque altro (§8.1): è la
 * prima data futura dell'evento, o l'ultima passata se non ne restano.
 *
 * Il marchio: se `media.open_graph.logo` indica un file, quello viene
 * inserito; altrimenti si disegna la stessa firma dell'intestazione del sito
 * — il pallino della tinta e il nome del prodotto. Sono la stessa identità,
 * e nessuna delle due è un file finto messo lì per riempire.
 */
final class OpenGraphImage
{
    /**
     * Quante righe di titolo entrano prima di tagliare. La quarta riga
     * spingerebbe la data fuori dal riquadro.
     */
    private const TITLE_LINES = 3;

    /**
     * Larghezza della colonna della locandina quando la locandina c'è.
     */
    private const POSTER_WIDTH = 460;

    private const PADDING = 64;

    public function __construct(private readonly ImageSanitizer $sanitizer) {}

    /**
     * Compone e salva l'immagine. Risponde con il percorso relativo sul disco
     * dei media, oppure `null` se non è stato possibile comporla.
     */
    public function render(Event $event): ?string
    {
        if (! extension_loaded('imagick')) {
            return null;
        }

        $width = config()->integer('media.open_graph.width');
        $height = config()->integer('media.open_graph.height');
        $temporary = tempnam(sys_get_temp_dir(), 'og').'.jpg';

        try {
            $canvas = (new ImagickDriver)->new($width, $height, config()->string('media.open_graph.background'));

            $poster = $this->posterFile($event);
            $textLeft = self::PADDING;
            $textWidth = $width - self::PADDING * 2;

            if ($poster !== null) {
                $canvas->insert(
                    (new ImagickDriver)->loadFile($poster)->fit(Fit::Crop, self::POSTER_WIDTH, $height),
                    AlignPosition::TopLeft,
                );

                $textLeft = self::POSTER_WIDTH + self::PADDING;
                $textWidth = $width - $textLeft - self::PADDING;
            }

            $this->draw($canvas, $event, $textLeft, $textWidth, $height);

            $canvas
                ->format('jpg')
                ->quality(config()->integer('media.open_graph.quality'))
                ->save($temporary);

            $this->sanitizer->sanitize($temporary);

            $path = self::path($event);
            $stream = fopen($temporary, 'rb');

            if ($stream === false) {
                return null;
            }

            Storage::disk(config()->string('media.open_graph.disk'))->put($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            return $path;
        } catch (Throwable) {
            /*
             * Un'anteprima che non si compone non è un guasto del sito: la
             * pagina resta corretta e `og:image` ricade sulla locandina. Il
             * chiamante decide se e come registrarlo.
             */
            return null;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Dove vive l'anteprima di un evento. È un percorso stabile: l'evento è
     * il soggetto, non la locandina, e un evento senza locandina un'anteprima
     * ce l'ha lo stesso.
     */
    public static function path(Event $event): string
    {
        return sprintf(
            '%s/eventi/%d.jpg',
            trim(config()->string('media.open_graph.directory'), '/'),
            (int) $event->getKey(),
        );
    }

    public static function disk(): string
    {
        return config()->string('media.open_graph.disk');
    }

    /**
     * Butta via l'anteprima: la chiama chi cancella l'evento.
     */
    public static function forget(Event $event): void
    {
        Storage::disk(self::disk())->delete(self::path($event));
    }

    /**
     * Il testo: marchio in alto, titolo al centro, data e luogo in fondo.
     */
    private function draw(ImageDriver $canvas, Event $event, int $left, int $width, int $height): void
    {
        $titleFont = $this->font('font');
        $foreground = config()->string('media.open_graph.foreground');
        $muted = config()->string('media.open_graph.muted');
        $titleSize = config()->integer('media.open_graph.title_size');
        $metaSize = config()->integer('media.open_graph.meta_size');

        $this->wordmark($canvas, $left, $this->font('text_font'), $metaSize);

        $title = $this->truncate($canvas, $event->title, $titleSize, $titleFont, $width);
        $lineHeight = (int) round($titleSize * 1.28);
        $titleTop = (int) round($height / 2 - (substr_count($title, "\n") + 1) * $lineHeight / 2 + $titleSize);

        $canvas->text($title, $titleSize, $foreground, $left, $titleTop, 0, $titleFont);

        $lines = array_values(array_filter([$this->when($event), $this->where($event)]));

        if ($lines === []) {
            return;
        }

        $canvas->text(
            implode("\n", $lines),
            $metaSize,
            $muted,
            $left,
            $height - self::PADDING - (count($lines) - 1) * (int) round($metaSize * 1.4),
            0,
            $this->font('text_font'),
        );
    }

    /**
     * La firma del prodotto: il pallino della tinta e il nome, come
     * nell'intestazione del sito. Se esiste un file di logo, prende il suo
     * posto.
     */
    private function wordmark(ImageDriver $canvas, int $left, string $font, int $size): void
    {
        $logo = $this->file(config()->string('media.open_graph.logo'));

        if ($logo !== null) {
            $canvas->insert(
                (new ImagickDriver)->loadFile($logo)->fit(Fit::Contain, 200, 56),
                AlignPosition::TopLeft,
                $left,
                self::PADDING,
            );

            return;
        }

        $accent = config()->string('media.open_graph.accent');
        $baseline = self::PADDING + $size;

        $canvas->text('•', $size + 8, $accent, $left, $baseline, 0, $font);
        $canvas->text(config()->string('app.name'), $size, $accent, $left + 26, $baseline, 0, $font);
    }

    /**
     * La riga della data. La data viene dal motore temporale (§8.1): è la
     * prima futura, o l'ultima passata quando non ne restano.
     */
    private function when(Event $event): ?string
    {
        $occurrence = $this->occurrence($event);

        if ($occurrence === null) {
            return null;
        }

        $formatter = DateFormatter::forTimezone($event->city->timezone);

        return $occurrence->is_all_day
            ? $formatter->weekdayDate($occurrence->business_date)
            : $formatter->weekdayDate($occurrence->business_date).' '.__('common.separator').' '.$formatter->time($occurrence->starts_at);
    }

    private function where(Event $event): string
    {
        $venue = $event->venue;

        if ($venue !== null) {
            return Str::squish($venue->name.' '.__('common.separator').' '.$venue->municipality);
        }

        $custom = is_array($event->custom_location) ? $event->custom_location : [];
        $name = is_string($custom['name'] ?? null) ? $custom['name'] : null;

        return $name ?? $event->city->name;
    }

    private function occurrence(Event $event): ?EventOccurrence
    {
        $city = $event->city;

        $next = EventOccurrenceQuery::for($city)->forEvent($event)->upcoming()->get()->first();

        if ($next instanceof EventOccurrence) {
            return $next;
        }

        $last = EventOccurrenceQuery::for($city)->forEvent($event)->past()->orderByNewestFirst()->get()->first();

        return $last instanceof EventOccurrence ? $last : null;
    }

    /**
     * Il titolo mandato a capo dentro la colonna e tagliato alle righe che ci
     * stanno: `wrapText` sa dove spezzare, non sa quando smettere.
     */
    private function truncate(ImageDriver $canvas, string $title, int $size, string $font, int $width): string
    {
        $wrapped = $canvas->wrapText(Str::squish($title), $size, $font, 0, $width);
        $lines = explode("\n", $wrapped);

        if (count($lines) <= self::TITLE_LINES) {
            return $wrapped;
        }

        $kept = array_slice($lines, 0, self::TITLE_LINES);
        $kept[self::TITLE_LINES - 1] = rtrim($kept[self::TITLE_LINES - 1]).'…';

        return implode("\n", $kept);
    }

    /**
     * Il file della locandina da comporre: quello della libreria media se c'è,
     * altrimenti il file indicato dalla colonna `events.poster` quando è sul
     * nostro disco. Un indirizzo esterno non si scarica: comporre
     * un'anteprima non è un buon motivo per andare a bussare a un altro
     * dominio da dentro una coda.
     */
    private function posterFile(Event $event): ?string
    {
        $media = $event->getFirstMedia('poster');

        if ($media !== null) {
            $path = $media->getPath();

            return is_file($path) ? $path : null;
        }

        if (blank($event->poster)) {
            return null;
        }

        $poster = (string) $event->poster;

        if (Str::startsWith($poster, ['http://', 'https://', '/'])) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($poster) ? $disk->path($poster) : null;
    }

    private function font(string $key): string
    {
        return $this->file(config()->string('media.open_graph.'.$key))
            ?? throw new \RuntimeException('Font per l\'anteprima social non trovato: '.config()->string('media.open_graph.'.$key));
    }

    private function file(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $absolute = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($absolute) ? $absolute : null;
    }
}
