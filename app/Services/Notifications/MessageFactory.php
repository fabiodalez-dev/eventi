<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\DTOs\NotificationMessage;
use App\Enums\NotificationSkipReason;
use App\Enums\NotificationType;
use App\Enums\OccurrenceStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\SavedEvent;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Support\CurrentCity;
use App\Support\DateFormatter;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;

/**
 * Da una riga di `scheduled_notifications` al testo da mandare — oppure al
 * motivo per cui non c'è più niente da mandare.
 *
 * È il punto in cui si decide se un invio previsto ha ancora senso, e la
 * risposta arriva **dal motore temporale**: "passata", "stasera", "questo
 * weekend" hanno una definizione sola in tutto il prodotto (§8.1, §3 delle
 * convenzioni). Qui non si confronta mai una data con `now()` per stabilire
 * una finestra: si chiede a `EventOccurrenceQuery`.
 *
 * L'unico confronto diretto con l'istante corrente riguarda l'inizio di una
 * data già in mano — un promemoria per qualcosa che è già cominciato non è più
 * un promemoria — ed è la stessa domanda che §15.5 formula come «occorrenza
 * passata → skipped».
 */
final readonly class MessageFactory
{
    public function __construct(private CurrentCity $currentCity) {}

    public function build(ScheduledNotification $notification, User $user): NotificationMessage|NotificationSkipReason
    {
        $type = $notification->type();

        if ($type === null) {
            return NotificationSkipReason::MissingSubject;
        }

        return match ($type) {
            NotificationType::EventReminder => $this->reminder($notification, $user),
            NotificationType::EventCancelled => $this->cancelled($notification),
            NotificationType::EventMoved => $this->moved($notification),
            NotificationType::EventSoldOut => $this->soldOut($notification),
            NotificationType::VenueDigest => $this->venueDigest($notification, $user),
            NotificationType::DailyDigest => $this->dailyDigest($user),
            NotificationType::WeekendNewsletter => $this->weekend($user),
            NotificationType::EventPublished => $this->eventPublished($notification),
            NotificationType::EventRejected => $this->eventRejected($notification),
            NotificationType::VenueInactive => $this->venueInactive($notification),
        };
    }

    // -------------------------------------------------------------- salvataggi

    private function reminder(ScheduledNotification $notification, User $user): NotificationMessage|NotificationSkipReason
    {
        $occurrence = $this->occurrence($notification);

        if (! $occurrence instanceof EventOccurrence) {
            return NotificationSkipReason::MissingSubject;
        }

        if ($occurrence->status === OccurrenceStatus::Cancelled) {
            return NotificationSkipReason::OccurrenceCancelled;
        }

        if ($this->hasStarted($occurrence)) {
            return NotificationSkipReason::OccurrencePast;
        }

        /*
         * Chi ha tolto la data dall'agenda non deve ricevere il promemoria,
         * nemmeno se una riga è sopravvissuta a un'esecuzione interrotta a
         * metà. Il controllo costa una lettura ed evita l'unica figuraccia
         * che questo motore può fare verso chi si è già disiscritto da solo.
         */
        $stillSaved = SavedEvent::query()
            ->where('user_id', $user->getKey())
            ->where('occurrence_id', $occurrence->getKey())
            ->exists();

        if (! $stillSaved) {
            return NotificationSkipReason::NotSaved;
        }

        $hours = (int) $notification->context('hours', 0);
        $dates = $this->dates($user);
        $event = $occurrence->event;

        return new NotificationMessage(
            type: NotificationType::EventReminder,
            subject: __('notifications.reminder.subject', [
                'when' => $this->reminderWhen($hours),
                'title' => $event->title,
            ]),
            heading: $event->title,
            lines: [
                __('notifications.reminder.line', [
                    'when' => $dates->dayAndTime($occurrence->business_date, $occurrence->starts_at),
                ]),
                $this->where($occurrence),
                __('notifications.reminder.why'),
            ],
            actionLabel: __('notifications.actions.open_event'),
            url: $this->eventUrl($event),
            occurrenceId: (int) $occurrence->getKey(),
            eventId: (int) $event->getKey(),
        );
    }

    private function cancelled(ScheduledNotification $notification): NotificationMessage|NotificationSkipReason
    {
        $occurrence = $this->occurrence($notification);

        if (! $occurrence instanceof EventOccurrence) {
            return NotificationSkipReason::MissingSubject;
        }

        $event = $occurrence->event;
        $dates = $this->dates();

        return new NotificationMessage(
            type: NotificationType::EventCancelled,
            subject: __('notifications.cancelled.subject', ['title' => $event->title]),
            heading: __('notifications.cancelled.heading', ['title' => $event->title]),
            lines: array_values(array_filter([
                __('notifications.cancelled.line', [
                    'when' => $dates->dayAndTime($occurrence->business_date, $occurrence->starts_at),
                ]),
                is_string($occurrence->status_note) && $occurrence->status_note !== ''
                    ? __('notifications.cancelled.note', ['note' => $occurrence->status_note])
                    : null,
                __('notifications.cancelled.why'),
            ])),
            actionLabel: __('notifications.actions.open_event'),
            url: $this->eventUrl($event),
            occurrenceId: (int) $occurrence->getKey(),
            eventId: (int) $event->getKey(),
        );
    }

    private function moved(ScheduledNotification $notification): NotificationMessage|NotificationSkipReason
    {
        $occurrence = $this->occurrence($notification);

        if (! $occurrence instanceof EventOccurrence) {
            return NotificationSkipReason::MissingSubject;
        }

        if ($occurrence->status === OccurrenceStatus::Cancelled) {
            return NotificationSkipReason::OccurrenceCancelled;
        }

        if ($this->hasStarted($occurrence)) {
            return NotificationSkipReason::OccurrencePast;
        }

        $event = $occurrence->event;
        $dates = $this->dates();
        $previous = $notification->context('previous_starts_at');

        return new NotificationMessage(
            type: NotificationType::EventMoved,
            subject: __('notifications.moved.subject', ['title' => $event->title]),
            heading: __('notifications.moved.heading', ['title' => $event->title]),
            lines: array_values(array_filter([
                is_string($previous)
                    ? __('notifications.moved.previous', [
                        'when' => $dates->dayAndTime(CarbonImmutable::parse($previous), CarbonImmutable::parse($previous)),
                    ])
                    : null,
                __('notifications.moved.line', [
                    'when' => $dates->dayAndTime($occurrence->business_date, $occurrence->starts_at),
                ]),
                __('notifications.moved.why'),
            ])),
            actionLabel: __('notifications.actions.open_event'),
            url: $this->eventUrl($event),
            occurrenceId: (int) $occurrence->getKey(),
            eventId: (int) $event->getKey(),
        );
    }

    private function soldOut(ScheduledNotification $notification): NotificationMessage|NotificationSkipReason
    {
        $occurrence = $this->occurrence($notification);

        if (! $occurrence instanceof EventOccurrence) {
            return NotificationSkipReason::MissingSubject;
        }

        if ($this->hasStarted($occurrence)) {
            return NotificationSkipReason::OccurrencePast;
        }

        $event = $occurrence->event;
        $dates = $this->dates();

        return new NotificationMessage(
            type: NotificationType::EventSoldOut,
            subject: __('notifications.sold_out.subject', ['title' => $event->title]),
            heading: __('notifications.sold_out.heading', ['title' => $event->title]),
            lines: [
                __('notifications.sold_out.line', [
                    'when' => $dates->dayAndTime($occurrence->business_date, $occurrence->starts_at),
                ]),
                __('notifications.sold_out.why'),
            ],
            actionLabel: __('notifications.actions.open_event'),
            url: $this->eventUrl($event),
            occurrenceId: (int) $occurrence->getKey(),
            eventId: (int) $event->getKey(),
        );
    }

    // ---------------------------------------------------------------- riepiloghi

    /**
     * «Nuovi eventi da chi segui», settimanale e **mai** per singolo evento
     * (§15.4). La finestra è quella del motore — da oggi in avanti — e la
     * novità si misura sull'ultima settimana, che è l'intervallo fra un
     * riepilogo e il precedente.
     */
    private function venueDigest(ScheduledNotification $notification, User $user): NotificationMessage|NotificationSkipReason
    {
        $city = $this->city();

        if (! $city instanceof City) {
            return NotificationSkipReason::NothingToSend;
        }

        $since = $notification->context('since');
        $window = is_string($since)
            ? CarbonImmutable::parse($since)
            : CarbonImmutable::now()->subDays(config()->integer('notifications.digests.venue.window_days'));

        $query = EventOccurrenceQuery::for($city)
            ->followedBy($user)
            ->upcoming()
            ->updatedSince($window);

        $items = $this->items($query, config()->integer('notifications.digests.venue.max_items'), $user);

        if ($items === []) {
            return NotificationSkipReason::NothingToSend;
        }

        return new NotificationMessage(
            type: NotificationType::VenueDigest,
            subject: __('notifications.venue_digest.subject'),
            heading: __('notifications.venue_digest.heading'),
            lines: [__('notifications.venue_digest.line', ['count' => count($items)])],
            actionLabel: __('notifications.actions.open_feed'),
            url: route('account.feed'),
            items: $items,
        );
    }

    /**
     * «Stasera nei tuoi generi» (§15.4). "Stasera" lo definisce il motore e
     * nessun altro: è la stessa finestra della homepage (§8.4).
     *
     * Il riepilogo è personale: togliere tutti gli interessi non deve
     * allargare di nascosto l'iscrizione a tutta la città.
     */
    private function dailyDigest(User $user): NotificationMessage|NotificationSkipReason
    {
        $city = $this->city();

        if (! $city instanceof City) {
            return NotificationSkipReason::NothingToSend;
        }

        $query = EventOccurrenceQuery::for($city)->tonight()->followedBy($user);

        $items = $this->items($query, config()->integer('notifications.digests.daily.max_items'), $user);

        if ($items === []) {
            return NotificationSkipReason::NothingToSend;
        }

        return new NotificationMessage(
            type: NotificationType::DailyDigest,
            subject: __('notifications.daily_digest.subject'),
            heading: __('notifications.daily_digest.heading'),
            lines: [__('notifications.daily_digest.line', ['count' => count($items)])],
            actionLabel: __('notifications.actions.open_tonight'),
            url: route('events.today'),
            items: $items,
        );
    }

    private function weekend(User $user): NotificationMessage|NotificationSkipReason
    {
        $city = $this->city();

        if (! $city instanceof City) {
            return NotificationSkipReason::NothingToSend;
        }

        $items = $this->items(
            EventOccurrenceQuery::for($city)->weekend(),
            config()->integer('notifications.digests.weekend.max_items'),
            $user,
        );

        if ($items === []) {
            return NotificationSkipReason::NothingToSend;
        }

        return new NotificationMessage(
            type: NotificationType::WeekendNewsletter,
            subject: __('notifications.weekend.subject'),
            heading: __('notifications.weekend.heading'),
            lines: [__('notifications.weekend.line', ['count' => count($items)])],
            actionLabel: __('notifications.actions.open_weekend'),
            url: route('events.weekend'),
            items: $items,
        );
    }

    // ------------------------------------------------------------- ai gestori

    private function eventPublished(ScheduledNotification $notification): NotificationMessage|NotificationSkipReason
    {
        $event = $this->event($notification);

        if (! $event instanceof Event) {
            return NotificationSkipReason::MissingSubject;
        }

        return new NotificationMessage(
            type: NotificationType::EventPublished,
            subject: __('notifications.event_published.subject', ['title' => $event->title]),
            heading: __('notifications.event_published.heading', ['title' => $event->title]),
            lines: [__('notifications.event_published.line')],
            actionLabel: __('notifications.actions.open_event'),
            url: $this->eventUrl($event),
            eventId: (int) $event->getKey(),
        );
    }

    private function eventRejected(ScheduledNotification $notification): NotificationMessage|NotificationSkipReason
    {
        $event = $this->event($notification);

        if (! $event instanceof Event) {
            return NotificationSkipReason::MissingSubject;
        }

        return new NotificationMessage(
            type: NotificationType::EventRejected,
            subject: __('notifications.event_rejected.subject', ['title' => $event->title]),
            heading: __('notifications.event_rejected.heading', ['title' => $event->title]),
            lines: array_values(array_filter([
                is_string($event->rejection_reason) && $event->rejection_reason !== ''
                    ? __('notifications.event_rejected.reason', ['reason' => $event->rejection_reason])
                    : null,
                __('notifications.event_rejected.line'),
            ])),
            actionLabel: __('notifications.actions.open_panel'),
            url: $this->panelUrl($event->venue),
            eventId: (int) $event->getKey(),
        );
    }

    private function venueInactive(ScheduledNotification $notification): NotificationMessage|NotificationSkipReason
    {
        $venue = $notification->notifiable;

        if (! $venue instanceof Venue) {
            return NotificationSkipReason::MissingSubject;
        }

        return new NotificationMessage(
            type: NotificationType::VenueInactive,
            subject: __('notifications.venue_inactive.subject', ['venue' => $venue->name]),
            heading: __('notifications.venue_inactive.heading', ['venue' => $venue->name]),
            lines: [
                __('notifications.venue_inactive.line', [
                    'days' => config()->integer('notifications.venue_inactivity_days'),
                ]),
            ],
            actionLabel: __('notifications.actions.open_panel'),
            url: $this->panelUrl($venue),
        );
    }

    // ---------------------------------------------------------------- interno

    /**
     * Le voci di un riepilogo, con il proprio collegamento profondo ciascuna:
     * §15.4 vuole che **ogni** notifica porti alla scheda, mai alla home.
     *
     * @return list<array{title: string, meta: string, url: string}>
     */
    private function items(EventOccurrenceQuery $query, int $max, User $user): array
    {
        $dates = $this->dates($user);
        $items = [];

        /** @var EventOccurrence $occurrence */
        foreach ($query->paginate(max($max, 1), 1)->items() as $occurrence) {
            $event = $occurrence->event;

            $items[] = [
                'title' => $event->title,
                'meta' => trim($dates->dayAndTime($occurrence->business_date, $occurrence->starts_at).' '.$this->where($occurrence)),
                'url' => $this->eventUrl($event),
            ];
        }

        return $items;
    }

    private function occurrence(ScheduledNotification $notification): ?EventOccurrence
    {
        $occurrence = $notification->notifiable;

        if (! $occurrence instanceof EventOccurrence) {
            return null;
        }

        $occurrence->loadMissing(['event.venue', 'event.city']);

        return $occurrence->event instanceof Event ? $occurrence : null;
    }

    private function event(ScheduledNotification $notification): ?Event
    {
        $event = $notification->notifiable;

        if (! $event instanceof Event) {
            return null;
        }

        $event->loadMissing('venue');

        return $event;
    }

    private function hasStarted(EventOccurrence $occurrence): bool
    {
        return CarbonImmutable::instance($occurrence->starts_at)->lessThanOrEqualTo(CarbonImmutable::now());
    }

    private function reminderWhen(int $hours): string
    {
        return $hours === 24
            ? __('notifications.reminder.when_tomorrow')
            : trans_choice('notifications.reminder.when_hours', $hours, ['count' => $hours]);
    }

    private function where(EventOccurrence $occurrence): string
    {
        $venue = $occurrence->event->venue;

        if (! $venue instanceof Venue) {
            return '';
        }

        return __('notifications.common.at_venue', [
            'venue' => $venue->name,
            'municipality' => $venue->municipality,
        ]);
    }

    private function eventUrl(Event $event): string
    {
        return route('events.show', $event);
    }

    private function panelUrl(?Venue $venue): string
    {
        if (! $venue instanceof Venue) {
            return url('/');
        }

        return Filament::getPanel('venue')->getUrl($venue) ?? url('/');
    }

    /**
     * Le date si stampano nel fuso di chi legge: è un campo del profilo
     * (§15.2) e non un dettaglio della città. Senza destinatario — o senza
     * fuso dichiarato — vale quello della città servita.
     */
    private function dates(?User $user = null): DateFormatter
    {
        if ($user instanceof User && $user->timezone !== '') {
            return DateFormatter::forTimezone($user->timezone);
        }

        $city = $this->city();

        return DateFormatter::forTimezone($city instanceof City ? $city->timezone : config()->string('app.timezone'));
    }

    private function city(): ?City
    {
        return $this->currentCity->get();
    }
}
