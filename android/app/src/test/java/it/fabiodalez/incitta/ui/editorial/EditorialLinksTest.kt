package it.fabiodalez.incitta.ui.editorial

import it.fabiodalez.incitta.data.editorial.CatalogKind
import it.fabiodalez.incitta.data.editorial.CommunitySection
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.ui.editorial.analytics.AnalyticsReportRoute
import it.fabiodalez.incitta.ui.editorial.catalog.CatalogFormRoute
import it.fabiodalez.incitta.ui.editorial.catalog.catalogPickedKey
import it.fabiodalez.incitta.ui.editorial.community.CommunityModerationRoute
import it.fabiodalez.incitta.ui.editorial.community.communityRouteForReport
import it.fabiodalez.incitta.ui.editorial.events.eventLinkRoutes
import it.fabiodalez.incitta.ui.editorial.promotion.CampaignWizardRoute
import org.junit.Assert.*
import org.junit.Test

/*
 * I collegamenti fra le aree della seconda ondata: dalla scheda evento alle
 * statistiche (R10) e a «Sponsorizza» (R8), da una segnalazione alla
 * community (R7), dal modulo evento al modulo di una caratteristica del
 * catalogo (R11). Nativi dove l'area c'è e la persona la apre; altrimenti la
 * pagina del pannello.
 */
class EditorialLinksTest {
    private fun can(vararg keys: String) = EditorialPermissions(keys.associateWith { true })
    private val admin = EditorialPermissions(allKeys.associateWith { it != "mail_manage" })
    private val moderator = can("events_view", "events_publish", "events_moderate", "venues_view", "venues_moderate", "applications_review", "users_view",
        "reports_resolve", "comments_moderate", "community_manage", "cases_manage", "carpool_manage", "search_misses_manage", "notifications_view")

    @Test fun theEventRecordOpensStatisticsAndTheCampaignWizardInTheApp() {
        val links = eventLinkRoutes(admin)
        assertEquals(AnalyticsReportRoute(type = "event", id = 41), links.statistics?.invoke(41))
        /* «Sponsorizza questo evento»: il primo passo con l'evento già scelto. */
        assertEquals(CampaignWizardRoute(event = 41), links.sponsor?.invoke(41))
    }

    @Test fun withoutThePermissionTheLinkStaysOnThePanelPageTheServerSends() {
        /* Il moderatore non vede statistiche né promozione: il server non manda i collegamenti e l'app non li apre. */
        val links = eventLinkRoutes(moderator)
        assertNull(links.statistics)
        assertNull(links.sponsor)
        /* Ognuno con il suo permesso. */
        assertNotNull(eventLinkRoutes(can("analytics_view")).statistics)
        assertNull(eventLinkRoutes(can("analytics_view")).sponsor)
        assertNotNull(eventLinkRoutes(can("sponsorships_manage")).sponsor)
        /* Senza il banco ancora caricato: il pannello. */
        assertNull(eventLinkRoutes(null).statistics)
        assertNull(eventLinkRoutes(null).sponsor)
    }

    @Test fun moderatingTheSubjectOfAReportOpensTheCommunityOnTheRightTab() {
        assertEquals(CommunityModerationRoute(CommunitySection.PROFILES), communityRouteForReport("community_profile"))
        assertEquals(CommunityModerationRoute(CommunitySection.POSTS), communityRouteForReport("community_post"))
        assertEquals(CommunityModerationRoute(CommunitySection.COMMENTS), communityRouteForReport("community_comment"))
        /* Un tipo che questa versione non conosce: la prima scheda, come il menu. */
        assertEquals(CommunityModerationRoute(CommunitySection.PROFILES), communityRouteForReport("community_story"))
        /* Dal menu la community parte senza scheda: il ViewModel sceglie i profili. */
        assertEquals("", CommunityModerationRoute().section)
        assertTrue(CommunitySection.all.containsAll(listOf("community_profile", "community_post", "community_comment").map { communityRouteForReport(it).section }))
    }

    @Test fun creatingAFeatureFromTheEventFormOpensTheCatalogFormThatComesBack() {
        val route = CatalogFormRoute(CatalogKind.Features.key, pick = true)
        assertEquals("event_features", route.kind)
        assertNull(route.id)
        assertTrue(route.pick)
        /* Aperto dal catalogo resta il modulo di sempre. */
        assertFalse(CatalogFormRoute(CatalogKind.Features.key).pick)
        assertEquals("catalog_picked_event_features", catalogPickedKey(CatalogKind.Features))
        assertTrue(EditorialArea.Catalog.native)
    }
}
