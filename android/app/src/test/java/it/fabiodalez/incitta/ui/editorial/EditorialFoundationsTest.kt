package it.fabiodalez.incitta.ui.editorial

import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.ReasonProblem
import it.fabiodalez.incitta.ui.editorial.common.ReasonRules
import it.fabiodalez.incitta.ui.editorial.common.RowAction
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.editorial.common.toggleFilter
import it.fabiodalez.incitta.ui.editorial.common.visibleActions
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import org.junit.Assert.*
import org.junit.Test

/* Le parti pure delle fondamenta della redazione: menu delle aree, elenchi paginati, motivo, azioni di riga, filtri. */
@OptIn(ExperimentalCoroutinesApi::class)
class EditorialFoundationsTest {
    private fun can(vararg keys: String) = EditorialPermissions(keys.associateWith { true })

    /* I permessi che il server dà a ogni ruolo (EditorialDeskTest, lato server). */
    private val moderator = can("events_view", "events_publish", "events_moderate", "venues_view", "venues_moderate", "applications_review", "users_view",
        "reports_resolve", "comments_moderate", "community_manage", "cases_manage", "carpool_manage", "search_misses_manage", "notifications_view")
    private val admin = EditorialPermissions(it.fabiodalez.incitta.ui.editorial.allKeys.associateWith { it != "mail_manage" })

    @Test fun everyPackageHasItsAreaAndTheMenuFollowsThePermissions() {
        assertEquals(14, EditorialArea.entries.size)
        assertEquals(EditorialArea.entries.map { it.entry }.toSet().size, EditorialArea.entries.size)
        /* Il moderatore: niente promozione, social, configurazione; il modulo evento non è nel menu. */
        val forModerator = editorialMenu(moderator)
        assertTrue(EditorialArea.Events in forModerator)
        assertTrue(EditorialArea.Moderation in forModerator)
        assertTrue(EditorialArea.Analytics in forModerator) // le ricerche senza risultati
        assertFalse(EditorialArea.Promotion in forModerator)
        assertFalse(EditorialArea.Social in forModerator)
        assertFalse(EditorialArea.Settings in forModerator)
        assertFalse(EditorialArea.EventEditor in forModerator)
        assertFalse(EditorialArea.EventEditor.allowedFor(moderator))
        /* L'admin vede tutto quello che è nel menu. */
        assertEquals(EditorialArea.entries.filter { it.inMenu }, editorialMenu(admin))
        /* Senza permessi resta il riepilogo. */
        assertEquals(listOf(EditorialArea.Dashboard), editorialMenu(EditorialPermissions()))
        /* Ogni area ha una pagina del pannello su cui ripiegare. */
        assertTrue(EditorialArea.entries.all { it.webPath.startsWith("admin") })
    }

    @Test fun everyPermissionKeyHasItsPropertyAndNoPropertyIsLeftOver() {
        assertEquals(35, allKeys.size)
        assertEquals(allKeys.size, allKeys.toSet().size)
        val getters = EditorialPermissions::class.java.declaredMethods
            .filter { it.name.startsWith("get") && it.parameterCount == 0 && it.returnType == Boolean::class.javaPrimitiveType }
        assertEquals(allKeys.size, getters.size)
        for (key in allKeys) {
            /* `events_view` → `getEventsView`: una sola proprietà dice sì, ed è quella con il nome della chiave. */
            val expected = "get" + key.split('_').joinToString("") { part -> part.replaceFirstChar { it.uppercase() } }
            val yes = getters.filter { it.invoke(EditorialPermissions(mapOf(key to true))) == true }.map { it.name }
            assertEquals(key, listOf(expected), yes)
        }
    }

