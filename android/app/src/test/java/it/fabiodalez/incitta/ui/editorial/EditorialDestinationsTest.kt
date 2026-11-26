package it.fabiodalez.incitta.ui.editorial

import it.fabiodalez.incitta.data.editorial.CatalogKind
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialDestination
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.notifications.notificationLinkAllowed
import it.fabiodalez.incitta.notifications.notificationProofMatches
import it.fabiodalez.incitta.ui.editorial.analytics.AnalyticsReportRoute
import it.fabiodalez.incitta.ui.editorial.calendar.CalendarRoute
import it.fabiodalez.incitta.ui.editorial.catalog.CatalogFormRoute
import it.fabiodalez.incitta.ui.editorial.catalog.CatalogListRoute
import it.fabiodalez.incitta.ui.editorial.eventeditor.EditorialEventEditorRoute
import it.fabiodalez.incitta.ui.editorial.events.EditorialEventRoute
import it.fabiodalez.incitta.ui.editorial.events.EditorialSubmissionRoute
import it.fabiodalez.incitta.ui.editorial.events.eventLinkRoutes
import it.fabiodalez.incitta.ui.editorial.home.EditorialShortcut
import it.fabiodalez.incitta.ui.editorial.home.editorialShortcuts
import it.fabiodalez.incitta.ui.editorial.promotion.CampaignListRoute
import it.fabiodalez.incitta.ui.editorial.settings.SettingsMailRoute
import it.fabiodalez.incitta.ui.editorial.settings.SettingsNewsletterRoute
import it.fabiodalez.incitta.ui.editorial.social.EditorialSocialStudioRoute
import it.fabiodalez.incitta.ui.editorial.system.SystemImportRoute
import it.fabiodalez.incitta.ui.editorial.users.UserDetailRoute
import it.fabiodalez.incitta.ui.editorial.venues.VenueDetailRoute
import it.fabiodalez.incitta.ui.editorial.venues.VenueEditRoute
import org.junit.Assert.*
import org.junit.Test

/*
 * R14 — la chiusura dei collegamenti: gli indirizzi del pannello
 * (`/admin/…`) che l'app riceve si aprono nella schermata nativa, con lo
 * stesso permesso; gli altri restano al browser.
 */
class EditorialDestinationsTest {
    private val origin = "https://eventi.test/api/v1/"
    private val admin = EditorialPermissions(allKeys.associateWith { it != "mail_manage" })
    private val superAdmin = EditorialPermissions(allKeys.associateWith { true })
    private val moderator = EditorialPermissions(listOf("events_view", "events_publish", "events_moderate", "venues_view", "venues_moderate", "applications_review",
        "users_view", "reports_resolve", "comments_moderate", "community_manage", "cases_manage", "carpool_manage", "search_misses_manage", "notifications_view").associateWith { true })

    private fun parse(url: String) = EditorialDestination.parse(url, origin)
    private fun route(url: String, can: EditorialPermissions = admin) = parse(url)?.let { editorialRouteFor(it, can) }

    @Test fun onlyPagesOfThePanelOfThisSiteAreRead() {
        assertEquals(EditorialDestination(), parse("https://eventi.test/admin"))
        assertEquals(EditorialDestination("sponsorships"), parse("https://eventi.test/admin/sponsorships"))
        assertEquals(EditorialDestination("events", record = "concerto-in-corte", edit = true), parse("https://eventi.test/admin/events/concerto-in-corte/edit"))
        assertEquals(EditorialDestination("events", action = "create"), parse("https://eventi.test/admin/events/create"))
        assertEquals(EditorialDestination("social", event = 41), parse("https://eventi.test/admin/social?event=41"))
        assertEquals(EditorialDestination("statistiche-condivisioni", record = "7", subjectType = "venue"), parse("https://eventi.test/admin/statistiche-condivisioni/venue/7"))
        /* Un altro sito, un'altra porta, credenziali nell'indirizzo, una pagina che l'app non ha, una forma strana: il browser. */
        assertNull(parse("https://altro.test/admin/sponsorships"))
        assertNull(parse("http://eventi.test/admin/sponsorships"))
        assertNull(parse("https://eventi.test:8443/admin/sponsorships"))
        assertNull(parse("https://chi@eventi.test/admin/sponsorships"))
        assertNull(parse("https://eventi.test/admin/impersonate/7"))
        assertNull(parse("https://eventi.test/admin/events/a/b/c"))
        assertNull(parse("https://eventi.test/admin/events//edit"))
        assertNull(parse("https://eventi.test/gestione/teatro/eventi"))
        assertNull(parse("incitta://gestione/venue/1/home"))
        /* `?event=` vale solo come numero. */
        assertNull(parse("https://eventi.test/admin/social?event=uno")?.event)
        /* I percorsi senza sito dei `web_path` del server. */
        assertEquals(EditorialDestination("users", record = "7", edit = true), EditorialDestination.fromPath("admin/users/7/edit"))
        assertEquals(EditorialDestination("import-sources"), EditorialDestination.fromPath("admin/import-sources?filters%5Bfailed%5D%5BisActive%5D=1"))
    }

