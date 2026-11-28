package it.fabiodalez.incitta.ui.editorial

import it.fabiodalez.incitta.data.editorial.CatalogKind
import it.fabiodalez.incitta.data.editorial.EditorialDestination
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.ui.editorial.analytics.AnalyticsReportRoute
import it.fabiodalez.incitta.ui.editorial.analytics.SearchMissesRoute
import it.fabiodalez.incitta.ui.editorial.analytics.SeoRoute
import it.fabiodalez.incitta.ui.editorial.calendar.CalendarRoute
import it.fabiodalez.incitta.ui.editorial.catalog.CatalogFormRoute
import it.fabiodalez.incitta.ui.editorial.catalog.CatalogListRoute
import it.fabiodalez.incitta.ui.editorial.community.CarpoolDeskRoute
import it.fabiodalez.incitta.ui.editorial.community.CommunityModerationRoute
import it.fabiodalez.incitta.ui.editorial.eventeditor.EditorialEventEditorRoute
import it.fabiodalez.incitta.ui.editorial.events.EditorialEventRoute
import it.fabiodalez.incitta.ui.editorial.events.EditorialSubmissionRoute
import it.fabiodalez.incitta.ui.editorial.events.EditorialSubmissionsRoute
import it.fabiodalez.incitta.ui.editorial.events.EventsRoute
import it.fabiodalez.incitta.ui.editorial.moderation.ModerationCommentsRoute
import it.fabiodalez.incitta.ui.editorial.moderation.ModerationReportRoute
import it.fabiodalez.incitta.ui.editorial.moderation.ModerationReportsRoute
import it.fabiodalez.incitta.ui.editorial.moderation.ModerationReviewsRoute
import it.fabiodalez.incitta.ui.editorial.promotion.BannersRoute
import it.fabiodalez.incitta.ui.editorial.promotion.CampaignDetailRoute
import it.fabiodalez.incitta.ui.editorial.promotion.CampaignListRoute
import it.fabiodalez.incitta.ui.editorial.promotion.CampaignWizardRoute
import it.fabiodalez.incitta.ui.editorial.promotion.GrantFormRoute
import it.fabiodalez.incitta.ui.editorial.promotion.GrantListRoute
import it.fabiodalez.incitta.ui.editorial.promotion.OrderListRoute
import it.fabiodalez.incitta.ui.editorial.promotion.PriceListRoute
import it.fabiodalez.incitta.ui.editorial.promotion.PromotionAnalyticsRoute
import it.fabiodalez.incitta.ui.editorial.settings.SettingsCacheRoute
import it.fabiodalez.incitta.ui.editorial.settings.SettingsCookiesRoute
import it.fabiodalez.incitta.ui.editorial.settings.SettingsMailRoute
import it.fabiodalez.incitta.ui.editorial.settings.SettingsNewsletterRoute
import it.fabiodalez.incitta.ui.editorial.settings.SettingsRedirectsRoute
import it.fabiodalez.incitta.ui.editorial.settings.SettingsScriptsRoute
import it.fabiodalez.incitta.ui.editorial.settings.SettingsTextsRoute
import it.fabiodalez.incitta.ui.editorial.social.EditorialSocialSettingsRoute
import it.fabiodalez.incitta.ui.editorial.social.EditorialSocialStudioRoute
import it.fabiodalez.incitta.ui.editorial.system.SystemImportFormRoute
import it.fabiodalez.incitta.ui.editorial.system.SystemImportRoute
import it.fabiodalez.incitta.ui.editorial.system.SystemImportsRoute
import it.fabiodalez.incitta.ui.editorial.system.SystemNotificationsRoute
import it.fabiodalez.incitta.ui.editorial.system.SystemOperationsRoute
import it.fabiodalez.incitta.ui.editorial.users.UserCreateRoute
import it.fabiodalez.incitta.ui.editorial.users.UserDetailRoute
import it.fabiodalez.incitta.ui.editorial.users.UsersRoute
import it.fabiodalez.incitta.ui.editorial.venues.ApplicationDetailRoute
import it.fabiodalez.incitta.ui.editorial.venues.ApplicationListRoute
import it.fabiodalez.incitta.ui.editorial.venues.OrganizerListRoute
import it.fabiodalez.incitta.ui.editorial.venues.VenueCreateRoute
import it.fabiodalez.incitta.ui.editorial.venues.VenueDetailRoute
import it.fabiodalez.incitta.ui.editorial.venues.VenueEditRoute
import it.fabiodalez.incitta.ui.editorial.venues.VenueListRoute

