package it.fabiodalez.incitta.ui.editorial

import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.ui.editorial.analytics.AnalyticsRoute
import it.fabiodalez.incitta.ui.editorial.calendar.CalendarRoute
import it.fabiodalez.incitta.ui.editorial.catalog.CatalogRoute
import it.fabiodalez.incitta.ui.editorial.community.CommunityRoute
import it.fabiodalez.incitta.ui.editorial.dashboard.DashboardRoute
import it.fabiodalez.incitta.ui.editorial.eventeditor.EventEditorRoute
import it.fabiodalez.incitta.ui.editorial.events.EventsRoute
import it.fabiodalez.incitta.ui.editorial.moderation.ModerationRoute
import it.fabiodalez.incitta.ui.editorial.promotion.PromotionRoute
import it.fabiodalez.incitta.ui.editorial.settings.SettingsRoute
import it.fabiodalez.incitta.ui.editorial.social.SocialRoute
import it.fabiodalez.incitta.ui.editorial.system.SystemRoute
import it.fabiodalez.incitta.ui.editorial.users.UsersRoute
import it.fabiodalez.incitta.ui.editorial.venues.VenuesRoute

/**
 * Le aree della redazione, una per pacchetto del piano (R1…R14), nell'ordine
 * del menu del pannello.
 *
 * - `entry`: la prima destinazione del grafo del pacchetto. Finché il
 *   pacchetto non c'è è la schermata «in arrivo», che apre la stessa pagina
 *   del pannello web (`webPath`).
 * - `native`: lo mette a `true` il pacchetto che porta l'area. Nel menu
 *   un'area non ancora nativa dice «sul sito».
 * - `inMenu`: il modulo evento (R3) si apre dall'elenco degli eventi, non
 *   dal menu.
 * - `badges`: le chiavi di `desk.badges` che l'area somma accanto al nome.
 *
 * Il pacchetto che porta un'area tocca solo la sua riga e il suo grafo.
 */
internal enum class EditorialArea(
    val title: Int,
    val help: Int,
    val webPath: String,
    val entry: Any,
    val native: Boolean = false,
    val inMenu: Boolean = true,
    val badges: List<String> = emptyList(),
) {
    Dashboard(R.string.editorial_area_dashboard, R.string.editorial_area_dashboard_help, "admin", DashboardRoute, native = true),
    Events(R.string.editorial_area_events, R.string.editorial_area_events_help, "admin/events", EventsRoute, native = true, badges = listOf("events_pending", "event_submissions_pending")),
    EventEditor(R.string.editorial_area_event_editor, R.string.editorial_area_event_editor_help, "admin/events/create", EventEditorRoute, native = true, inMenu = false),
    Venues(R.string.editorial_area_venues, R.string.editorial_area_venues_help, "admin/venues", VenuesRoute, native = true, badges = listOf("venues_pending", "venue_applications_pending")),
    Users(R.string.editorial_area_users, R.string.editorial_area_users_help, "admin/users", UsersRoute(), native = true, badges = listOf("users_registrations")),
    Moderation(R.string.editorial_area_moderation, R.string.editorial_area_moderation_help, "admin/reports", ModerationRoute, native = true, badges = listOf("reports_open", "event_comments_hidden", "catalog_reviews_pending")),
    Community(R.string.editorial_area_community, R.string.editorial_area_community_help, "admin/community", CommunityRoute, native = true, badges = listOf("carpool_open")),
    Promotion(R.string.editorial_area_promotion, R.string.editorial_area_promotion_help, "admin/sponsorships", PromotionRoute, native = true, badges = listOf("sponsorship_orders_awaiting")),
    Social(R.string.editorial_area_social, R.string.editorial_area_social_help, "admin/social", SocialRoute, native = true),
    Analytics(R.string.editorial_area_analytics, R.string.editorial_area_analytics_help, "admin/statistiche-condivisioni", AnalyticsRoute, native = true),
    Catalog(R.string.editorial_area_catalog, R.string.editorial_area_catalog_help, "admin/categories", CatalogRoute, native = true),
    System(R.string.editorial_area_system, R.string.editorial_area_system_help, "admin/scheduled-notifications", SystemRoute, native = true, badges = listOf("scheduled_notifications_due")),
    Settings(R.string.editorial_area_settings, R.string.editorial_area_settings_help, "admin/cache-performance", SettingsRoute, native = true),
    Calendar(R.string.editorial_area_calendar, R.string.editorial_area_calendar_help, "admin/calendario", CalendarRoute);

    /**
     * Se la persona, secondo i `permissions` del banco, apre quest'area: la
     * stessa domanda che il pannello fa per mostrare la voce del menu.
     */
    fun allowedFor(can: EditorialPermissions): Boolean = when (this) {
        /* Il riepilogo e il calendario: tutta la redazione. */
        Dashboard -> true
        Events, Calendar -> can.eventsView
        EventEditor -> can.eventsEdit
        /* Locali (lettura), richieste e organizzatori stanno nella stessa area. */
        Venues -> can.any("venues_view", "venues_edit", "applications_review")
        Users -> can.usersView
        /* Segnalazioni, commenti, recensioni: l'area c'è se almeno una delle tre si vede. */
        Moderation -> can.any("reports_resolve", "comments_moderate", "reviews_moderate")
        Community -> can.any("community_manage", "cases_manage", "carpool_manage")
        Promotion -> can.sponsorshipsManage
        Social -> can.socialUse
        /* Le ricerche senza risultati sono del moderatore; statistiche e SEO di admin e super admin. */
        Analytics -> can.any("analytics_view", "search_misses_manage")
        /* Categorie, tag, città e caratteristiche si leggono da tutta la redazione; scriverli chiede `catalog_manage`. */
        Catalog -> can.eventsView || can.catalogManage
        System -> can.any("imports_manage", "notifications_view", "system_view")
        /* Testi, posta, newsletter, script, cookie e cache chiedono il ruolo; gli indirizzi cambiati `pages.manage`. */
        Settings -> can.any("system_manage", "mail_manage", "pages_manage")
    }

    /** La somma dei contatori dell'area; `null` se nessuno si vede. */
    fun badgeFor(desk: EditorialDesk): Int? {
        val values = badges.mapNotNull(desk::badge)
        return if (values.isEmpty()) null else values.sum()
    }
}

/** Le aree del menu: permesse e da menu, nell'ordine del pannello. */
internal fun editorialMenu(can: EditorialPermissions): List<EditorialArea> =
    EditorialArea.entries.filter { it.inMenu && it.allowedFor(can) }
