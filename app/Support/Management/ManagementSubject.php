<?php

declare(strict_types=1);

namespace App\Support\Management;

use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Su che cosa sta lavorando chi gestisce: un locale o un'organizzazione, con
 * la persona e il suo ruolo.
 *
 * Fino all'app di gestione la risposta la dava solo Filament
 * (`Filament::getTenant()`), e i servizi delle statistiche e delle
 * sponsorizzazioni la chiedevano a lui: fuori dai pannelli non funzionavano.
 * Adesso la domanda si fa qui, e la risposta arriva da una delle due strade:
 *
 * - nell'API la lega alla richiesta `ResolveManagementSubject`, dopo aver
 *   controllato `User::canAccessTenant` (la stessa barriera dei pannelli);
 * - nei pannelli la si ricava dal tenant e dal pannello correnti, come prima.
 *
 * Il soggetto sta negli attributi della richiesta e non nel container: un
 * oggetto nel container sopravvive alla richiesta (nei test, a quelle dopo),
 * e il soggetto di una chiamata finirebbe a rispondere per la successiva.
 *
 * Dall'API non chiamo mai `Filament::setTenant()`: accenderebbe gli scope di
 * tenancy dei pannelli su richieste che non li aspettano.
 */
final class ManagementSubject
{
    public function __construct(
        public readonly Venue|Organizer $model,
        public readonly User $user,
    ) {}

    /**
     * Il soggetto della richiesta in corso: quello legato dall'API, altrimenti
     * quello del pannello. `null` fuori da entrambi (sito pubblico,
     * amministrazione, comandi).
     */
    public static function current(): ?self
    {
        $request = app()->bound('request') ? app('request') : null;
        $bound = $request instanceof Request ? $request->attributes->get(self::class) : null;

        return $bound instanceof self ? $bound : self::fromPanel();
    }

    /** Il soggetto della richiesta, o 404: per i punti che senza non hanno senso. */
    public static function require(): self
    {
        $subject = self::current();
        abort_if($subject === null, 404);

        return $subject;
    }

    public static function bind(Request $request, self $subject): void
    {
        $request->attributes->set(self::class, $subject);
    }

    /** `venue` oppure `organizer`: lo stesso nome dei due pannelli. */
    public function type(): string
    {
        return $this->model instanceof Venue ? 'venue' : 'organizer';
    }

    public function isVenue(): bool
    {
        return $this->model instanceof Venue;
    }

    public function isOrganizer(): bool
    {
        return $this->model instanceof Organizer;
    }

    public function venue(): ?Venue
    {
        return $this->model instanceof Venue ? $this->model : null;
    }

    public function organizer(): ?Organizer
    {
        return $this->model instanceof Organizer ? $this->model : null;
    }

    /**
     * Il ruolo della persona: `owner` o `editor` nel locale (la riga di
     * `venue_user`), `owner` o `member` nell'organizzazione (`owner_id` contro
     * i membri di `organizer_user`).
     */
    public function role(): string
    {
        if ($this->model instanceof Venue) {
            return $this->user->isOwnerOfVenue((int) $this->model->getKey()) ? 'owner' : 'editor';
        }

        return (int) $this->model->owner_id === (int) $this->user->getKey() ? 'owner' : 'member';
    }

    public function isOwner(): bool
    {
        return $this->role() === 'owner';
    }

    /**
     * Nei pannelli il soggetto è il tenant, ma solo nel pannello giusto: un
     * locale come tenant del pannello dell'organizzatore non esiste, e
     * nell'amministrazione non c'è tenant.
     */
    private static function fromPanel(): ?self
    {
        $panel = Filament::getCurrentPanel()?->getId();
        $tenant = Filament::getTenant();
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return match (true) {
            $panel === 'venue' && $tenant instanceof Venue,
            $panel === 'organizer' && $tenant instanceof Organizer => new self($tenant, $user),
            default => null,
        };
    }
}
