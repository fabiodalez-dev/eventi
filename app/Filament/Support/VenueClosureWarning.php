<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Venue;
use App\Support\VenueClosures;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;

/**
 * L'avviso accanto all'orario di una data quando il locale ha una chiusura
 * straordinaria quel giorno. Non blocca: una serata privata o un evento in
 * un altro spazio del locale sono casi veri.
 */
final class VenueClosureWarning
{
    /**
     * @param  Closure(Get): (array{venue: Venue|null, start: CarbonImmutable|null}|null)  $context
     */
    public static function make(Closure $context, string $name = 'venue_closure_warning'): TextEntry
    {
        return TextEntry::make($name)
            ->label(__('manage.closures.warning'))
            ->color('danger')
            ->state(function (Get $get) use ($context): ?string {
                $closure = self::closure($context($get));

                return $closure === null ? null : __('manage.closures.warning_body', [
                    'from' => CarbonImmutable::parse($closure['from'])->format('d/m/Y'),
                    'to' => CarbonImmutable::parse($closure['to'])->format('d/m/Y'),
                    'note' => $closure['note'] === null ? '' : ' ('.$closure['note'].')',
                ]);
            })
            ->hidden(fn (Get $get): bool => self::closure($context($get)) === null)
            ->columnSpanFull();
    }

    /**
     * @param  array{venue: Venue|null, start: CarbonImmutable|null}|null  $context
     * @return array{from: string, to: string, note: string|null}|null
     */
    private static function closure(?array $context): ?array
    {
        $venue = $context['venue'] ?? null;
        $start = $context['start'] ?? null;
        if (! $venue instanceof Venue || $start === null || ! is_array($venue->closures)) {
            return null;
        }
        $timezone = $venue->city->timezone ?? config()->string('app.timezone');

        return VenueClosures::covering($venue->closures, $timezone, day: $start->setTimezone($timezone)->format('Y-m-d'));
    }
}
