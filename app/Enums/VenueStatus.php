<?php

declare(strict_types=1);

namespace App\Enums;

enum VenueStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Approved = 'approved';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    /**
     * Lo stato è un **provvedimento** preso su questo locale?
     *
     * Sospensione e rifiuto lo sono; bozza e attesa no — quelli descrivono un
     * percorso non ancora concluso, non una decisione contro qualcuno.
     *
     * La distinzione conta perché da essa dipende la sparizione dei contenuti
     * dal sito pubblico: si applica a chi è stato fermato, non a chi non è
     * ancora arrivato. Tenerla qui, e non ripetuta in ogni query, è ciò che
     * impedisce alle due liste di divergere in silenzio — divergenza che si
     * manifesterebbe come «l'ho sospeso ma i suoi eventi sono ancora online»,
     * cioè come nulla di visibile finché qualcuno non se ne accorge.
     */
    public function isProvvedimento(): bool
    {
        return match ($this) {
            self::Suspended, self::Rejected => true,
            self::Draft, self::Pending, self::Approved => false,
        };
    }

    /**
     * Gli stati che **non** nascondono i contenuti del locale.
     *
     * @return non-empty-list<string>
     */
    public static function valoriSenzaProvvedimento(): array
    {
        /** @var non-empty-list<string> $valori */
        $valori = array_values(array_map(
            static fn (self $stato): string => $stato->value,
            array_filter(self::cases(), static fn (self $stato): bool => ! $stato->isProvvedimento()),
        ));

        return $valori;
    }

    public function label(): string
    {
        return __('enums.venue_status.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
