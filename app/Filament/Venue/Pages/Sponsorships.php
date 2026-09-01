<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Filament\Venue\Support\CurrentVenue;
use App\Models\Sponsorship;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Le campagne sponsorizzate sui propri eventi, in sola lettura.
 *
 * **Perché esiste, visto che il referente non può farci niente.** Perché
 * altrimenti scoprirebbe dal sito che un proprio evento sta in cima con la
 * scritta «sponsorizzato» e un nome che magari non conosce — un'etichetta
 * discografica che promuove il concerto ospitato nel suo circolo. Sapere cosa
 * si vende sulle proprie serate non è un permesso: è il minimo per non far
 * fare a qualcuno la figura di chi non sa cosa succede in casa propria.
 *
 * **Perché in sola lettura.** Potersi sponsorizzare da sé significherebbe che
 * il posto in cima si prende invece di comprarlo, ed è per questo che
 * `SponsorshipPolicy` non apre a nessun ruolo di locale.
 */
class Sponsorships extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'sponsorizzazioni';

    protected string $view = 'filament.venue.pages.sponsorships';

    public static function getNavigationLabel(): string
    {
        return __('sponsorships.venue.title');
    }

    public function getTitle(): string
    {
        return __('sponsorships.venue.title');
    }

    public function getSubheading(): ?string
    {
        return __('sponsorships.venue.lead');
    }

    /**
     * Le campagne vive sugli eventi di questo locale.
     *
     * **Solo quelle vive**: uno storico di campagne finite qui non serve a
     * niente — non è chi le paga a leggerlo — e diventerebbe un elenco che
     * cresce senza che nessuno lo guardi.
     *
     * @return Collection<int, Sponsorship>
     */
    public function sponsorships(): Collection
    {
        $venue = CurrentVenue::get();

        return Sponsorship::query()
            ->visible()
            ->whereHas('event', fn ($event) => $event->where('venue_id', $venue->getKey()))
            ->with('event')
            ->orderBy('ends_at')
            ->get();
    }
}