    @Test fun theNativeAreasOfTheTwoWavesOpenTheirOwnScreens() {
        val native = EditorialArea.entries.filter { it.native }
        assertEquals(
            listOf(
                EditorialArea.Dashboard, EditorialArea.Events, EditorialArea.EventEditor, EditorialArea.Venues, EditorialArea.Users, EditorialArea.Moderation,
                EditorialArea.Community, EditorialArea.Promotion, EditorialArea.Analytics, EditorialArea.Catalog,
            ),
            native,
        )
        /* Restano sul sito finché i loro pacchetti non arrivano: social (R9), sistema (R12), impostazioni (R13), calendario (R14). */
        assertEquals(listOf(EditorialArea.Social, EditorialArea.System, EditorialArea.Settings, EditorialArea.Calendar), EditorialArea.entries.filterNot { it.native })
        assertEquals(it.fabiodalez.incitta.ui.editorial.dashboard.DashboardRoute, EditorialArea.Dashboard.entry)
        assertEquals(it.fabiodalez.incitta.ui.editorial.events.EventsRoute, EditorialArea.Events.entry)
        assertEquals(it.fabiodalez.incitta.ui.editorial.eventeditor.EventEditorRoute, EditorialArea.EventEditor.entry)
        assertEquals(it.fabiodalez.incitta.ui.editorial.venues.VenuesRoute, EditorialArea.Venues.entry)
        /* Dal menu l'elenco degli utenti parte senza scheda né filtri: «Tutti». */
        assertEquals(it.fabiodalez.incitta.ui.editorial.users.UsersRoute(), EditorialArea.Users.entry)
        assertEquals(it.fabiodalez.incitta.ui.editorial.moderation.ModerationRoute, EditorialArea.Moderation.entry)
        assertEquals(it.fabiodalez.incitta.ui.editorial.community.CommunityRoute, EditorialArea.Community.entry)
        assertEquals(it.fabiodalez.incitta.ui.editorial.promotion.PromotionRoute, EditorialArea.Promotion.entry)
        assertEquals(it.fabiodalez.incitta.ui.editorial.analytics.AnalyticsRoute, EditorialArea.Analytics.entry)
        assertEquals(it.fabiodalez.incitta.ui.editorial.catalog.CatalogRoute, EditorialArea.Catalog.entry)
        /* Il modulo evento si apre dall'elenco e dalla scheda, non dal menu. */
        assertFalse(EditorialArea.EventEditor.inMenu)
        /* I contatori del menu: le stesse chiavi che le voci dentro l'area mostrano. */
        assertEquals(listOf("venues_pending", "venue_applications_pending"), EditorialArea.Venues.badges)
        assertEquals(it.fabiodalez.incitta.ui.editorial.moderation.ModerationSection.entries.map { it.badge }.toSet(), EditorialArea.Moderation.badges.toSet())
        assertEquals(listOf("users_registrations"), EditorialArea.Users.badges)
        assertEquals(listOf("events_pending", "event_submissions_pending"), EditorialArea.Events.badges)
        assertEquals(it.fabiodalez.incitta.ui.editorial.community.CommunityPage.entries.mapNotNull { it.badge }, EditorialArea.Community.badges)
        assertEquals(listOf("sponsorship_orders_awaiting"), EditorialArea.Promotion.badges)
    }

    @Test fun everyCounterOfTheDeskBelongsToExactlyOneArea() {
        /* EditorialBadges::KEYS lato server, nell'ordine del contratto. */
        val serverBadges = listOf(
            "events_pending", "event_submissions_pending", "venues_pending", "venue_applications_pending", "users_registrations",
            "reports_open", "event_comments_hidden", "catalog_reviews_pending", "carpool_open", "sponsorship_orders_awaiting", "scheduled_notifications_due",
        )
        val inAreas = EditorialArea.entries.flatMap { it.badges }
        assertEquals(inAreas.size, inAreas.toSet().size)
        assertEquals(serverBadges.toSet(), inAreas.toSet())
    }

    @Test fun theMenuOfTheModeratorIsExactlyThePanelOne() {
        /* Niente promozione né social; nelle statistiche solo le ricerche senza risultati, nel sistema solo gli invii programmati, il catalogo in lettura. */
        assertEquals(
            listOf(EditorialArea.Dashboard, EditorialArea.Events, EditorialArea.Venues, EditorialArea.Users, EditorialArea.Moderation, EditorialArea.Community,
                EditorialArea.Analytics, EditorialArea.Catalog, EditorialArea.System, EditorialArea.Calendar),
            editorialMenu(moderator),
        )
    }

