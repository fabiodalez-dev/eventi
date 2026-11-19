<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Admin\Pages as AdminPages;
use App\Filament\Admin\Resources\CatalogReviews\CatalogReviewResource as AdminCatalogReviews;
use App\Filament\Admin\Resources\Categories\CategoryResource;
use App\Filament\Admin\Resources\Cities\CityResource;
use App\Filament\Admin\Resources\ConsentScripts\ConsentScriptResource;
use App\Filament\Admin\Resources\CookieDeclarations\CookieDeclarationResource;
use App\Filament\Admin\Resources\EventComments\EventCommentResource as AdminEventComments;
use App\Filament\Admin\Resources\EventFeatures\EventFeatureResource;
use App\Filament\Admin\Resources\Events\EventResource as AdminEvents;
use App\Filament\Admin\Resources\EventSubmissions\EventSubmissionResource;
use App\Filament\Admin\Resources\ImportSources\ImportSourceResource;
use App\Filament\Admin\Resources\Organizers\OrganizerResource;
use App\Filament\Admin\Resources\Pages\PageResource;
use App\Filament\Admin\Resources\Redirects\RedirectResource;
use App\Filament\Admin\Resources\Reports\ReportResource;
use App\Filament\Admin\Resources\ScheduledNotifications\ScheduledNotificationResource;
use App\Filament\Admin\Resources\SearchMisses\SearchMissResource;
use App\Filament\Admin\Resources\SponsorshipGrants\SponsorshipGrantResource;
use App\Filament\Admin\Resources\SponsorshipOrders\SponsorshipOrderResource;
use App\Filament\Admin\Resources\SponsorshipPrices\SponsorshipPriceResource;
use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use App\Filament\Admin\Resources\Tags\TagResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Filament\Organizer\Pages as OrganizerPages;
use App\Filament\Organizer\Resources\CatalogReviews\CatalogReviewResource as OrganizerCatalogReviews;
use App\Filament\Organizer\Resources\EventComments\EventCommentResource as OrganizerEventComments;
use App\Filament\Organizer\Resources\Events\EventResource as OrganizerEvents;
use App\Filament\Venue\Pages as VenuePages;
use App\Filament\Venue\Resources\CatalogReviews\CatalogReviewResource as VenueCatalogReviews;
use App\Filament\Venue\Resources\EventComments\EventCommentResource as VenueEventComments;
use App\Filament\Venue\Resources\Events\EventResource as VenueEvents;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Resources\Resource as PanelResource;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

/**
 * Il menu laterale dei tre pannelli, in un file solo.
 *
 * **Cosa decide questo file e cosa no.** Qui stanno l'ordine, i gruppi e le
 * icone. Il nome di ogni voce no: lo dà la pagina con `getNavigationLabel()`,
 * così il menu, la barra in alto, il titolo e i riquadri della dashboard
 * dicono la stessa parola. Anche indirizzo, numero accanto alla voce e stato
 * attivo arrivano dalla pagina (`getNavigationItems()`), e una voce compare
 * solo se chi guarda può aprirla (`shouldRegisterNavigation()` e
 * `canAccess()`, come fa Filament da sé).
 *
 * **Una pagina nuova va aggiunta qui.** Il menu non si costruisce più da solo
 * dalle classi: una pagina dimenticata non comparirebbe. La guardia è in
 * `PanelNavigationTest`, che fallisce se una pagina registrata nel pannello
 * con la sua voce di menu manca da queste mappe.
 *
 * Gli stessi gruppi hanno lo stesso nome, lettera per lettera, nei tre
 * pannelli; dentro un pannello nessuna icona si ripete.
 */
final class PanelNavigation
{
    /** La voce «Biglietti e partecipanti», che non è una pagina del pannello. */
    public const TICKETS = 'tickets';

    /** I gruppi chiusi all'apertura: si toccano raramente. */
    private const COLLAPSED = ['catalog', 'system'];

    public static function admin(NavigationBuilder $builder): NavigationBuilder
    {
        return self::build('admin', $builder);
    }

    public static function venue(NavigationBuilder $builder): NavigationBuilder
    {
        return self::build('venue', $builder);
    }

