package it.fabiodalez.incitta.ui.editorial.dashboard

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.editorial.DashboardTarget
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.ui.editorial.allKeys
import it.fabiodalez.incitta.ui.editorial.common.EditorialListTarget
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.encodeEditorialFilterParam
import it.fabiodalez.incitta.ui.editorial.common.parseEditorialFilterParam
import it.fabiodalez.incitta.ui.editorial.common.takeEditorialListTarget
import it.fabiodalez.incitta.ui.editorial.events.EditorialEventsListRoute
import it.fabiodalez.incitta.ui.editorial.events.acceptEventsLists
import it.fabiodalez.incitta.ui.editorial.moderation.ModerationCommentsRoute
import it.fabiodalez.incitta.ui.editorial.moderation.ModerationReportsRoute
import it.fabiodalez.incitta.ui.editorial.moderation.ModerationReviewsRoute
import it.fabiodalez.incitta.ui.editorial.moderation.acceptModerationLists
import it.fabiodalez.incitta.ui.editorial.users.UsersRoute
import it.fabiodalez.incitta.ui.editorial.users.acceptUsersLists
import it.fabiodalez.incitta.ui.editorial.venues.ApplicationListRoute
import it.fabiodalez.incitta.ui.editorial.venues.VenueListRoute
import it.fabiodalez.incitta.ui.editorial.venues.acceptVenuesLists
import it.fabiodalez.incitta.ui.editorial.venues.applicationListRoute
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/*
 * Dove porta ogni riquadro del riepilogo: le destinazioni sono quelle che il
 * server manda oggi (`DeskSummary`, `DeskSummaryPayload`), ognuna verso
 * l'elenco nativo con la stessa scheda e gli stessi filtri del pannello,
 * oppure, dove l'elenco non è nell'app, verso la pagina del pannello.
 */
class DashboardTargetsTest {
    /* I grafi delle aree dichiarano i loro elenchi quando il NavHost si compone: qui lo faccio a mano. */
    @Before fun lists() {
        acceptEventsLists()
        acceptVenuesLists()
        acceptUsersLists()
        acceptModerationLists()
    }

    private fun can(vararg keys: String) = EditorialPermissions(keys.associateWith { true })
    private val admin = EditorialPermissions(allKeys.associateWith { it != "mail_manage" })
    /* I permessi del moderatore secondo il server (EditorialDeskTest). */
    private val moderator = can("events_view", "events_publish", "events_moderate", "venues_view", "venues_moderate", "applications_review", "users_view",
        "reports_resolve", "comments_moderate", "community_manage", "cases_manage", "carpool_manage", "search_misses_manage", "notifications_view")

    /** Una destinazione come la scrive `DeskTarget::toArray()`. */
    private fun target(area: String, filters: Map<String, String> = emptyMap(), tab: String? = null, web: String = "admin/$area") =
        DashboardTarget(area = area, tab = tab, filters = JsonObject(filters.mapValues { JsonPrimitive(it.value) }), webPath = web)

    /* Ogni riquadro del riepilogo con un elenco dietro, e la rotta nativa che deve aprire. */
    private val tiles: Map<String, Pair<DashboardTarget, Any>> = mapOf(
        "pending_events" to (target("events", mapOf("status" to "pending")) to EditorialEventsListRoute(filter = "status:pending")),
        "pending_venues" to (target("venues", mapOf("status" to "pending")) to VenueListRoute(filter = "status:pending")),
        /* Le richieste non hanno un filtro di stato nel pannello: lo stato è la scheda. */
        "pending_applications" to (target("applications", mapOf("status" to "pending"), web = "admin/venue-applications") to ApplicationListRoute(tab = "pending")),
        "open_reports" to (target("reports", mapOf("open" to "1")) to ModerationReportsRoute(filter = "open:1")),
        "published_today" to (target("events", mapOf("published_today" to "1")) to EditorialEventsListRoute(filter = "published_today:1")),
        "published_this_week" to (target("events", mapOf("published_this_week" to "1")) to EditorialEventsListRoute(filter = "published_this_week:1")),
        "cancelled_events" to (target("events", mapOf("status" to "cancelled")) to EditorialEventsListRoute(filter = "status:cancelled")),
        "missing_poster" to (target("events", mapOf("missing_poster" to "1")) to EditorialEventsListRoute(filter = "missing_poster:1")),
        "incomplete" to (target("events", mapOf("incomplete" to "1")) to EditorialEventsListRoute(filter = "incomplete:1")),
        "possible_duplicates" to (target("events", mapOf("possible_duplicates" to "1")) to EditorialEventsListRoute(filter = "possible_duplicates:1")),
        "inactive_venues" to (target("venues", mapOf("inactive" to "1")) to VenueListRoute(filter = "inactive:1")),
        /* «Nuove iscrizioni»: «Solo in attesa» e «Tutti gli utenti». */
        "users_pending" to (target("users", tab = "pending") to UsersRoute(tab = "pending")),
        "users_all" to (target("users") to UsersRoute()),
    )

    @Test fun everyTileOpensTheNativeListWithTheTabAndFiltersOfThePanel() {
        for ((key, tile) in tiles) {
            val (target, route) = tile
            assertEquals(key, DashboardDestination.Native(route), dashboardDestination(target, admin))
        }
        /* Il moderatore apre gli stessi elenchi: tutti e cinque li vede anche nel pannello. */
        for ((key, tile) in tiles) {
            assertEquals(key, DashboardDestination.Native(tile.second), dashboardDestination(tile.first, moderator))
        }
    }