    @Test fun theMorningAlertOfTheBellOpensTheNativeCampaigns() {
        assertEquals(EditorialRouteTarget.Native(CampaignListRoute), route("https://eventi.test/admin/sponsorships"))
        /* Il moderatore non gestisce la promozione: resta sulla prima pagina, come il pannello che gli direbbe di no. */
        assertNull(route("https://eventi.test/admin/sponsorships", moderator))
        assertEquals(EditorialRouteTarget.Home, route("https://eventi.test/admin", moderator))
    }

    @Test fun eventsAndVenuesWithTheSlugAreResolvedThenOpened() {
        val event = route("https://eventi.test/admin/events/concerto-in-corte/edit") as EditorialRouteTarget.Resolve
        assertEquals("events", event.type)
        assertEquals("concerto-in-corte", event.slug)
        assertEquals(EditorialEventEditorRoute(41), event.route(41))
        /* Il moderatore non modifica: la scheda in sola lettura. */
        assertEquals(EditorialEventRoute(41), (route("https://eventi.test/admin/events/concerto-in-corte/edit", moderator) as EditorialRouteTarget.Resolve).route(41))
        assertEquals(EditorialEventRoute(41), (route("https://eventi.test/admin/events/concerto-in-corte") as EditorialRouteTarget.Resolve).route(41))
        val venue = route("https://eventi.test/admin/venues/teatro-verdi/edit") as EditorialRouteTarget.Resolve
        assertEquals(VenueEditRoute(5), venue.route(5))
        assertEquals(VenueDetailRoute(5), (route("https://eventi.test/admin/venues/teatro-verdi/edit", moderator) as EditorialRouteTarget.Resolve).route(5))
    }

    @Test fun theOtherPagesOpenTheirScreenWithTheirPermission() {
        assertEquals(EditorialRouteTarget.Native(UserDetailRoute(7)), route("https://eventi.test/admin/users/7/edit", moderator))
        assertNull(route("https://eventi.test/admin/users/create", moderator))
        assertEquals(EditorialRouteTarget.Native(EditorialSubmissionRoute(3)), route("https://eventi.test/admin/event-submissions/3/edit", moderator))
        assertEquals(EditorialRouteTarget.Native(CalendarRoute), route("https://eventi.test/admin/calendario", moderator))
        assertEquals(EditorialRouteTarget.Native(EditorialSocialStudioRoute(event = 41)), route("https://eventi.test/admin/social?event=41"))
        assertNull(route("https://eventi.test/admin/social", moderator))
        assertEquals(EditorialRouteTarget.Native(AnalyticsReportRoute(type = "venue", id = 7)), route("https://eventi.test/admin/statistiche-condivisioni/venue/7"))
        assertEquals(EditorialRouteTarget.Native(SystemImportRoute(9)), route("https://eventi.test/admin/import-sources/9/edit"))
        assertNull(route("https://eventi.test/admin/import-sources/9/edit", moderator))
        /* Il catalogo si legge da tutti; scriverlo (e creare) chiede `catalog_manage`. */
        assertEquals(EditorialRouteTarget.Native(CatalogListRoute(CatalogKind.Tags.key, canCreate = false)), route("https://eventi.test/admin/tags", moderator))
        assertEquals(EditorialRouteTarget.Native(CatalogListRoute(CatalogKind.Tags.key, canCreate = true)), route("https://eventi.test/admin/tags"))
        assertNull(route("https://eventi.test/admin/tags/create", moderator))
        assertEquals(EditorialRouteTarget.Native(CatalogFormRoute(CatalogKind.Cities.key, 2)), route("https://eventi.test/admin/cities/2/edit"))
        /* Posta e newsletter solo al super admin, come nel pannello; «Impostazioni newsletter» del profilo arriva qui. */
        assertNull(route("https://eventi.test/admin/newsletter"))
        assertEquals(EditorialRouteTarget.Native(SettingsNewsletterRoute), route("https://eventi.test/admin/newsletter", superAdmin))
        assertEquals(EditorialRouteTarget.Native(SettingsMailRoute), route("https://eventi.test/admin/configurazione-posta", superAdmin))
        /* Un id dove il pannello vuole un numero, se non è un numero: nessuna schermata. */
        assertNull(route("https://eventi.test/admin/users/mario/edit"))
    }