/*
 * R14 — dove porta dentro l'area un indirizzo del pannello
 * (`EditorialDestination`): la schermata nativa che fa la stessa cosa, con
 * lo stesso permesso che apre la voce nell'app.
 */

/** La schermata di un indirizzo del pannello. */
internal sealed interface EditorialRouteTarget {
    /** La prima pagina dell'area. */
    data object Home : EditorialRouteTarget
    /** Una destinazione di un grafo dell'area. */
    data class Native(val route: Any) : EditorialRouteTarget
    /**
     * Un evento o un locale che il pannello indica con lo slug: l'app chiede
     * l'id al server (`GET E/links/{type}/{slug}`) e poi apre `route(id)`.
     */
    data class Resolve(val type: String, val slug: String, val route: (Long) -> Any) : EditorialRouteTarget
}

/**
 * La schermata nativa di un indirizzo del pannello per chi ha questi
 * permessi; `null` se l'app non ne ha una o se la persona non apre quella
 * voce (allora resta dov'è: il pannello direbbe di no allo stesso modo).
 */
internal fun editorialRouteFor(destination: EditorialDestination, can: EditorialPermissions): EditorialRouteTarget? {
    fun native(allowed: Boolean, route: Any): EditorialRouteTarget? = if (allowed) EditorialRouteTarget.Native(route) else null
    val id = destination.recordId
    val record = destination.record
    val create = destination.action == "create"
    return when (destination.page) {
        "" -> EditorialRouteTarget.Home
        "calendario" -> native(EditorialArea.Calendar.allowedFor(can), CalendarRoute)
        "events" -> when {
            create -> native(can.eventsEdit, EditorialEventEditorRoute())
            record != null && can.eventsView -> EditorialRouteTarget.Resolve("events", record) { found ->
                if (destination.edit && can.eventsEdit) EditorialEventEditorRoute(found) else EditorialEventRoute(found)
            }
            record != null -> null
            else -> native(can.eventsView, EventsRoute)
        }
        "event-submissions" -> when {
            id != null -> native(can.eventsModerate, EditorialSubmissionRoute(id))
            record != null -> null
            else -> native(can.eventsModerate, EditorialSubmissionsRoute())
        }
        "venues" -> when {
            create -> native(can.venuesEdit, VenueCreateRoute)
            record != null && (can.venuesView || can.venuesEdit) -> EditorialRouteTarget.Resolve("venues", record) { found ->
                if (destination.edit && can.venuesEdit) VenueEditRoute(found) else VenueDetailRoute(found)
            }
            record != null -> null
            else -> native(can.venuesView || can.venuesEdit, VenueListRoute())
        }
        "venue-applications" -> when {
            id != null -> native(can.applicationsReview, ApplicationDetailRoute(id))
            record != null -> null
            else -> native(can.applicationsReview, ApplicationListRoute())
        }
        "organizers" -> native(can.organizersManage, OrganizerListRoute)
        "users" -> when {
            create -> native(can.usersManage, UserCreateRoute)
            id != null -> native(can.usersView, UserDetailRoute(id))
            record != null -> null
            else -> native(can.usersView, UsersRoute())
        }
        "reports" -> when {
            id != null -> native(can.reportsResolve, ModerationReportRoute(id))
            record != null -> null
            else -> native(can.reportsResolve, ModerationReportsRoute())
        }
        "catalog-reviews" -> native(can.reviewsModerate, ModerationReviewsRoute())
        "event-comments" -> native(can.commentsModerate, ModerationCommentsRoute())
        "community" -> native(can.communityManage, CommunityModerationRoute())
        "carpool" -> native(can.casesManage || can.carpoolManage, CarpoolDeskRoute)
        "sponsorships" -> when {
            create -> native(can.sponsorshipsManage, CampaignWizardRoute())
            id != null -> native(can.sponsorshipsManage, CampaignDetailRoute(id))
            record != null -> null
            else -> native(can.sponsorshipsManage, CampaignListRoute)
        }
        "sponsorship-orders" -> native(can.sponsorshipsManage, OrderListRoute)
        "sponsorship-grants" -> when {
            create -> native(can.sponsorshipsManage, GrantFormRoute(0))
            id != null -> native(can.sponsorshipsManage, GrantFormRoute(id))
            record != null -> null
            else -> native(can.sponsorshipsManage, GrantListRoute)
        }
        "sponsorship-prices" -> native(can.sponsorshipsManage, PriceListRoute)
        "sponsorship-banners" -> native(can.sponsorshipsManage, BannersRoute)
        "statistiche-sponsorizzazioni" -> native(can.sponsorshipsManage, PromotionAnalyticsRoute())
        "social" -> native(can.socialUse, EditorialSocialStudioRoute(event = destination.event ?: 0))
        "social-settings" -> native(can.socialUse, EditorialSocialSettingsRoute)
        "statistiche-condivisioni" -> {
            val type = destination.subjectType
            if (type != null && id != null) native(can.analyticsView, AnalyticsReportRoute(type = type, id = id))
            else native(can.analyticsView, AnalyticsReportRoute())
        }
        "seo-overview" -> native(can.analyticsView, SeoRoute)
        "ricerche-senza-risultati" -> native(can.searchMissesManage, SearchMissesRoute)
        "categories", "tags", "event-features", "cities", "pages" -> catalogTarget(destination, can)
        "import-sources" -> when {
            create -> native(can.importsManage, SystemImportFormRoute())
            id != null -> native(can.importsManage, SystemImportRoute(id))
            record != null -> null
            else -> native(can.importsManage, SystemImportsRoute())
        }
        "scheduled-notifications" -> native(can.notificationsView, SystemNotificationsRoute())
        "cron-e-programmazioni" -> native(can.systemView, SystemOperationsRoute)
        "testi-delle-email" -> native(can.systemManage, SettingsTextsRoute)
        "configurazione-posta" -> native(can.mailManage, SettingsMailRoute)
        "newsletter" -> native(can.mailManage, SettingsNewsletterRoute)
        "script-consenso" -> native(can.systemManage, SettingsScriptsRoute)
        "cookie" -> native(can.systemManage, SettingsCookiesRoute)
        "indirizzi-cambiati" -> native(can.pagesManage, SettingsRedirectsRoute)
        "cache-performance" -> native(can.systemManage, SettingsCacheRoute)
        else -> null
    }
}