    @Test fun theBadgeOfAnAreaSumsOnlyWhatTheServerShows() {
        val desk = Json.decodeFromString<EditorialDesk>("""{"badges":{"events_pending":3,"event_submissions_pending":2,"reports_open":0,"event_comments_hidden":1,"catalog_reviews_pending":null}}""")
        assertEquals(5, EditorialArea.Events.badgeFor(desk))
        assertEquals(1, EditorialArea.Moderation.badgeFor(desk))
        assertNull(EditorialArea.Promotion.badgeFor(desk))
        assertNull(EditorialArea.Social.badgeFor(desk))
    }

    @Test fun theWebFallbackPointsAtThePanelOfTheSameSite() {
        assertEquals("https://eventi.example.test/admin/events", editorialWebUrl("admin/events", "https://eventi.example.test/api/v1/"))
        assertEquals("https://eventi.example.test/admin", editorialWebUrl("/admin", "https://eventi.example.test/api/v1/"))
    }

    @Test fun listQueriesFollowTheConvention() {
        val query = ListQuery(q = "  jazz ", tab = "pending", filters = mapOf("venue" to "12", "city" to "padova", "empty" to ""), sort = "-starts_at", perPage = 80)
        assertEquals(
            listOf("page" to "2", "per_page" to "50", "q" to "jazz", "tab" to "pending", "filter[city]" to "padova", "filter[venue]" to "12", "sort" to "-starts_at"),
            query.params(2),
        )
        assertEquals(listOf("page" to "1", "per_page" to "20"), ListQuery().params(0))
        assertEquals(2, query.activeFilters)
    }

    @Test fun theListLoadsPageAfterPageAndRestartsWhenTheCriteriaChange() = runTest {
        val scope = TestScope(testScheduler)
        val requests = mutableListOf<Pair<ListQuery, Int>>()
        val controller = PagedListController(scope) { query, page ->
            requests += query to page
            EditorialPage(List(2) { "${query.tab ?: "all"}-$page-$it" }, PageMeta(currentPage = page, lastPage = 2, total = 4, tabs = JsonObject(mapOf("pending" to JsonPrimitive(4)))))
        }
        controller.refresh(); scope.runCurrent()
        assertEquals(listOf("all-1-0", "all-1-1"), controller.current.value.items)
        assertTrue(controller.current.value.hasMore)
        assertEquals(4, controller.current.value.tabCounts["pending"])

        controller.loadMore(); scope.runCurrent()
        assertEquals(4, controller.current.value.items.size)
        assertFalse(controller.current.value.hasMore)
        /* Alla fine non chiede altro. */
        controller.loadMore(); scope.runCurrent()
        assertEquals(2, requests.size)

        controller.selectTab("pending"); scope.runCurrent()
        assertEquals(listOf("pending-1-0", "pending-1-1"), controller.current.value.items)
        assertEquals(1, requests.last().second)
        controller.search("jazz"); scope.runCurrent()
        assertEquals("jazz", requests.last().first.q)
        assertEquals("pending", requests.last().first.tab)
    }

    @Test fun aNewCriterionWinsOverAnAnswerThatArrivesLate() = runTest {
        val scope = TestScope(testScheduler)
        val slow = CompletableDeferred<EditorialPage<String>>()
        val controller = PagedListController(scope) { query, _ -> if (query.q == "vecchia") slow.await() else EditorialPage(listOf("nuova")) }
        controller.search("vecchia"); scope.runCurrent()
        controller.search("nuova"); scope.runCurrent()
        slow.complete(EditorialPage(listOf("vecchia")))
        scope.runCurrent()
        assertEquals(listOf("nuova"), controller.current.value.items)
    }