    @Test fun everyPageOfTheListHasAScreenForTheSuperAdmin() {
        for (page in EditorialDestination.PAGES) {
            assertNotNull(page, editorialRouteFor(EditorialDestination(page), superAdmin))
        }
    }

    @Test fun thePathOfADestinationReopensTheSamePageInTheBrowser() {
        assertEquals("admin/events/concerto/edit", editorialDestinationPath(EditorialDestination("events", record = "concerto", edit = true)))
        assertEquals("admin/social?event=41", editorialDestinationPath(EditorialDestination("social", event = 41)))
        assertEquals("admin/statistiche-condivisioni/venue/7", editorialDestinationPath(EditorialDestination("statistiche-condivisioni", record = "7", subjectType = "venue")))
        assertEquals("admin", editorialDestinationPath(EditorialDestination()))
        /* Gli indirizzi interi del sito diventano percorsi; quelli di un altro sito restano com'erano (e non si traducono). */
        assertEquals("/admin/sponsorships", editorialPanelPath("https://eventi.test/admin/sponsorships", origin))
        assertEquals("/admin/users/7/edit", editorialPanelPath("admin/users/7/edit", origin))
        assertEquals("https://altro.test/admin", editorialPanelPath("https://altro.test/admin", origin))
        assertNull(EditorialDestination.fromPath(editorialPanelPath("https://altro.test/admin", origin)))
    }

    @Test fun theSocialGraphicsOfTheEventRecordOpenTheNativeStudio() {
        assertEquals(EditorialSocialStudioRoute(event = 41), eventLinkRoutes(admin).social?.invoke(41))
        assertNull(eventLinkRoutes(moderator).social)
        assertNull(eventLinkRoutes(null).social)
    }

    @Test fun theShortcutsAreThoseOfTheTopBarOfThePanel() {
        val adminDesk = EditorialDesk(role = EditorialDesk.ROLE_ADMIN, permissions = allKeys.associateWith { it != "mail_manage" })
        assertEquals(EditorialShortcut.entries.toList(), editorialShortcuts(adminDesk))
        /* Il moderatore: niente nuovo evento, statistiche, biglietti né social; i commenti e il calendario sì. */
        val moderatorDesk = EditorialDesk(role = EditorialDesk.ROLE_MODERATOR, permissions = listOf("events_view", "comments_moderate").associateWith { true })
        assertEquals(listOf(EditorialShortcut.Events, EditorialShortcut.Comments, EditorialShortcut.Calendar, EditorialShortcut.Site), editorialShortcuts(moderatorDesk))
    }

    @Test fun aPanelAddressOpensInTheAreaOnlyFromANotificationOfThisAppForThisPerson() {
        val secret = { "segreto" }
        assertTrue(notificationProofMatches(7, "segreto", 7, secret))
        assertFalse(notificationProofMatches(null, "segreto", 7, secret))
        assertFalse(notificationProofMatches(7, null, 7, secret))
        assertFalse(notificationProofMatches(7, "sbagliato", 7, secret))
        assertFalse(notificationProofMatches(8, "segreto", 7, secret))
        assertFalse(notificationProofMatches(7, "segreto", null, secret))
        /* La regola di prima per gli indirizzi interni della gestione non cambia. */
        assertTrue(notificationLinkAllowed("https://eventi.test/eventi/concerto", null, null, 7, secret))
        assertFalse(notificationLinkAllowed("incitta://gestione/venue/1/home", null, null, 7, secret))
        assertTrue(notificationLinkAllowed("incitta://gestione/venue/1/home", 7, "segreto", 7, secret))
    }
}
