<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Models\City;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\User;
use App\Models\Venue;
use App\Support\Management\ManagementSubject;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le campagne che chi guarda le statistiche puo' vedere.
 *
 * Per un locale: le campagne sugli eventi del locale, nate da una sua
 * abilitazione o dalla redazione. Per un'organizzazione: lo stesso per gli
 * eventi che firma. Il soggetto è quello di `ManagementSubject`, quindi vale
 * uguale nei pannelli e nell'API di gestione. Nell'amministrazione, dove un
 * soggetto non c'è: tutte, solo per chi ha il ruolo.
 */
final class SponsorshipReport
{
    /** @return Builder<Sponsorship> */
    public function campaigns(): Builder
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $query = Sponsorship::withTrashed();
        $subject = ManagementSubject::current();

        if ($subject !== null) {
            abort_unless($subject->user->canAccessTenant($subject->model), 403);

            return $this->ofSponsor($query, $subject->model);
        }

        abort_unless($user->hasAnyRole(['admin', 'super_admin']), 403);

        return $query;
    }

    /**
     * Le campagne di un committente: sugli eventi suoi, e solo quelle pagate
     * con una sua abilitazione o messe dalla redazione. Una campagna comprata
     * da un altro committente sullo stesso evento resta sua.
     *
     * @param  Builder<Sponsorship>  $query
     * @return Builder<Sponsorship>
     */
    public function ofSponsor(Builder $query, Venue|Organizer $sponsor): Builder
    {
        $column = $sponsor instanceof Venue ? 'venue_id' : 'organizer_id';
        $id = $sponsor->getKey();

        return $query->whereHas('event', fn ($q) => $q->where($column, $id))
            ->where(fn ($q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn ($g) => $g->where($column, $id)));
    }

    /**
     * Il fuso in cui leggere i giorni: quello della citta' del committente
     * nei pannelli (i contatori giornalieri si scrivono nel fuso della citta'
     * della campagna), quello dell'applicazione per la redazione, che vede
     * piu' citta' insieme.
     */
    public function timezone(): string
    {
        $tenant = ManagementSubject::current()?->model;
        $city = $tenant instanceof Venue || $tenant instanceof Organizer ? $tenant->city : null;
        $timezone = $city instanceof City ? $city->timezone : null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Rome';
    }
}
