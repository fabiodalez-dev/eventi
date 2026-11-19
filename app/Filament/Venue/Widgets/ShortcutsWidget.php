<?php

declare(strict_types=1);

namespace App\Filament\Venue\Widgets;

use App\Filament\Venue\Widgets\Support\ManagementContext;
use Filament\Widgets\Widget;

/**
 * «Vai a»: tutte le pagine del pannello in tre gruppi di pulsanti — gli
 * eventi e ciò che li fa girare (social, sponsorizzazioni, biglietteria), il
 * pubblico e i numeri, il locale o l'organizzazione. Dal riepilogo si arriva
 * a tutto senza aprire il menu laterale, che sul telefono sta chiuso.
 */
class ShortcutsWidget extends Widget
{
    protected static ?int $sort = 5;

    /* Piccoli e in cima alla pagina: si disegnano subito, senza una richiesta in più per riquadro. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.venue.widgets.shortcuts';

    public static function canView(): bool
    {
        return ManagementContext::tenant() !== null;
    }

    /**
     * @return array<string, list<array{key: string, label: string, icon: string, url: string, primary: bool}>>
     */
    public function groups(): array
    {
        return ManagementContext::shortcuts();
    }
}