    public static function organizer(NavigationBuilder $builder): NavigationBuilder
    {
        return self::build('organizer', $builder);
    }

    /**
     * Le voci del menu, gruppo per gruppo (`''` è la voce sciolta in cima),
     * con l'icona di ognuna.
     *
     * @return array<string, array<string, Heroicon>>
     */
    public static function map(string $panel): array
    {
        return match ($panel) {
            'admin' => [
                '' => [AdminPages\Dashboard::class => Heroicon::OutlinedHome],
                'events' => [
                    AdminEvents::class => Heroicon::OutlinedCalendarDays,
                    EventSubmissionResource::class => Heroicon::OutlinedInboxArrowDown,
                    AdminPages\EventsCalendar::class => Heroicon::OutlinedCalendar,
                ],
                'places_people' => [
                    VenueResource::class => Heroicon::OutlinedBuildingStorefront,
                    VenueApplicationResource::class => Heroicon::OutlinedEnvelopeOpen,
                    OrganizerResource::class => Heroicon::OutlinedUserGroup,
                    UserResource::class => Heroicon::OutlinedUsers,
                ],
                'moderation' => [
                    ReportResource::class => Heroicon::OutlinedFlag,
                    AdminCatalogReviews::class => Heroicon::OutlinedStar,
                    AdminEventComments::class => Heroicon::OutlinedChatBubbleLeftRight,
                    AdminPages\Community::class => Heroicon::OutlinedIdentification,
                    AdminPages\Carpool::class => Heroicon::OutlinedTruck,
                ],
                'promotion' => [
                    SponsorshipResource::class => Heroicon::OutlinedMegaphone,
                    SponsorshipOrderResource::class => Heroicon::OutlinedShoppingCart,
                    SponsorshipGrantResource::class => Heroicon::OutlinedCreditCard,
                    AdminPages\SponsorshipBanners::class => Heroicon::OutlinedRectangleGroup,
                    SponsorshipPriceResource::class => Heroicon::OutlinedCurrencyEuro,
                    AdminPages\SponsorshipAnalytics::class => Heroicon::OutlinedPresentationChartLine,
                    AdminPages\Social::class => Heroicon::OutlinedPhoto,
                ],
                'statistics' => [
                    AdminPages\EventShareAnalytics::class => Heroicon::OutlinedChartBar,
                    AdminPages\SeoOverview::class => Heroicon::OutlinedClipboardDocumentCheck,
                    SearchMissResource::class => Heroicon::OutlinedMagnifyingGlassMinus,
                ],
                'catalog' => [
                    CategoryResource::class => Heroicon::OutlinedSquares2x2,
                    TagResource::class => Heroicon::OutlinedTag,
                    EventFeatureResource::class => Heroicon::OutlinedInformationCircle,
                    CityResource::class => Heroicon::OutlinedMapPin,
                    PageResource::class => Heroicon::OutlinedDocumentText,
                ],
                'system' => [
                    ImportSourceResource::class => Heroicon::OutlinedArrowDownTray,
                    ScheduledNotificationResource::class => Heroicon::OutlinedBellAlert,
                    AdminPages\ScheduledOperations::class => Heroicon::OutlinedClock,
                    AdminPages\NotificationTexts::class => Heroicon::OutlinedPencilSquare,
                    AdminPages\MailConfiguration::class => Heroicon::OutlinedEnvelope,
                    AdminPages\Newsletter::class => Heroicon::OutlinedNewspaper,
                    ConsentScriptResource::class => Heroicon::OutlinedCodeBracket,
                    CookieDeclarationResource::class => Heroicon::OutlinedShieldCheck,
                    RedirectResource::class => Heroicon::OutlinedArrowUturnRight,
                    AdminPages\CachePerformance::class => Heroicon::OutlinedBolt,
                ],
            ],
            'venue' => [
                '' => [VenuePages\Dashboard::class => Heroicon::OutlinedHome],
                'events' => [
                    VenueEvents::class => Heroicon::OutlinedCalendarDays,
                    self::TICKETS => Heroicon::OutlinedTicket,
                    VenuePages\CalendarImport::class => Heroicon::OutlinedArrowPath,
                    VenuePages\Social::class => Heroicon::OutlinedPhoto,
                    VenuePages\Sponsorships::class => Heroicon::OutlinedMegaphone,
                ],
                'statistics' => [
                    VenuePages\Statistics::class => Heroicon::OutlinedChartBar,
                    VenuePages\EventShareAnalytics::class => Heroicon::OutlinedPresentationChartLine,
                    VenuePages\SponsorshipAnalytics::class => Heroicon::OutlinedChartPie,
                ],
                'audience' => [
                    VenueEventComments::class => Heroicon::OutlinedChatBubbleLeftRight,
                    VenueCatalogReviews::class => Heroicon::OutlinedStar,
                ],
                'venue' => [
                    VenuePages\VenueProfile::class => Heroicon::OutlinedBuildingStorefront,
                    VenuePages\Collaborators::class => Heroicon::OutlinedUserGroup,
                    VenuePages\VenueAccess::class => Heroicon::OutlinedKey,
                ],
            ],
            'organizer' => [
                '' => [OrganizerPages\Dashboard::class => Heroicon::OutlinedHome],
                'events' => [
                    OrganizerEvents::class => Heroicon::OutlinedCalendarDays,
                    OrganizerPages\Sponsorships::class => Heroicon::OutlinedMegaphone,
                ],
                'statistics' => [
                    OrganizerPages\Statistics::class => Heroicon::OutlinedChartBar,
                    OrganizerPages\EventShareAnalytics::class => Heroicon::OutlinedPresentationChartLine,
                    OrganizerPages\SponsorshipAnalytics::class => Heroicon::OutlinedChartPie,
                ],
                'audience' => [
                    OrganizerEventComments::class => Heroicon::OutlinedChatBubbleLeftRight,
                    OrganizerCatalogReviews::class => Heroicon::OutlinedStar,
                ],
                'organizer' => [
                    OrganizerPages\Profile::class => Heroicon::OutlinedUserGroup,
                ],
            ],
            default => [],
        };
    }

