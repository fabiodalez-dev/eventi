<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialFormat;
use App\Http\Resources\V1\PriceResource;
use App\Models\EventOccurrence;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;
use RuntimeException;

/** Fixed editorial zones, measured with the same font used to draw. Never ellipsize event data. */
final class SocialGraphic
{
    public static function version(): string
    {
        return substr(hash_file('sha256', __FILE__), 0, 16);
    }

    /** @param array<string, mixed> $options */
    public function render(EventOccurrence $occurrence, SocialFormat $format, array $options = []): string
    {
        $event = $occurrence->event;
        $height = $format->height();
        $manager = ImageManager::gd();
        $image = $manager->create(1080, $height)->fill('#0B0B0B');
        $dark = '#0B0B0B';
        $foreground = '#F5F5F0';
        $font = resource_path('fonts/og-title.ttf');
        $body = resource_path('fonts/og-text.ttf');
        $posterHeight = $height;
        $poster = $event->getFirstMedia('poster')?->getPath();
        if (! $poster || ! is_file($poster)) {
            $candidate = (string) $event->poster;
            $poster = $candidate !== '' && ! str_contains($candidate, '..') && ! Str::startsWith($candidate, ['/', 'http:', 'https:']) && Storage::disk('public')->exists($candidate)
                ? Storage::disk('public')->path($candidate) : null;
        }
        if ($poster && is_file($poster)) {
            // No remote fetches: only media already accepted by the application's upload pipeline.
            $photo = $manager->read($poster);
            $backdrop = $manager->read($poster)->cover(270, (int) ceil($posterHeight / 4))->blur(12)->resize(1080, $posterHeight);
            $photo = ($options['image_fit'] ?? 'contain') === 'contain'
                ? $photo->scale(width: 1080, height: $posterHeight)
                : $photo->cover(1080, $posterHeight);
            if ($options['monochrome'] ?? true) {
                $photo->greyscale();
                $backdrop->greyscale();
            }
            $panel = $backdrop;
            $panel->place($photo, 'center');
            $image->place($panel, 'top-left');
        } else {
            $panel = $manager->create(1080, $posterHeight)->fill('#CCFF00');
            $panel->text('inCittà', 54, min(320, (int) ($posterHeight * 0.23)), fn (FontFactory $f) => $f->filename($font)->size(110)->color($dark));
            $image->place($panel, 'top-left');
        }

        // Keep the entire poster visible. The lower gradient gives fixed text zones
        // contrast without guessing which part of a vertical poster may be cropped.
        $gradientHeight = min($posterHeight, 850);
        $gradient = imagecreatetruecolor(1080, $gradientHeight);
        imagealphablending($gradient, false);
        imagesavealpha($gradient, true);
        for ($y = 0; $y < $gradientHeight; $y++) {
            $progress = $y / max(1, $gradientHeight - 1);
            $alpha = (int) round(127 * pow(1 - $progress, 3.5));
            $color = imagecolorallocatealpha($gradient, 11, 11, 11, $alpha);
            imageline($gradient, 0, $y, 1079, $y, $color);
        }
        $image->place($manager->read($gradient), 'top-left', 0, $height - $gradientHeight);
        // The brand sits on the image, without opaque strips.
        $topShade = imagecreatetruecolor(1080, 150);
        imagealphablending($topShade, false);
        imagesavealpha($topShade, true);
        for ($y = 0; $y < 150; $y++) {
            imageline($topShade, 0, $y, 1079, $y, imagecolorallocatealpha($topShade, 11, 11, 11, (int) round(45 + 82 * $y / 149)));
        }
        $image->place($manager->read($topShade));
        $image->text('inCittà', 54, 80, fn (FontFactory $f) => $f->filename($font)->size(54)->color('#CCFF00'));
        $image->text(mb_strtoupper($event->city->name), 1026, 76, fn (FontFactory $f) => $f->filename($body)->size(25)->color($foreground)->align('right'));
        $top = $height - 650;
        $category = mb_strtoupper($event->category->name);
        [$categoryLines, $categorySize] = $this->fit($category, $body, 972, 26, 22, 14);
        $image->text(implode(' ', $categoryLines), 54, $top, fn (FontFactory $f) => $f->filename($body)->size($categorySize)->color('#CCFF00'));
        $title = trim((string) ($options['title'] ?? '')) ?: $event->title;
        [$lines, $size] = $this->fit($title, $font, 972, 220, 64, 30);
        $baseline = $top + 28 + $size;
        foreach ($lines as $line) {
            $image->text($line, 54, $baseline, fn (FontFactory $f) => $f->filename($font)->size($size)->color($foreground));
            $baseline += (int) ceil($size * 1.17);
        }

        $metaTop = $height - 300;
        $when = $occurrence->starts_at->timezone($event->city->timezone);
        $date = $when->translatedFormat('D j F');
        $time = $occurrence->is_all_day ? __('social.all_day') : $when->format('H:i');
        $image->text(mb_strtoupper($date).'  /  '.$time, 54, $metaTop, fn (FontFactory $f) => $f->filename($font)->size(32)->color('#CCFF00'));
        $venue = $occurrence->effectiveVenue();
        $custom = is_array($event->custom_location) ? $event->custom_location : [];
        $where = $venue !== null ? $venue->name : (string) ($custom['name'] ?? $event->city->name);
        [$venueLines, $venueSize] = $this->fit($where, $font, 972, 68, 30, 20);
        foreach ($venueLines as $i => $line) {
            $image->text($line, 54, $metaTop + 48 + $i * 32, fn (FontFactory $f) => $f->filename($font)->size($venueSize)->color($foreground));
        }
        if ($options['show_address'] ?? true) {
            $address = $venue !== null ? trim($venue->address.' '.$venue->municipality) : (string) ($custom['address'] ?? '');
            [$addressLines, $addressSize] = $this->fit($address, $body, 972, 52, 24, 16);
            foreach ($addressLines as $i => $line) {
                $image->text($line, 54, $metaTop + 115 + $i * 25, fn (FontFactory $f) => $f->filename($body)->size($addressSize)->color($foreground));
            }
        }
        if ($options['show_price'] ?? true) {
            $price = PriceResource::toArray($event, $occurrence);
            $label = __('enums.price_type.'.$price['type']);
            if ($price['type'] === 'ticket' && $price['min'] !== null) {
                $label = number_format((float) $price['min'], 2, ',', '.').($price['max'] !== null && $price['max'] != $price['min'] ? ' - '.number_format((float) $price['max'], 2, ',', '.') : '').' '.($price['currency'] ?: 'EUR');
            }
            $image->text($label, 54, $height - 108, fn (FontFactory $f) => $f->filename($font)->size(26)->color($foreground));
        }
        $image->text((string) parse_url(config('app.url'), PHP_URL_HOST), 54, $height - 30, fn (FontFactory $f) => $f->filename($body)->size(24)->color('#CCFF00'));

        return (string) $image->toJpeg(90);
    }

    /** @return array{list<string>, int} */
    public function fit(string $text, string $font, int $width, int $height, int $max, int $min): array
    {
        $text = Str::squish(strip_tags($text));
        for ($size = $max; $size >= $min; $size--) {
            $lines = [];
            $line = '';
            foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;
                if ($this->width($candidate, $font, $size) <= $width) {
                    $line = $candidate;
                } else {
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                    $line = $word;
                }
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            if (count($lines) * ceil($size * 1.17) <= $height && collect($lines)->every(fn ($line) => $this->width($line, $font, $size) <= $width)) {
                return [$lines, $size];
            }
        }
        throw new RuntimeException(__('social.text_overflow'));
    }

    private function width(string $text, string $font, int $size): int
    {
        // Match Intervention GD FontProcessor::nativeFontSize exactly.
        $box = imagettfbbox($size * 0.76, 0, $font, $text);

        return $box === false ? PHP_INT_MAX : abs($box[2] - $box[0]);
    }
}