    @Test fun theFailedImportsStayOnThePanelUntilTheSystemAreaIsInTheApp() {
        val failed = target("import_sources", mapOf("failed" to "1"), web = "admin/import-sources?filters%5Bfailed%5D%5BisActive%5D=1")
        assertEquals(DashboardDestination.Web("admin/import-sources?filters%5Bfailed%5D%5BisActive%5D=1"), dashboardDestination(failed, admin))
    }

    @Test fun withoutThePermissionOfTheListTheTileOpensThePanel() {
        /* Il moderatore non ha le recensioni: l'area Moderazione sì, l'elenco no. */
        val reviews = target("reviews", mapOf("status" to "pending"), web = "admin/catalog-reviews")
        assertEquals(DashboardDestination.Web("admin/catalog-reviews"), dashboardDestination(reviews, moderator))
        assertEquals(DashboardDestination.Native(ModerationReviewsRoute(filter = "status:pending")), dashboardDestination(reviews, admin))
        /* Chi ha solo le richieste apre l'area Locali ma non l'elenco dei locali. */
        val onlyApplications = can("applications_review")
        assertEquals(DashboardDestination.Web("admin/venues"), dashboardDestination(target("venues", mapOf("status" to "pending")), onlyApplications))
        assertEquals(DashboardDestination.Native(ApplicationListRoute(tab = "pending")), dashboardDestination(tiles.getValue("pending_applications").first, onlyApplications))
        /* Senza il banco (ancora in arrivo) si apre il pannello. */
        assertEquals(DashboardDestination.Web("admin/events"), dashboardDestination(target("events", mapOf("status" to "pending")), null))
    }

    @Test fun aListThatIsNotDeclaredFallsBackToThePanelAndAnUnknownOneToNothing() {
        val pending = target("events", mapOf("status" to "pending"), web = "admin/events?filters%5Bstatus%5D%5Bvalue%5D=pending")
        assertEquals(DashboardDestination.Web(pending.webPath), dashboardDestination(pending, admin, routeFor = { _, _ -> null }))
        assertNull(dashboardDestination(DashboardTarget(area = "sconosciuta"), admin))
        assertEquals(DashboardDestination.Web("admin/x"), dashboardDestination(DashboardTarget(area = "sconosciuta", webPath = "admin/x"), admin))
    }

    @Test fun commentsAreDeclaredToo() {
        assertEquals(
            DashboardDestination.Native(ModerationCommentsRoute(filter = "status:hidden")),
            dashboardDestination(target("comments", mapOf("status" to "hidden")), moderator),
        )
    }

    @Test fun theApplicationStatusBecomesTheTabAndTheOtherFiltersStay() {
        assertEquals(ApplicationListRoute(tab = "pending", filter = "kind:claim"), applicationListRoute(EditorialListTarget("applications", null, mapOf("status" to "pending", "kind" to "claim"))))
        /* Una scheda esplicita vince sul filtro. */
        assertEquals(ApplicationListRoute(tab = "all"), applicationListRoute(EditorialListTarget("applications", "all", mapOf("status" to "pending"))))
        assertEquals(ApplicationListRoute(), applicationListRoute(EditorialListTarget("applications")))
    }

    @Test fun theRouteArgumentsReachTheListOnceAndOnTopOfItsDefaults() {
        val handle = SavedStateHandle(mapOf("tab" to "", "filter" to "status:pending"))
        val target = handle.takeEditorialListTarget("events")
        assertEquals(EditorialListTarget("events", null, mapOf("status" to "pending")), target)
        /* Una volta sola: dopo valgono i criteri che la persona ha scelto, anche se il sistema riapre l'app. */
        assertNull(handle.takeEditorialListTarget("events"))
        val query = target!!.applyTo(ListQuery(tab = "all", filters = mapOf("city" to "padova")))
        assertEquals("all", query.tab)
        assertEquals(mapOf("city" to "padova", "status" to "pending"), query.filters)
        assertTrue(query.params(1).contains("filter[status]" to "pending"))
        /* Dal menu, senza argomenti, non c'è destinazione. */
        assertNull(SavedStateHandle(mapOf("tab" to "", "filter" to "")).takeEditorialListTarget("users"))
        assertNull(SavedStateHandle().takeEditorialListTarget("users"))
        assertEquals(EditorialListTarget("users", "pending"), SavedStateHandle(mapOf("tab" to "pending")).takeEditorialListTarget("users"))
    }

    @Test fun theFilterArgumentIsSortedAndReadsBack() {
        val filters = mapOf("status" to "pending", "missing_poster" to "1", "empty" to "")
        assertEquals("missing_poster:1,status:pending", encodeEditorialFilterParam(filters))
        assertEquals(mapOf("missing_poster" to "1", "status" to "pending"), parseEditorialFilterParam(encodeEditorialFilterParam(filters)))
        assertEquals(mapOf("status" to "pending", "missing_poster" to "1"), parseEditorialFilterParam("status:pending, missing_poster:1,broken,:x"))
        assertTrue(parseEditorialFilterParam(null).isEmpty())
        assertEquals("", EditorialListTarget("users").filterParam)
    }
}
