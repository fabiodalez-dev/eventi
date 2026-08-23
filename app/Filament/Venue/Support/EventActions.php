<?php

declare(strict_types=1);

namespace App\Filament\Venue\Support;

use App\Actions\DuplicateEventAction;
use App\Enums\EventStatus;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\User;
use App\Support\RecurrenceRule;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Le tre azioni che un gestore compie davvero su un evento: **pubblicalo**,
 * **duplicalo**, **ripetilo**.
 *
 * Stanno insieme perché compaiono negli stessi due posti — la riga
 * dell'elenco e la testata della scheda — e perché ognuna deve dichiarare da
 * sé il proprio `->authorize()`: la tenancy nasconde le righe degli altri
 * locali, ma è la Policy a rispondere alla domanda «questa persona può fare
 * *questo* su *questa* riga».
 */
final class EventActions
{
    /**
     * §9.2, §10 — «preview della scheda pubblica».
     *
     * Chi inserisce un evento vuole sapere come apparirà a chi lo cerca, e
     * l'unico modo onesto di dirglielo è portarcelo. Si apre in una scheda
     * nuova: il lavoro nel pannello non va perso, e una bozza non salvata
     * resta dov'è.
     *
     * Compare solo sugli eventi **pubblicati**: la scheda pubblica risponde
     * 404 su tutto il resto, e un pulsante che porta a una pagina inesistente
     * è peggio di un pulsante assente.
     */
    public static function viewOnSite(): Action
    {
        return Action::make('viewOnSite')
            ->label(__('manage.actions.view_on_site'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Event $record): ?string => Route::has('events.show')
                ? route('events.show', $record)
                : null)
            ->openUrlInNewTab()
            ->visible(fn (Event $record): bool => $record->status === EventStatus::Published
                && Route::has('events.show'));
    }

    /**
     * §10.3 — «la funzione più usata: quasi tutti i locali fanno serate
     * ricorrenti». Un tocco, e la copia è già compilata: restano da mettere
     * data e ora, che è l'unica cosa davvero cambiata.
     */
    public static function duplicate(): Action
    {
        return Action::make('duplicate')
            ->label(__('manage.actions.duplicate'))
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->authorize(fn (Event $record): bool => self::user()?->can('create', [Event::class, $record->venue]) ?? false)
            ->action(function (Event $record, Component $livewire): void {
                $user = self::user();

                if (! $user instanceof User) {
                    return;
                }

                $copy = app(DuplicateEventAction::class)->execute($record, $user);

                Notification::make()
                    ->title(__('manage.notifications.duplicated'))
                    ->body(__('manage.notifications.duplicated_body'))
                    ->success()
                    ->send();

                $livewire->redirect(
                    EventResource::getUrl('edit', ['record' => $copy]),
                    navigate: true,
                );
            });
    }

    /**
     * Mette online l'evento, o lo manda alla redazione: la differenza la fa
     * `venues.auto_publish` e la decide la Policy, non questo pulsante.
     */
    public static function publish(): Action
    {
        return Action::make('publish')
            ->label(__('manage.actions.publish'))
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->requiresConfirmation()
            ->modalDescription(__('manage.actions.publish_confirm'))
            ->authorize(fn (Event $record): bool => self::user()?->can('update', $record) ?? false)
            ->visible(fn (Event $record): bool => $record->status !== EventStatus::Published)
            ->disabled(fn (Event $record): bool => ! $record->occurrences()->exists())
            ->action(function (Event $record): void {
                EventPublication::notify(EventPublication::submit($record));
            });
    }

    /**
     * §10.4 — la ripetizione detta a parole. La stringa RFC 5545 la scrive
     * `RecurrenceForm`, e il gestore non la vede mai.
     */
    public static function repeat(): Action
    {
        return Action::make('repeat')
            ->label(__('manage.actions.repeat'))
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('gray')
            ->modalHeading(__('manage.recurrence.heading'))
            ->modalDescription(fn (Event $record): string => self::currentRule($record))
            ->modalSubmitActionLabel(__('manage.recurrence.submit'))
            ->authorize(fn (Event $record): bool => self::user()?->can('update', $record) ?? false)
            ->disabled(fn (Event $record): bool => ! $record->occurrences()->exists())
            ->schema(fn (Schema $schema): Schema => $schema->components(RecurrenceForm::fields()))
            ->fillForm(fn (Event $record): array => RecurrenceForm::stateFrom(
                $record->recurrences()->orderBy('id')->first(),
            ))
            ->action(function (Event $record, array $data): void {
                $created = RecurrenceForm::apply($record, $data);

                Notification::make()
                    ->title($created === 0
                        ? __('manage.notifications.no_new_dates')
                        : __('manage.notifications.dates_created', ['count' => $created]))
                    ->success()
                    ->send();
            });
    }

    /**
     * La regola già impostata, detta a parole: «ogni settimana il giovedì».
     * Il gestore non deve mai vedere la stringa RFC 5545 che sta sotto (§10.4).
     */
    private static function currentRule(Event $event): string
    {
        $recurrence = $event->recurrences()->orderBy('id')->first();

        return $recurrence === null
            ? __('manage.recurrence.description')
            : __('manage.recurrence.current', ['rule' => RecurrenceRule::describe($recurrence->rrule)]);
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
