<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Catalogo dei permessi granulari gestiti da `spatie/laravel-permission`.
 * Ogni caso è il nome esatto salvato nella tabella `permissions`: le Policy
 * e il seeder leggono sempre da qui, mai da una stringa scritta a mano.
 */
enum Permission: string
{
    case ViewVenues = 'venues.view';
    case CreateVenues = 'venues.create';
    case UpdateVenues = 'venues.update';
    case DeleteVenues = 'venues.delete';
    case ManageVenueCollaborators = 'venues.manage_collaborators';
    case ViewVenueSensitiveData = 'venues.view_sensitive_data';
    case ModerateVenues = 'venues.moderate';

    case ViewEvents = 'events.view';
    case CreateEvents = 'events.create';
    case UpdateEvents = 'events.update';
    case DeleteEvents = 'events.delete';
    case PublishEvents = 'events.publish';
    case ModerateEvents = 'events.moderate';

    case ViewVenueApplications = 'venue_applications.view';
    case ReviewVenueApplications = 'venue_applications.review';

    case ViewReports = 'reports.view';
    case ResolveReports = 'reports.resolve';

    case ManageCategories = 'categories.manage';
    case ManageTags = 'tags.manage';
    case ApproveTags = 'tags.approve';
    case ManageCities = 'cities.manage';

    case ManageImportSources = 'import_sources.manage';

    case ManagePages = 'pages.manage';

    /*
     * Le campagne sponsorizzate. Non e' un permesso editoriale: chi lo ha
     * decide cosa compare a pagamento e con quale priorita', e quella e' una
     * scelta commerciale. Per questo il moderatore, che cura i contenuti
     * altrui, non ce l'ha.
     */
    case ManageSponsorships = 'sponsorships.manage';

    case ViewScheduledNotifications = 'notifications.view';
    case ManageScheduledNotifications = 'notifications.manage';

    case ManageUsers = 'users.manage';
    case ImpersonateUsers = 'users.impersonate';

    public function label(): string
    {
        return __('enums.permission.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
