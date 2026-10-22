<?php

declare(strict_types=1);

namespace App\Services\Ticketing;

use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\TicketTierStatus;
use App\Jobs\DeactivateWalletPass;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Notifications\BookingChanged;
use App\Notifications\BookingMessageSent;
use App\Notifications\GuestBookingConfirmation;
use App\Services\Notifications\NotificationScheduler;
use App\Support\ContentVersion;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TicketingService
{
    public function activeBooking(User $user, EventOccurrence $date): ?Booking
    {
        return Booking::query()->active()->where('user_id', $user->id)
            ->where('occurrence_id', $date->id)->latest('id')->first();
    }

    /** @return array<string, int> */
    public function statistics(EventOccurrence $date): array
    {
        return AdmissionTicket::query()->whereHas('booking', fn ($query) => $query->where('occurrence_id', $date->id))
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(fn ($total): int => (int) $total)->all();
    }

    /** @return array<string, mixed> */
    public function availability(EventOccurrence $date): array
    {
        $used = $this->occupied($date);
        $remaining = $date->booking_capacity === null ? null : max(0, $date->booking_capacity - $used);
        $open = $this->isOpen($date);

        return [
            'enabled' => (bool) ($date->booking_enabled && $date->effectiveVenue()?->ticketing_enabled),
            'open' => $open,
            'sale_state' => match (true) {
                ! $this->eventValid($date) => TicketTierStatus::Closed->value,
                $date->status === OccurrenceStatus::SoldOut || $remaining === 0 => TicketTierStatus::SoldOut->value,
                $open => TicketTierStatus::Available->value,
                $date->booking_opens_at !== null && now()->lt($date->booking_opens_at) => TicketTierStatus::NotYetOnSale->value,
                default => TicketTierStatus::Closed->value,
            },
            'capacity' => $date->booking_capacity,
            'remaining' => $remaining,
            'limit_per_account' => $date->booking_limit,
            'waitlist' => (bool) $date->booking_waitlist,
            'opens_at' => $date->booking_opens_at?->toIso8601String(),
            'closes_at' => ($date->booking_closes_at ?? $date->starts_at)->toIso8601String(),
            'cancellation_closes_at' => ($date->cancellation_closes_at ?? $date->starts_at)->toIso8601String(),
            'instructions' => $date->booking_instructions,
            'booker_fields' => app(BookingForm::class)->fields($date),
            'privacy_url' => url('/pagine/privacy'),
        ];
    }

    /** @param list<string|array{first_name: string, last_name: string}> $names
     * @param  array<string, mixed>  $booker
     */
    public function reserve(User $user, EventOccurrence $date, array $names, string $key, bool $waitlist, array $booker = []): Booking
    {
        $names = array_map(fn ($name) => is_array($name) ? array_map('trim', $name) : trim($name), $names);
        ksort($booker);
        // Preserve the digest of already-issued legacy retries.
        $payload = [$date->id, $names, $waitlist];
        if ($booker !== []) {
            $payload[] = $booker;
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $date, $names, $key, $hash, $waitlist, $booker): Booking {
            // Serialise retries even when a client reuses its key for another date.
            $account = User::query()->lockForUpdate()->findOrFail($user->id);
            // The occurrence lock must precede ANY consistent read. Under
            // InnoDB REPEATABLE READ an earlier snapshot could miss seats
            // committed by the request we just waited for.
            $date = EventOccurrence::query()->lockForUpdate()->findOrFail($date->id);
            $existing = Booking::query()->where('user_id', $account->id)->where('request_key', $key)->first();
            if ($existing) {
                $this->ensure(hash_equals($existing->request_hash, $hash), 'retry_conflict');

                return $existing->load('tickets');
            }
            $this->ensure($this->activeBooking($account, $date) === null, 'already_booked');
            $this->ensure($this->isOpen($date), 'closed');
            $this->ensure($this->noShowCount($account) < config()->integer('ticketing.no_show.limit'), 'no_show_limit');
            $details = app(BookingForm::class)->validate($date, $booker);
            // Resume queued groups first when a previously closed window reopens.
            $this->promote($date);
            $this->ensure(count($names) > 0 && count($names) <= $date->booking_limit, 'account_limit');
            $already = AdmissionTicket::query()->whereHas('booking', fn ($q) => $q->where('occurrence_id', $date->id)->where('user_id', $user->id))
                ->where('status', '!=', AdmissionStatus::Cancelled)->count();
            $this->ensure($already + count($names) <= $date->booking_limit, 'account_limit');
            /* La coda spinge in lista d'attesa solo finché la coda può davvero
               avanzare. A ridosso dell'inizio `promotable()` ferma le promozioni:
               senza questa condizione il posto rimasto libero non sarebbe
               prenotabile da nessuno — la coda non lo prende più e chi prova a
               prenotarlo viene mandato in coda a sua volta — mentre la pagina
               continua a dichiararlo disponibile. In quelle ore il posto è di
               chi si presenta, che è esattamente ciò che `promotable()` dice. */
            $hasQueue = $this->promotable($date)
                && Booking::query()->where('occurrence_id', $date->id)->where('status', BookingStatus::Waitlisted)->exists();
            $full = $hasQueue || ($date->booking_capacity !== null && $this->occupied($date) + count($names) > $date->booking_capacity);
            $this->ensure(! $full || ($waitlist && $date->booking_waitlist), 'full');
            $booking = Booking::query()->create([
                'occurrence_id' => $date->id, 'user_id' => $user->id, 'request_key' => $key,
                'request_hash' => $hash, 'status' => $full ? BookingStatus::Waitlisted : BookingStatus::Confirmed,
                'booker_data' => $details ?: null,
                'privacy_accepted_at' => now(),
                'privacy_version' => BookingForm::PRIVACY_VERSION,
            ]);
            foreach ($names as $name) {
                $booking->tickets()->create([
                    'attendee_name' => is_array($name) ? $name['first_name'].' '.$name['last_name'] : $name,
                    'first_name' => is_array($name) ? $name['first_name'] : null,
                    'last_name' => is_array($name) ? $name['last_name'] : null,
                    'code' => Str::random(64),
                    'status' => $full ? AdmissionStatus::Waitlisted : AdmissionStatus::Valid,
                ]);
            }
            $this->notify($booking, $full ? 'waitlisted' : 'confirmed');
            $this->audit($booking, 'reserved', $user);
            app(NotificationScheduler::class)->announceAlmostFull($date);

            return $booking->load('tickets');
        }, 3);
    }

    public function cancel(Booking $booking, User $actor, ?int $ticketId = null, bool $staff = false, ?string $reason = null): Booking
    {
        return DB::transaction(function () use ($booking, $actor, $ticketId, $staff, $reason): Booking {
            $date = EventOccurrence::withTrashed()->lockForUpdate()->findOrFail($booking->occurrence_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $tickets = $booking->tickets()->when($ticketId !== null, fn ($q) => $q->whereKey($ticketId))->get();
            abort_if($tickets->isEmpty(), 404);
            $active = $tickets->where('status', '!=', AdmissionStatus::Cancelled);
            if ($active->isEmpty()) {
                return $booking->load('tickets');
            }
            if (! $staff) {
                $this->ensure(now()->lt($date->cancellation_closes_at ?? $date->starts_at), 'cancellation_closed');
                $this->ensure(! $active->contains('status', AdmissionStatus::CheckedIn), 'already_used');
            }
            foreach ($active as $ticket) {
                $ticket->update(['status' => AdmissionStatus::Cancelled, 'cancelled_at' => now()]);
            }
            if (! $booking->tickets()->where('status', '!=', AdmissionStatus::Cancelled)->exists()) {
                $booking->update(['status' => BookingStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            }
            $this->audit($booking, 'cancelled', $actor);
            $this->notify($booking, 'cancelled');
            $this->promote($date);

            return $booking->load('tickets');
        }, 3);
    }

    public function checkIn(EventOccurrence $date, string $code, User $actor, ?string $requestKey = null, ?DateTimeInterface $scannedAt = null): AdmissionTicket
    {
        return DB::transaction(function () use ($date, $code, $actor, $requestKey, $scannedAt): AdmissionTicket {
            $date = EventOccurrence::query()->lockForUpdate()->findOrFail($date->id);
            $this->ensure($this->eventValid($date), 'event_cancelled');
            $this->ensure(now()->between($date->doors_at ?? $date->starts_at->copy()->subHours(3), $date->effective_ends_at ?? $date->starts_at->copy()->addHours(6)), 'checkin_closed');
            $ticket = AdmissionTicket::query()->where('code', $code)
                ->whereHas('booking', fn ($q) => $q->where('occurrence_id', $date->id)->where('status', BookingStatus::Confirmed))
                ->lockForUpdate()->first();
            $this->ensure($ticket !== null, 'invalid_qr');
            if ($ticket->status === AdmissionStatus::CheckedIn && $requestKey !== null && $ticket->checkin_request_key === $requestKey && $ticket->checked_in_by === $actor->id) {
                return $ticket;
            }
            $this->ensure($ticket->status !== AdmissionStatus::CheckedIn, 'already_used');
            $this->ensure($ticket->status === AdmissionStatus::Valid, 'invalid_qr');
            /* Una finestra di conferma già scaduta non si sana alla porta.
               Il lavoro che libera quei posti gira ogni minuto, quindi fra la
               scadenza e l'annullamento c'è un momento in cui il biglietto
               sembra ancora buono: senza questo controllo, chi arriva proprio
               in quel momento entrerebbe su un posto che sta per tornare a chi
               aspetta, e chi arriva un minuto dopo no. */
            $this->ensure($ticket->booking?->promotion_expires_at === null
                || $ticket->booking->promotion_expires_at->isFuture(), 'promotion_expired');
            /* Una lettura fatta senza rete arriva dopo, con l'ora in cui la
               persona è passata davvero: si conserva quella, purché stia
               dentro la serata. Un orologio sbagliato non sposta un ingresso
               fuori dalla finestra in cui la porta era aperta. */
            $from = $date->doors_at ?? $date->starts_at->copy()->subHours(3);
            $scanned = $scannedAt !== null && CarbonImmutable::instance($scannedAt)->between($from, now()) ? CarbonImmutable::instance($scannedAt)->utc() : now();
            $ticket->update(['status' => AdmissionStatus::CheckedIn, 'checked_in_at' => $scanned, 'checked_in_by' => $actor->id, 'checkin_request_key' => $requestKey]);
            /* Presentarsi alla porta è la conferma più forte che esista, e chiude
               la finestra. Senza, `expirePromotions()` — che gira ogni minuto —
               poteva annullare fra sessanta secondi una prenotazione il cui
               biglietto era già passato al controllo: la persona è dentro e il
               sistema dice che non ha un posto. Entrambi i percorsi bloccano
               prima la data, quindi qui non serve un ordine di lock nuovo. */
            Booking::query()->whereKey($ticket->booking_id)->whereNotNull('promotion_expires_at')
                ->update(['promotion_expires_at' => null]);
            $this->audit($ticket->booking, 'checked_in:'.$ticket->id, $actor);
            if ($ticket->wallet_requested_at !== null && app(GoogleWallet::class)->configured()) {
                DeactivateWalletPass::dispatch(app(GoogleWallet::class)->objectId((int) $ticket->id));
            }

            return $ticket;
        }, 3);
    }

    /** @param array<string, mixed> $settings */
    public function configure(EventOccurrence $date, array $settings, User $actor): void
    {
        DB::transaction(function () use ($date, $settings, $actor): void {
            $date = EventOccurrence::query()->lockForUpdate()->findOrFail($date->id);
            $this->ensure((bool) $date->effectiveVenue()?->ticketing_enabled, 'disabled');
            $capacity = $settings['booking_capacity'] ?? null;
            $this->ensure($capacity === null || $capacity >= $this->occupied($date), 'capacity_too_low');
            $date->update($settings);
            activity('ticketing')->causedBy($actor)->performedOn($date)->log('configured');
            $this->promote($date);
        }, 3);
    }

    public function cancelDate(EventOccurrence $date): void
    {
        DB::transaction(function () use ($date): void {
            $date = EventOccurrence::withTrashed()->lockForUpdate()->find($date->id);
            if (! $date) {
                return;
            }
            Booking::query()->where('occurrence_id', $date->id)->where('status', '!=', BookingStatus::Cancelled)
                ->chunkById(100, function ($bookings): void {
                    foreach ($bookings as $booking) {
                        $booking->tickets()->where('status', '!=', AdmissionStatus::Cancelled)->update(['status' => AdmissionStatus::Cancelled->value, 'cancelled_at' => now()]);
                        $booking->update(['status' => BookingStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => __('ticketing.errors.event_cancelled')]);
                        $this->notify($booking, 'event_cancelled');
                    }
                });
        }, 3);
    }

    public function announceChange(EventOccurrence $date): void
    {
        Booking::query()->where('occurrence_id', $date->id)->where('status', '!=', BookingStatus::Cancelled)
            ->each(fn (Booking $booking) => $this->notify($booking, 'changed'));
    }

    public function promoteWaitingLists(): void
    {
        Booking::query()->where('status', BookingStatus::Waitlisted)->select('occurrence_id')->distinct()
            ->orderBy('occurrence_id')->get()->each(function (Booking $booking): void {
                DB::transaction(function () use ($booking): void {
                    $date = EventOccurrence::query()->lockForUpdate()->find($booking->occurrence_id);
                    if ($date) {
                        $this->promote($date);
                    }
                }, 3);
            });
    }

    public function eraseUser(User $user): void
    {
        $dateIds = Booking::query()->where('user_id', $user->id)->distinct()->orderBy('occurrence_id')->pluck('occurrence_id');
        foreach ($dateIds as $id) {
            $date = EventOccurrence::withTrashed()->lockForUpdate()->find($id);
            Booking::query()->where('user_id', $user->id)->where('occurrence_id', $id)->each(fn (Booking $booking) => $this->revokeWallets($booking, true));
            Booking::query()->where('user_id', $user->id)->where('occurrence_id', $id)->delete();
            if ($date) {
                $this->promote($date);
            }
        }
    }

    /**
     * Una prenotazione scritta dal personale: la telefonata, la persona al
     * banco, l'ospite dell'organizzatore. Nessun account dietro, nessun limite
     * per account, ma i posti sono gli stessi di tutti: se la data è piena il
     * banco lo vede e, se vuole, alza i posti dalle impostazioni. Niente lista
     * d'attesa, perché chi è al telefono vuole un sì o un no.
     *
     * @param  list<string>  $names
     * @param  array{email?: string|null, phone?: string|null, note?: string|null}  $contact
     */
    public function reserveForGuest(EventOccurrence $date, array $names, array $contact, User $staff): Booking
    {
        $names = array_values(array_filter(array_map(trim(...), $names), static fn (string $name): bool => $name !== ''));
        $contact = array_filter(array_map(static fn ($value) => is_string($value) ? trim($value) : $value, $contact));

        return DB::transaction(function () use ($date, $names, $contact, $staff): Booking {
            $date = EventOccurrence::query()->lockForUpdate()->findOrFail($date->id);
            $this->ensure($this->eventValid($date) && (bool) $date->effectiveVenue()?->ticketing_enabled, 'closed');
            $this->ensure(now()->lt($date->effective_ends_at ?? $date->starts_at->copy()->addHours(6)), 'closed');
            $this->ensure(count($names) > 0 && count($names) <= 50, 'account_limit');
            $this->ensure($date->booking_capacity === null || $this->occupied($date) + count($names) <= $date->booking_capacity, 'full');
            $booking = Booking::query()->create([
                'occurrence_id' => $date->id, 'user_id' => null, 'created_by' => $staff->id,
                'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', (string) Str::uuid()),
                'status' => BookingStatus::Confirmed,
                'booker_data' => $contact === [] ? null : $contact,
                'privacy_accepted_at' => null,
            ]);
            foreach ($names as $name) {
                $booking->tickets()->create(['attendee_name' => $name, 'code' => Str::random(64), 'status' => AdmissionStatus::Valid]);
            }
            $email = $booking->contactEmail();
            activity('ticketing')->causedBy($staff)->performedOn($booking)->withProperties(array_filter(['email' => $email]))->log('reserved_by_staff');
            $cityId = $date->event?->city_id;
            if ($cityId !== null) {
                DB::afterCommit(fn () => ContentVersion::bump($cityId));
            }
            /* La mail parte verso un indirizzo scritto dal personale: un tetto
               giornaliero per data impedisce che il modulo diventi un modo per
               spedire posta a chiunque. Oltre il tetto la prenotazione resta,
               la mail no, e il pannello lo dice. */
            if ($email !== null) {
                $mailedToday = Booking::query()->where('occurrence_id', $date->id)->whereNull('user_id')
                    ->where('created_at', '>=', now()->subDay())->where('id', '!=', $booking->id)->whereNotNull('booker_data')->count();
                $this->ensure($mailedToday < config()->integer('ticketing.guest.daily_mail_limit'), 'guest_mail_limit');
                Notification::route('mail', $email)->notify(new GuestBookingConfirmation($booking->id));
            }
            app(NotificationScheduler::class)->announceAlmostFull($date);

            return $booking->load('tickets');
        }, 3);
    }

    /**
     * Il locale scrive a chi ha prenotato: «porta la tessera», «si comincia
     * alle 22». Arriva a chi ha un posto confermato e, se richiesto, a chi è
     * in coda. Il tetto giornaliero per data sta in configurazione: è un
     * canale verso persone che hanno solo prenotato, non una lista di
     * iscritti.
     */
    public function message(EventOccurrence $date, User $sender, string $subject, string $body, bool $includeWaitlisted): BookingMessage
    {
        return DB::transaction(function () use ($date, $sender, $subject, $body, $includeWaitlisted): BookingMessage {
            $date = EventOccurrence::query()->lockForUpdate()->findOrFail($date->id);
            $sentToday = BookingMessage::query()->where('occurrence_id', $date->id)->where('created_at', '>=', now()->subDay())->count();
            $this->ensure($sentToday < config()->integer('ticketing.messages.daily_limit'), 'message_limit');
            $statuses = $includeWaitlisted ? [BookingStatus::Confirmed, BookingStatus::Waitlisted] : [BookingStatus::Confirmed];
            $recipients = Booking::query()->where('occurrence_id', $date->id)->whereIn('status', $statuses)->with('user')->get();
            $message = BookingMessage::query()->create([
                'occurrence_id' => $date->id, 'sender_id' => $sender->id, 'subject' => $subject, 'body' => $body,
                'include_waitlisted' => $includeWaitlisted, 'recipients_count' => 0,
            ]);
            $delivered = 0;
            $guests = [];
            foreach ($recipients as $booking) {
                if ($booking->user !== null) {
                    $booking->user->notify(new BookingMessageSent($message->id, $booking->id));
                    $delivered++;
                } elseif (($email = $booking->contactEmail()) !== null && ! in_array($email, $guests, true)) {
                    Notification::route('mail', $email)->notify(new BookingMessageSent($message->id, $booking->id));
                    $guests[] = $email;
                    $delivered++;
                }
            }
            $message->update(['recipients_count' => $delivered]);
            activity('ticketing')->causedBy($sender)->performedOn($date)->withProperties(['message_id' => $message->id, 'recipients' => $delivered])->log('message_sent');

            return $message;
        }, 3);
    }

    /**
     * Le assenze: biglietti validi mai passati dalla porta, a serata finita,
     * **solo dove la porta ha registrato almeno un ingresso**. Un locale che
     * non ha scansionato nessuno non ha assenti, ha solo biglietti che nessuno
     * ha guardato. Il comando gira ogni ora; la tolleranza dopo la fine evita
     * di segnare chi è entrato con l'ultima lettura ancora in coda.
     */
    public function markNoShows(): int
    {
        $cutoff = now()->subHours(config()->integer('ticketing.no_show.grace_hours'));
        $marked = 0;
        $dates = EventOccurrence::withTrashed()
            ->whereRaw('COALESCE(effective_ends_at, ends_at, starts_at) < ?', [$cutoff])
            ->whereHas('bookings.tickets', fn ($q) => $q->where('status', AdmissionStatus::Valid))
            ->whereHas('bookings', fn ($q) => $q->whereNotNull('user_id')->whereHas('tickets', fn ($t) => $t->where('status', AdmissionStatus::CheckedIn)))
            ->orderBy('id')->pluck('id');
        foreach ($dates as $id) {
            /* La porta deve essere stata usata davvero, e da chi ha prenotato
               da solo: un gestore che registra un ingresso fittizio dal banco
               e lascia gli altri «validi» non deve poter segnare assenti
               persone che magari sono entrate senza essere scansionate. La
               soglia sta in configurazione; le prenotazioni del banco non
               fanno prova né in un senso né nell'altro. */
            $self = AdmissionTicket::query()->whereHas('booking', fn ($q) => $q->where('occurrence_id', $id)->whereNotNull('user_id')->where('status', BookingStatus::Confirmed))
                ->whereIn('status', [AdmissionStatus::Valid, AdmissionStatus::CheckedIn])
                ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
            $present = (int) ($self[AdmissionStatus::CheckedIn->value] ?? 0);
            $total = $present + (int) ($self[AdmissionStatus::Valid->value] ?? 0);
            if ($total === 0 || $present / $total < config()->float('ticketing.no_show.min_checked_in_share')) {
                continue;
            }
            $marked += AdmissionTicket::query()->where('status', AdmissionStatus::Valid)
                ->whereHas('booking', fn ($q) => $q->where('occurrence_id', $id)->whereNotNull('user_id')->where('status', BookingStatus::Confirmed))
                ->update(['status' => AdmissionStatus::NoShow->value]);
        }

        return $marked;
    }

    /** Quante assenze recenti ha questa persona, nella finestra di configurazione. */
    public function noShowCount(User $user): int
    {
        return AdmissionTicket::query()->where('status', AdmissionStatus::NoShow)
            ->where('updated_at', '>=', now()->subDays(config()->integer('ticketing.no_show.window_days')))
            ->whereHas('booking', fn ($q) => $q->where('user_id', $user->id))->count();
    }

    /**
     * L'elenco che il controllo ingressi scarica **prima** della serata, per
     * riconoscere i biglietti anche senza rete. Dei codici viaggia solo
     * l'impronta: chi tiene il telefono alla porta non deve poter ricostruire
     * un QR altrui. L'ingresso resta confermato dal server quando la rete
     * torna; qui c'è quanto basta per dire «sì, è in lista» o «mai visto».
     *
     * @return array{generated_at: string, window: array{from: string, to: string}, tickets: list<array{h: string, name: string, status: string}>}
     */
    public function manifest(EventOccurrence $date): array
    {
        $tickets = AdmissionTicket::query()->whereHas('booking', fn ($q) => $q->where('occurrence_id', $date->id)->where('status', BookingStatus::Confirmed))
            ->whereIn('status', [AdmissionStatus::Valid, AdmissionStatus::CheckedIn])
            ->whereDoesntHave('booking', fn ($q) => $q->whereNotNull('promotion_expires_at')->where('promotion_expires_at', '<=', now()))
            ->orderBy('id')->get(['id', 'code', 'attendee_name', 'status']);

        return [
            'generated_at' => now()->toIso8601String(),
            'window' => [
                'from' => ($date->doors_at ?? $date->starts_at->copy()->subHours(3))->toIso8601String(),
                'to' => ($date->effective_ends_at ?? $date->starts_at->copy()->addHours(6))->toIso8601String(),
            ],
            'tickets' => $tickets->map(fn (AdmissionTicket $ticket): array => [
                'h' => hash('sha256', $ticket->code), 'name' => $ticket->attendee_name, 'status' => $ticket->status->value,
            ])->all(),
        ];
    }

    private function promote(EventOccurrence $date): void
    {
        if (! $this->isOpen($date) || ! $this->promotable($date)) {
            return;
        }
        while ($booking = Booking::query()->where('occurrence_id', $date->id)->where('status', BookingStatus::Waitlisted)->oldest('id')->first()) {
            $count = $booking->tickets()->where('status', AdmissionStatus::Waitlisted)->count();
            if ($date->booking_capacity !== null && $this->occupied($date) + $count > $date->booking_capacity) {
                break;
            }
            $booking->update(['status' => BookingStatus::Confirmed, 'promotion_expires_at' => $this->confirmBy($date)]);
            $booking->tickets()->where('status', AdmissionStatus::Waitlisted)->update(['status' => AdmissionStatus::Valid->value]);
            $this->notify($booking, 'promoted');
            app(NotificationScheduler::class)->announceAlmostFull($date);
        }
    }

    /**
     * A ridosso dell'inizio non si promuove più: il posto resta libero per chi
     * si presenta, invece di essere assegnato a qualcuno che non farebbe in
     * tempo nemmeno a leggere l'avviso.
     */
    private function promotable(EventOccurrence $date): bool
    {
        return now()->lt($date->starts_at->subHours(config()->integer('ticketing.promotion.min_hours_before')));
    }

    /** Entro quando confermare: mai oltre l'inizio della serata. */
    private function confirmBy(EventOccurrence $date): CarbonImmutable
    {
        $deadline = CarbonImmutable::now()->addHours(config()->integer('ticketing.promotion.confirm_hours'));
        $start = CarbonImmutable::parse($date->starts_at);

        return $deadline->greaterThan($start) ? $start : $deadline;
    }

    /**
     * La conferma di chi è stato promosso: il posto smette di avere una
     * scadenza e diventa suo come ogni altra prenotazione.
     */
    public function confirmPromotion(Booking $booking, User $actor): Booking
    {
        return DB::transaction(function () use ($booking, $actor): Booking {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            abort_unless($booking->user_id === $actor->id, 403);
            $this->ensure($booking->status === BookingStatus::Confirmed && $booking->promotion_expires_at !== null, 'promotion_missing');
            $this->ensure($booking->promotion_expires_at->isFuture(), 'promotion_expired');
            $booking->update(['promotion_expires_at' => null]);
            $this->audit($booking, 'promotion_confirmed', $actor);

            return $booking;
        }, 3);
    }

    /**
     * I posti promossi e mai confermati tornano in circolo, e la coda avanza.
     *
     * Non è un annullamento qualunque: chi non conferma non ha rinunciato, ha
     * solo taciuto. Il motivo registrato lo dice, così nel pannello si distingue
     * da chi ha disdetto davvero.
     */
    public function expirePromotions(): int
    {
        $expired = 0;
        /* Chi è già entrato non si annulla, doppia rete oltre al check-in che
           chiude la finestra: togliere dall'annullamento il solo biglietto
           lascerebbe la prenotazione annullata attorno a un ingresso avvenuto,
           che è uno stato che nessuno saprebbe leggere. */
        $checkedIn = fn ($query) => $query->where('status', AdmissionStatus::CheckedIn);
        $dates = Booking::query()->where('status', BookingStatus::Confirmed)
            ->whereNotNull('promotion_expires_at')->where('promotion_expires_at', '<=', now())
            ->whereDoesntHave('tickets', $checkedIn)
            ->select('occurrence_id')->distinct()->orderBy('occurrence_id')->pluck('occurrence_id');

        foreach ($dates as $id) {
            DB::transaction(function () use ($id, $checkedIn, &$expired): void {
                $date = EventOccurrence::withTrashed()->lockForUpdate()->find($id);
                $bookings = Booking::query()->where('occurrence_id', $id)->where('status', BookingStatus::Confirmed)
                    ->whereNotNull('promotion_expires_at')->where('promotion_expires_at', '<=', now())
                    ->whereDoesntHave('tickets', $checkedIn)->lockForUpdate()->get();
                foreach ($bookings as $booking) {
                    $booking->tickets()->where('status', '!=', AdmissionStatus::Cancelled)
                        ->update(['status' => AdmissionStatus::Cancelled->value, 'cancelled_at' => now()]);
                    $booking->update(['status' => BookingStatus::Cancelled, 'cancelled_at' => now(),
                        'promotion_expires_at' => null, 'cancellation_reason' => __('ticketing.errors.promotion_expired')]);
                    $this->notify($booking, 'promotion_expired');
                    $expired++;
                }
                if ($date !== null) {
                    $this->promote($date);
                }
            }, 3);
        }

        return $expired;
    }

    /**
     * Quanti gruppi ci sono davanti nella coda. È una posizione approssimata e
     * va raccontata come tale: dipende da quanti posti chiede chi sta davanti,
     * non solo da quante persone sono in fila.
     */
    public function queuePosition(Booking $booking): int
    {
        return Booking::query()->where('occurrence_id', $booking->occurrence_id)
            ->where('status', BookingStatus::Waitlisted)->where('id', '<', $booking->id)->count() + 1;
    }

    private function occupied(EventOccurrence $date): int
    {
        return AdmissionTicket::query()->whereHas('booking', fn ($q) => $q->where('occurrence_id', $date->id))
            ->whereIn('status', [AdmissionStatus::Valid, AdmissionStatus::CheckedIn])->count();
    }

    private function eventValid(EventOccurrence $date): bool
    {
        return ! $date->trashed() && $date->event?->status === EventStatus::Published
            && ! $date->effectiveVenue()?->status?->isProvvedimento()
            && in_array($date->status, [OccurrenceStatus::Scheduled, OccurrenceStatus::SoldOut, OccurrenceStatus::Moved], true);
    }

    /**
     * Se **adesso** si può prenotare questa data.
     *
     * È pubblica perché è la sola regola che decide: la usa `reserve()` per
     * autorizzare, e la scheda dell'evento per mostrare il pulsante. Quando la
     * scheda si accontentava dei due interruttori di configurazione —
     * `booking_enabled` e `ticketing_enabled`, che dicono se le prenotazioni
     * *esistono* — offriva «Prenota il tuo posto» anche su una data già
     * iniziata, e la pagina di prenotazione rispondeva che sono chiuse.
     */
    public function isOpen(EventOccurrence $date): bool
    {
        return $this->eventValid($date) && $date->status !== OccurrenceStatus::SoldOut
            && $date->booking_enabled && $date->effectiveVenue()?->ticketing_enabled
            && ($date->booking_opens_at === null || now()->gte($date->booking_opens_at))
            && now()->lt($date->booking_closes_at ?? $date->starts_at)
            && now()->lt($date->starts_at);
    }

    private function ensure(bool $condition, string $key): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['ticketing' => __('ticketing.errors.'.$key)]);
        }
    }

    private function revokeWallets(Booking $booking, bool $all = false): void
    {
        $wallet = app(GoogleWallet::class);
        if (! $wallet->configured()) {
            return;
        }
        foreach ($booking->tickets()->whereNotNull('wallet_requested_at')
            ->when(! $all, fn ($q) => $q->where('status', AdmissionStatus::Cancelled))->pluck('id') as $id) {
            DeactivateWalletPass::dispatch($wallet->objectId((int) $id));
        }
    }

    private function notify(Booking $booking, string $kind): void
    {
        $cityId = $booking->occurrence?->event?->city_id;
        if ($cityId !== null) {
            DB::afterCommit(fn () => ContentVersion::bump($cityId));
        }
        if (in_array($kind, ['cancelled', 'event_cancelled', 'promotion_expired'], true)) {
            $this->revokeWallets($booking);
        }
        $booking->user?->notify(new BookingChanged($booking->id, $kind));
    }

    private function audit(Booking $booking, string $action, User $actor): void
    {
        activity('ticketing')->causedBy($actor)->performedOn($booking)->log($action);
    }
}
