<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Follow;
use App\Models\ImportSource;
use App\Models\Report;
use App\Models\SavedEvent;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Observers\EventObserver;
use App\Observers\EventOccurrenceObserver;
use App\Policies\CategoryPolicy;
use App\Policies\CityPolicy;
use App\Policies\EventOccurrencePolicy;
use App\Policies\EventPolicy;
use App\Policies\FollowPolicy;
use App\Policies\ImportSourcePolicy;
use App\Policies\ReportPolicy;
use App\Policies\SavedEventPolicy;
use App\Policies\TagPolicy;
use App\Policies\VenueApplicationPolicy;
use App\Policies\VenuePolicy;
use App\Services\Geo\GeoQueryInterface;
use App\Services\Geo\MariaDbGeoQuery;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Le query geospaziali passano tutte da qui: cambiare motore di
        // database costa questa riga più una implementazione dell'interfaccia.
        $this->app->bind(GeoQueryInterface::class, MariaDbGeoQuery::class);
    }

    public function boot(): void
    {
        // Le colonne morph (`follows.followable_type`, `reports.reportable_type`,
        // `scheduled_notifications.notifiable_type`) contengono alias brevi e non
        // nomi di classe: i dati non devono dipendere dal namespace PHP.
        Relation::enforceMorphMap([
            'city' => City::class,
            'venue' => Venue::class,
            'category' => Category::class,
            'tag' => Tag::class,
            'event' => Event::class,
            'event_occurrence' => EventOccurrence::class,
            'user' => User::class,
        ]);

        // `business_date` ed `effective_ends_at` sono calcolate dall'observer a
        // ogni salvataggio; cambiare categoria o città a un evento ricalcola le
        // occorrenze già salvate.
        EventOccurrence::observe(EventOccurrenceObserver::class);
        Event::observe(EventObserver::class);

        Gate::policy(Venue::class, VenuePolicy::class);
        Gate::policy(Event::class, EventPolicy::class);
        Gate::policy(EventOccurrence::class, EventOccurrencePolicy::class);
        Gate::policy(VenueApplication::class, VenueApplicationPolicy::class);
        Gate::policy(Report::class, ReportPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Tag::class, TagPolicy::class);
        Gate::policy(City::class, CityPolicy::class);
        Gate::policy(ImportSource::class, ImportSourcePolicy::class);
        Gate::policy(SavedEvent::class, SavedEventPolicy::class);
        Gate::policy(Follow::class, FollowPolicy::class);
    }
}
