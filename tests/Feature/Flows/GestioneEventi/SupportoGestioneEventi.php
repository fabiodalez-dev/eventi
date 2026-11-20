<?php

declare(strict_types=1);

/*
 * Aiuti condivisi dai test di flusso sulla gestione degli eventi.
 *
 * Il file non finisce in `Test.php` di proposito: Pest non lo esegue, lo
 * caricano con `require_once` i file della cartella. I nomi hanno il prefisso
 * `ge` perché le funzioni dichiarate nei file di Pest sono globali per tutta
 * la suite, e `entraInGestione()` o simili potrebbero esistere già altrove.
 */

use App\Enums\PriceType;
use App\Filament\Admin\Resources\Events\EventResource as AdminEventResource;
use App\Filament\Admin\Resources\Events\Pages\EditEvent as AdminEditEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Filament\Venue\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Entra nel pannello del locale come farebbe il browser — una GET vera, che
 * accende lo scope del tenant come in produzione — e poi prepara Livewire.
 */
function geEntra(User $user, Venue $venue, string $path = ''): void
{
    test()->actingAs($user)->get('/gestione/'.$venue->slug.$path)->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);
}

/**
 * Torna sul sito pubblico da anonimo. Nel processo di test lo scope di
 * tenancy di Filament resta acceso dopo una richiesta a `/gestione`, mentre
 * in produzione ogni richiesta parte pulita: va spento a mano.
 */
function geSitoPubblico(): void
{
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    auth()->logout();
    app('auth')->forgetGuards();
}

/** Lo stesso, ma restando collegati: per l'anteprima e per le pagine del sito con un account. */
function geFuoriDalPannello(): void
{
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
}

/**
 * I campi essenziali del modulo rapido, con l'ora scritta come la scrive un
 * gestore: nell'ora locale della città.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function geDatiEvento(Category $category, array $overrides = []): array
{
    return [
        'title' => 'Serata di prova',
        'starts_at' => '2026-09-17 21:00:00',
        'category_id' => $category->id,
        'price_type' => PriceType::Free->value,
        ...$overrides,
    ];
}

/** La sezione «Date» sotto la scheda di modifica. */
function geDate(Event $event): Testable
{
    return Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class]);
}

/** La scheda dell'evento nel pannello di redazione, aperta da chi modera. */
function geRedazione(User $staff, Event $event): Testable
{
    Filament::setTenant(null);
    test()->actingAs($staff)->get(AdminEventResource::getUrl('edit', ['record' => $event], panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');

    return Livewire::test(AdminEditEvent::class, ['record' => $event->getRouteKey()]);
}

/** Una data in più, scritta nell'ora locale della città. */
function geData(Event $event, string $localStartsAt): EventOccurrence
{
    return EventOccurrence::factory()->create([
        'event_id' => $event->id,
        'starts_at' => CarbonImmutable::parse($localStartsAt, 'Europe/Rome')->utc(),
        'ends_at' => null,
        'doors_at' => null,
    ]);
}

/** L'ora di una data nell'ora locale, nel formato in cui la si legge nelle asserzioni. */
function geOraLocale(EventOccurrence $occurrence): string
{
    return CarbonImmutable::instance($occurrence->starts_at)->setTimezone('Europe/Rome')->format('Y-m-d H:i');
}