    /**
     * Tutte le pagine e le risorse che hanno un posto nel menu di un pannello.
     *
     * @return list<class-string>
     */
    public static function classes(string $panel): array
    {
        return collect(self::map($panel))
            ->flatMap(fn (array $items): array => array_keys($items))
            ->reject(fn (string $key): bool => $key === self::TICKETS)
            ->values()
            ->all();
    }

    /**
     * L'icona di una pagina, la stessa del menu: serve a chi disegna una
     * scorciatoia verso quella pagina (i riquadri della dashboard).
     */
    public static function icon(string $class): Heroicon
    {
        foreach (['admin', 'venue', 'organizer'] as $panel) {
            foreach (self::map($panel) as $items) {
                if (isset($items[$class])) {
                    return $items[$class];
                }
            }
        }

        return Heroicon::OutlinedSquares2x2;
    }

    /**
     * Il menu della persona, uguale nei tre pannelli: in cima nome ed email
     * (la vista `user-menu-identity`), poi «Area personale», «Tema chiaro o
     * scuro», «Esci». I dati della persona si cambiano sul sito, in un posto
     * solo: nei pannelli non c'è una pagina del profilo.
     *
     * @return array<string, Action|\Closure>
     */
    public static function userMenu(): array
    {
        return [
            'profile' => Action::make('profile')
                ->label(fn (): string => __('pannelli-impianto.user_menu.account'))
                ->icon(Heroicon::OutlinedUserCircle)
                ->url(fn (): string => route('account.profile'))
                ->sort(1),
            /*
             * Il tema si cambia da qui, senza uscire dal pannello: la scelta
             * si salva nel profilo, come da «Aspetto» del sito, e vale per
             * sito e pannelli. «Come il sistema» resta nella pagina del sito.
             */
            'appearance' => Action::make('appearance')
                ->label(fn (): string => __(self::isDark() ? 'pannelli-impianto.user_menu.to_light' : 'pannelli-impianto.user_menu.to_dark'))
                ->icon(fn (): Heroicon => self::isDark() ? Heroicon::OutlinedSun : Heroicon::OutlinedMoon)
                ->action(function (Component $livewire): void {
                    $user = auth()->user();

                    if ($user instanceof User) {
                        $user->forceFill(['appearance' => self::isDark() ? 'light' : 'dark'])->save();
                    }

                    $livewire->redirect(request()->header('Referer') ?: Filament::getUrl());
                })
                ->sort(2),
            'logout' => fn (Action $action): Action => $action->label(__('pannelli-impianto.user_menu.logout')),
        ];
    }

