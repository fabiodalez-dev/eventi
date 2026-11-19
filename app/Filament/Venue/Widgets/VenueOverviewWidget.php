<?php

declare(strict_types=1);

namespace App\Filament\Venue\Widgets;

use App\Enums\EventStatus;
use App\Filament\Venue\Widgets\Support\ManagementContext;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Number;

/**
 * «Il tuo cartellone»: le date di oggi, quelle in arrivo e le bozze da
 * completare, ognuna un collegamento all'elenco eventi già sulla scheda
 * giusta, e il pulsante «Nuovo evento».
 *
 * «Oggi» e «prossimi» li conta il motore temporale — "oggi" ha una sola
 * definizione in tutto il prodotto (§8.1). Le visite non sono più qui: il
 * riepilogo del periodo, subito sotto, le mostra già (era un doppione), e il
 * riquadro «Ultime visite» dice quali schede sono state aperte.
 *
 * Vale per i due pannelli: il locale (`/gestione`) e l'organizzazione
 * (`/organizza`).
 */
class VenueOverviewWidget extends Widget
{
    protected static ?int $sort = 1;

    /* Piccoli e in cima alla pagina: si disegnano subito, senza una richiesta in più per riquadro. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.venue.widgets.overview';

    public static function canView(): bool
    {
        return ManagementContext::tenant() !== null;
    }

    /**
     * @return list<array{key: string, label: string, value: string, hint: string, url: ?string}>
     */
    public function numbers(): array
    {
        // Una query nuova per numero: il motore temporale si modifica sul posto.
        $count = fn (string $window): int => ManagementContext::occurrences()?->{$window}()->count() ?? 0;
        $resource = ManagementContext::eventResource();
        $list = fn (string $tab): ?string => $resource::canViewAny() ? $resource::getUrl('index', ['tab' => $tab]) : null;
        $format = fn (int $value): string => Number::format($value, locale: 'it');

        return [
            ['key' => 'today', 'label' => __('manage.dashboard.today'), 'value' => $format($count('today')), 'hint' => __('manage.dashboard.today_hint'), 'url' => $list('upcoming')],
            ['key' => 'upcoming', 'label' => __('manage.dashboard.upcoming'), 'value' => $format($count('upcoming')), 'hint' => __('manage.dashboard.upcoming_hint'), 'url' => $list('upcoming')],
            ['key' => 'drafts', 'label' => __('manage.dashboard.drafts'), 'value' => $format(ManagementContext::events()->where('status', EventStatus::Draft)->count()), 'hint' => __('manage.dashboard.drafts_hint'), 'url' => $list('drafts')],
        ];
    }

    public function createUrl(): ?string
    {
        $resource = ManagementContext::eventResource();

        return $resource::canCreate() ? $resource::getUrl('create') : null;
    }

    public function panelId(): string
    {
        return (string) Filament::getCurrentPanel()?->getId();
    }
}