    @Test fun aFailedPageShowsTheErrorAndRetriesThatPage() = runTest {
        val scope = TestScope(testScheduler)
        var fail = true
        val pages = mutableListOf<Int>()
        val controller = PagedListController(scope) { _, page ->
            pages += page
            if (page == 2 && fail) throw ApiException(500, ApiProblem(message = "Errore"))
            EditorialPage(listOf("riga-$page"), PageMeta(currentPage = page, lastPage = 3))
        }
        controller.refresh(); scope.runCurrent()
        controller.loadMore(); scope.runCurrent()
        assertNotNull(controller.current.value.error)
        assertEquals(listOf("riga-1"), controller.current.value.items)
        fail = false
        controller.retry(); scope.runCurrent()
        assertNull(controller.current.value.error)
        assertEquals(listOf("riga-1", "riga-2"), controller.current.value.items)
        assertEquals(listOf(1, 2, 2), pages)
        /* Dopo un'azione la riga si sostituisce o si toglie senza ricaricare. */
        controller.replace({ it == "riga-1" }, "riga-1-pubblicata")
        controller.remove { it == "riga-2" }
        assertEquals(listOf("riga-1-pubblicata"), controller.current.value.items)
    }

    @Test fun anEmptyListIsEmptyOnlyAfterTheFirstPage() = runTest {
        val scope = TestScope(testScheduler)
        val controller = PagedListController(scope) { _, _ -> EditorialPage<String>() }
        assertFalse(controller.current.value.isEmpty)
        controller.refresh(); scope.runCurrent()
        assertTrue(controller.current.value.isEmpty)
    }

    @Test fun theReasonFollowsThePanelLimits() {
        val rules = ReasonRules()
        assertEquals(ReasonProblem.TooShort, rules.problem("   ok  "))
        assertNull(rules.problem("Doppione"))
        assertEquals(ReasonProblem.TooLong, rules.problem("a".repeat(1001)))
        assertTrue(rules.accepts("a".repeat(1000)))
        assertFalse(ReasonRules(min = 10).accepts("Doppione"))
    }

    @Test fun rowActionsAreOnlyTheOnesTheServerAllows() {
        val abilities = abilitiesOf(Json.parseToJsonElement("""{"publish":true,"reject":1,"feature":false,"delete":"0","merge":null}"""))
        val actions = listOf(RowAction("publish", "Pubblica"), RowAction("feature", "In evidenza"), RowAction("reject", "Rifiuta", destructive = true), RowAction("merge", "Unisci"))
        assertEquals(listOf("publish", "reject"), visibleActions(actions, abilities).map { it.key })
        /* PHP manda una mappa vuota come `[]`: nessuna azione. */
        assertTrue(abilitiesOf(Json.parseToJsonElement("[]")).isEmpty())
        assertTrue(visibleActions(actions, emptyMap()).isEmpty())
    }

    @Test fun aFilterIsChosenAndUnchosenByTappingIt() {
        val chosen = toggleFilter(emptyMap(), "status", "pending")
        assertEquals(mapOf("status" to "pending"), chosen)
        assertEquals(mapOf("status" to "published"), toggleFilter(chosen, "status", "published"))
        assertEquals(emptyMap<String, String>(), toggleFilter(chosen, "status", "pending"))
        assertEquals("Sì", OptionChoice("1", "Sì").label)
    }
}

/* Tutte le chiavi di `permissions` del contratto, nell'ordine di EditorialPermissions::KEYS lato server (35, con `organizers_manage` di R4). */
internal val allKeys = listOf(
    "events_view", "events_edit", "events_publish", "events_moderate", "events_feature", "submissions_publish",
    "venues_view", "venues_edit", "venues_moderate", "venues_members", "applications_review",
    "users_view", "users_manage", "users_roles",
    "reports_resolve", "reviews_moderate", "comments_moderate", "comments_delete",
    "community_manage", "cases_manage", "carpool_manage", "messages_read",
    "sponsorships_manage", "social_use", "analytics_view", "search_misses_manage",
    "catalog_manage", "pages_manage", "imports_manage", "notifications_view", "notifications_cancel",
    "system_view", "system_manage", "mail_manage", "organizers_manage",
)