/* Categorie, tag, caratteristiche e città si leggono da tutta la redazione e si scrivono con `catalog_manage`; le pagine con `pages_manage`. */
private fun catalogTarget(destination: EditorialDestination, can: EditorialPermissions): EditorialRouteTarget? {
    val kind = when (destination.page) {
        "categories" -> CatalogKind.Categories
        "tags" -> CatalogKind.Tags
        "event-features" -> CatalogKind.Features
        "cities" -> CatalogKind.Cities
        else -> CatalogKind.Pages
    }
    val write = if (kind == CatalogKind.Pages) can.pagesManage else can.catalogManage
    val read = if (kind == CatalogKind.Pages) can.pagesManage else EditorialArea.Catalog.allowedFor(can)
    val id = destination.recordId
    return when {
        destination.action == "create" -> if (write) EditorialRouteTarget.Native(CatalogFormRoute(kind.key)) else null
        id != null -> if (read) EditorialRouteTarget.Native(CatalogFormRoute(kind.key, id)) else null
        destination.record != null -> null
        else -> if (read) EditorialRouteTarget.Native(CatalogListRoute(kind.key, canCreate = write)) else null
    }
}

/** Il percorso del pannello di una destinazione (`admin/events/slug/edit`), per riaprirla nel browser. */
internal fun editorialDestinationPath(destination: EditorialDestination): String = buildString {
    append("admin")
    if (destination.page.isNotEmpty()) append('/').append(destination.page)
    destination.subjectType?.let { append('/').append(it) }
    destination.action?.let { append('/').append(it) }
    destination.record?.let { append('/').append(it) }
    if (destination.edit) append("/edit")
    destination.event?.let { append("?event=").append(it) }
}
