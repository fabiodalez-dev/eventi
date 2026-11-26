package it.fabiodalez.incitta.data.management

import org.junit.Assert.*
import org.junit.Test

/*
 * Q3: gli avvisi che erano solo email (ordine deciso, riepilogo della
 * sponsorizzazione, rapporto mensile, locale moderato, accesso concesso,
 * richiesta accolta o rifiutata, invito) diventano indirizzi interni che
 * aprono la schermata nativa giusta.
 */
class AppNoticeTargetsTest {
    private fun parse(url: String) = ManagementDestination.parse(url, "https://eventi.fabiodalez.it/api/v1/")

    @Test fun theNewAreasOfASubjectBecomeTheirPanelPages() {
        val order = ManagementNoticeTarget("venue", 12, "sponsorships", 7)
        assertEquals("incitta://gestione/venue/12/sponsorships/7", order.uri())
        assertEquals(ManagementDestination.Panel(SubjectRef(SubjectKind.Venue, id = 12), PanelPage.Sponsorships), parse(order.uri()!!))

        val campaign = ManagementNoticeTarget("organizer", 3, "sponsorship_stats", 55)
        assertEquals("incitta://gestione/organizer/3/sponsorship_stats/55", campaign.uri())
        assertEquals(ManagementDestination.Panel(SubjectRef(SubjectKind.Organizer, id = 3), PanelPage.CampaignAnalytics(55)), parse(campaign.uri()!!))

        val monthly = ManagementNoticeTarget("venue", 12, "statistics", null)
        assertEquals("incitta://gestione/venue/12/statistics", monthly.uri())
        assertEquals(ManagementDestination.Panel(SubjectRef(SubjectKind.Venue, id = 12), PanelPage.Statistics), parse(monthly.uri()!!))

        /* Locale moderato, organizzazione concessa, richiesta accolta: la pagina del soggetto. */
        assertEquals(ManagementDestination.Panel(SubjectRef(SubjectKind.Organizer, id = 3), PanelPage.Home), parse(ManagementNoticeTarget("organizer", 3, "home").uri()!!))
    }

    @Test fun theAccountTargetsOpenApplicationsAndInvitations() {
        val applications = ManagementNoticeTarget("account", 9, "applications")
        assertEquals("incitta://gestione/me/applications", applications.uri())
        assertEquals(ManagementDestination.Registration(RegistrationForm.Applications), parse(applications.uri()!!))
        val invitations = ManagementNoticeTarget("account", 9, "invitations")
        assertEquals("incitta://gestione/me/invitations", invitations.uri())
        assertEquals(ManagementDestination.Invitations, parse(invitations.uri()!!))
    }

    @Test fun incompleteOrUnknownTargetsFallBackToTheUrl() {
        listOf(
            ManagementNoticeTarget("venue", 12, "sponsorships", null),
            ManagementNoticeTarget("venue", 12, "sponsorship_stats", null),
            ManagementNoticeTarget("account", 9, "home"),
            ManagementNoticeTarget("account", 9, "biglietti"),
            ManagementNoticeTarget("venue", 12, "applications"),
        ).forEach { assertNull(it.toString(), it.uri()) }
        assertEquals("https://eventi.fabiodalez.it/le-mie-richieste", noticeTarget(ManagementNoticeTarget("account", 9, "nuova"), "https://eventi.fabiodalez.it/le-mie-richieste"))

        listOf("incitta://gestione/venue/12/sponsorships", "incitta://gestione/venue/12/sponsorship_stats", "incitta://gestione/venue/12/statistics/4",
            "incitta://gestione/me/altro", "incitta://gestione/me/applications/3", "incitta://gestione:80/me/invitations",
            "incitta://utente@gestione/me/invitations").forEach { assertNull(it, parse(it)) }
    }

    @Test fun thePushAndTheInboxCarryTheNewTargets() {
        val push = mapOf("url" to "https://eventi.fabiodalez.it/le-mie-richieste", "management_subject_type" to "account",
            "management_subject_id" to "9", "management_area" to "applications", "management_id" to "")
        assertEquals("incitta://gestione/me/applications", noticeTarget(ManagementNoticeTarget.fromPush(push), push.getValue("url")))
        val stats = mapOf("url" to "https://eventi.fabiodalez.it/eventi/concerto", "management_subject_type" to "venue",
            "management_subject_id" to "12", "management_area" to "sponsorship_stats", "management_id" to "55")
        assertEquals("incitta://gestione/venue/12/sponsorship_stats/55", noticeTarget(ManagementNoticeTarget.fromPush(stats), stats.getValue("url")))

        val json = kotlinx.serialization.json.Json { ignoreUnknownKeys = true }
        val row = json.decodeFromString<it.fabiodalez.incitta.data.InboxNotification>(
            "{\"id\":\"a\",\"type\":\"venue_access_granted\",\"data\":{\"title\":\"Circolo su inCittà\",\"body\":\"Ti hanno invitato\",\"url\":\"https://eventi.fabiodalez.it/inviti-locali/1/2/3\",\"management\":{\"subject_type\":\"account\",\"subject_id\":9,\"area\":\"invitations\",\"id\":null}},\"read\":false}",
        )
        assertEquals("incitta://gestione/me/invitations", row.data.target)
    }
}
