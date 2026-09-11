<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Booking;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Services\Ticketing\BookingForm;
use App\Services\Ticketing\TicketingService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;

final class EventTicketingActions
{
    /** @return list<Action> */
    public static function forEvent(?Event $record): array
    {
        if (! $record) {
            return [];
        }

        return $record->occurrences()->orderBy('starts_at')->get()->map(function (EventOccurrence $date) use ($record): Action {
            $timezone = $record->city->timezone;

            return Action::make('configureTickets'.$date->id)
                ->label('Configura ticketing · '.$date->starts_at->timezone($timezone)->format('d/m/Y H:i'))
                ->icon('heroicon-o-ticket')->color('gray')
                ->authorize(fn (): bool => auth()->user()?->can('manage', [Booking::class, $date]) === true)
                ->modalHeading($record->title.' · Ticketing · '.$date->starts_at->timezone($timezone)->format('d/m/Y H:i'))
                ->modalDescription(fn (): string => 'Biglietti gratuiti o pagamento all’ingresso. Imposta 1 per un solo biglietto per account, oppure il massimo consentito. Il limite comprende prenotazioni attive e lista d’attesa.'.(! $date->effectiveVenue()?->ticketing_enabled ? ' Il locale non è ancora abilitato: puoi consultare le opzioni, ma per salvarle serve l’abilitazione della redazione.' : ''))
                ->fillForm(function () use ($date, $timezone): array {
                    $date->refresh();
                    $data = $date->only(['booking_enabled', 'booking_capacity', 'booking_limit', 'booking_waitlist', 'booking_instructions', 'booking_fields']);
                    $data['account_email_requirement'] = 'required';
                    $data['booking_fields'] = array_replace(array_fill_keys(BookingForm::FIELDS, 'hidden'), $date->booking_fields ?? []);
                    foreach (['booking_opens_at', 'booking_closes_at', 'cancellation_closes_at'] as $field) {
                        $data[$field] = $date->$field?->timezone($timezone)->format('Y-m-d H:i:s');
                    }

                    return $data;
                })
                ->schema([
                    TextEntry::make('notifications_info')->label('Notifiche automatiche')->state('Conferma con biglietti PDF e QR, lista d’attesa, posto liberato, variazioni e annullamenti: email e notifiche nell’account. Gli invii passano dalla coda gestita dal cron.'),
                    Toggle::make('booking_enabled')->label('Attiva prenotazioni per questa data'),
                    TextInput::make('booking_capacity')->label('Posti totali prenotabili')->helperText('Vuoto: nessun limite complessivo.')->numeric()->integer()->minValue(1)->maxValue(1000000),
                    TextInput::make('booking_limit')->label('Massimo biglietti per account')->helperText('1 = un biglietto a testa per account. Da 2 a 20 = prenotazione di un gruppo.')->numeric()->integer()->required()->minValue(1)->maxValue(20),
                    Toggle::make('booking_waitlist')->label('Abilita lista d’attesa'),
                    DateTimePicker::make('booking_opens_at')->label('Apertura prenotazioni ('.$timezone.')')->native(false)->locale('it')->displayFormat('d/m/Y H:i')->firstDayOfWeek(1)->seconds(false)->helperText('Vuoto: apertura immediata.'),
                    DateTimePicker::make('booking_closes_at')->label('Chiusura prenotazioni')->native(false)->locale('it')->displayFormat('d/m/Y H:i')->firstDayOfWeek(1)->seconds(false)->after(fn (Get $get): string => $get('booking_opens_at') ?: '1970-01-01')->helperText('Vuoto: inizio evento.'),
                    DateTimePicker::make('cancellation_closes_at')->label('Termine annullamento')->native(false)->locale('it')->displayFormat('d/m/Y H:i')->firstDayOfWeek(1)->seconds(false)->helperText('Vuoto: inizio evento.'),
                    DescriptionEditor::make('booking_instructions')->label('Istruzioni per chi prenota')->maxLength(3000),
                    Select::make('account_email_requirement')->label('Email di chi prenota')->options(['required' => 'Obbligatoria — email dell’account'])->default('required')->disabled()->dehydrated(false)->helperText('Già acquisita dall’account: riceve conferma, biglietti e aggiornamenti della prenotazione.'),
                    ...array_map(fn (string $field): Select => Select::make('booking_fields.'.$field)->label(__('ticketing.fields.'.$field))->options(['hidden' => 'Non raccogliere', 'optional' => 'Facoltativo', 'required' => 'Obbligatorio'])->default('hidden'), BookingForm::FIELDS),
                ])
                ->modalSubmitActionLabel('Salva impostazioni ticketing')
                ->modalSubmitAction(fn (Action $action): Action => $action->disabled(! $date->effectiveVenue()?->ticketing_enabled))
                ->action(function (array $data) use ($date, $timezone): void {
                    Gate::authorize('manage', [Booking::class, $date]);
                    $actor = auth()->user();
                    abort_unless($actor !== null, 403);
                    foreach (['booking_opens_at', 'booking_closes_at', 'cancellation_closes_at'] as $field) {
                        $data[$field] = empty($data[$field]) ? null : CarbonImmutable::parse($data[$field], $timezone)->utc();
                    }
                    app(TicketingService::class)->configure($date, $data, $actor);
                    Notification::make()->success()->title('Impostazioni ticketing salvate')->send();
                });
        })->all();
    }
}