    /**
     * Il tema che chi è dentro vede adesso: la scelta del profilo, poi quella
     * del browser (cookie), come in `filament.partials.theme-bootstrap`. Con
     * «come il sistema» non so cosa vede: propongo lo scuro.
     */
    private static function isDark(): bool
    {
        $saved = auth()->user()?->appearance;

        if (in_array($saved, ['light', 'dark'], true)) {
            return $saved === 'dark';
        }

        return request()->cookie('incitta_appearance') === 'dark';
    }

    private static function build(string $panel, NavigationBuilder $builder): NavigationBuilder
    {
        /*
         * Filament chiede il menu più volte per pagina (se c'è, com'è fatto,
         * per disegnarlo): ogni volta rifarebbe i controlli di accesso e le
         * query dei numeri accanto alle voci. Si costruisce una volta per
         * richiesta, persona e locale.
         */
        /*
         * Indirizzi e controlli d'accesso delle voci dipendono dal pannello
         * attivo e dal suo locale o organizzatore: il menu di un pannello che
         * non è quello attivo (nei test capita, con più richieste nello stesso
         * processo) non si vede, e costruirlo farebbe rispondere 403 o 500.
         */
        if (Filament::getCurrentPanel()?->getId() !== $panel) {
            return $builder;
        }

        $request = request();
        $key = 'panel-navigation.'.$panel.'.'.(Filament::getTenant()?->getKey() ?? '-').'.'.(Filament::auth()->id() ?? '-');

        if ($request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        $sort = 0;

        foreach (self::map($panel) as $group => $entries) {
            $items = [];

            foreach ($entries as $entry => $icon) {
                foreach (self::items($panel, $entry) as $item) {
                    $items[] = $item->icon($icon)->activeIcon($icon)->parentItem(null)->group(null)->sort(++$sort);
                }
            }

            if ($items === []) {
                continue;
            }

            if ($group === '') {
                $builder->items($items);

                continue;
            }

            $builder->group(NavigationGroup::make(__('pannelli-impianto.groups.'.$group))
                ->items($items)
                ->collapsed(in_array($group, self::COLLAPSED, true)));
        }

        $request->attributes->set($key, $builder);

        return $builder;
    }

    /** @return list<NavigationItem> */
    private static function items(string $panel, string $entry): array
    {
        if ($entry === self::TICKETS) {
            return self::ownsCurrentVenue()
                ? [NavigationItem::make(__('pannelli-impianto.tickets'))->url(route('ticketing.manage.index'))]
                : [];
        }

        if (! class_exists($entry) || ! self::shouldShow($panel, $entry)) {
            return [];
        }

        /** @var class-string<Page>|class-string<PanelResource> $entry */
        return array_values($entry::getNavigationItems());
    }

    /**
     * Una voce compare se la pagina vuole stare nel menu e chi guarda può
     * aprirla. «Il tuo accesso» del locale fa eccezione: la pagina non si
     * registra da sé (stava nel menu della persona), ma la sua voce ora è
     * l'ultima del menu laterale.
     *
     * @param  class-string  $class
     */
    private static function shouldShow(string $panel, string $class): bool
    {
        $forced = $panel === 'venue' && $class === VenuePages\VenueAccess::class;

        if (! $forced && method_exists($class, 'shouldRegisterNavigation') && ! $class::shouldRegisterNavigation()) {
            return false;
        }

        return method_exists($class, 'canAccess') && $class::canAccess();
    }

    /** «Biglietti e partecipanti» lo vede il referente del locale aperto, come prima. */
    private static function ownsCurrentVenue(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->ownedVenues()->exists();
    }
}
