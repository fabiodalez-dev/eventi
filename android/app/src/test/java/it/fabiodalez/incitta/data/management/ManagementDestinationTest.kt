package it.fabiodalez.incitta.data.management

import org.junit.Assert.*
import org.junit.Test

class ManagementDestinationTest {
    private val origin = "https://eventi.fabiodalez.it/api/v1/"
    private fun parse(url: String) = ManagementDestination.parse(url, origin)

    @Test fun ticketingPagesOfTheSiteOpenTheNativeScreens() {
        assertEquals(ManagementDestination.TicketingDates, parse("https://eventi.fabiodalez.it/gestione-biglietti"))
        assertEquals(ManagementDestination.TicketingDates, parse("https://eventi.fabiodalez.it/gestione-biglietti/"))
        assertEquals(ManagementDestination.TicketingDate(41), parse("https://eventi.fabiodalez.it/gestione-biglietti/41"))
        assertEquals(ManagementDestination.TicketingDate(41), parse("https://eventi.fabiodalez.it/gestione-biglietti/41/scanner?x=1#top"))
    }

    @Test fun pagesWithoutANativeScreenStayInTheBrowser() {
        listOf(
            /* Lo studio social è solo del locale: l'organizzazione non ha la pagina. */
            "https://eventi.fabiodalez.it/organizza/jazz-club/social",
            "https://eventi.fabiodalez.it/gestione/login",
            "https://eventi.fabiodalez.it/organizza/password-reset/request",
            "https://eventi.fabiodalez.it/gestione/teatro-verdi/eventi/concerto/csv",
            "https://eventi.fabiodalez.it/organizza/jazz-club/collaboratori",
            "https://eventi.fabiodalez.it/gestione/teatro-verdi/statistiche-condivisioni/altro/4",
            "https://eventi.fabiodalez.it/gestione/teatro-verdi/statistiche-condivisioni/event/0",
            "https://eventi.fabiodalez.it/gestione/Teatro-Verdi",
            "https://eventi.fabiodalez.it/gestione-biglietti/41/csv",
            "https://eventi.fabiodalez.it/gestione-biglietti/41/lista.pdf",
            "https://eventi.fabiodalez.it/gestione-biglietti/prenotazione/5/annulla",
            "https://eventi.fabiodalez.it/gestione-biglietti/0",
            "https://eventi.fabiodalez.it/gestione-biglietti/-4",
            "https://eventi.fabiodalez.it/gestione-biglietti/99999999999999999999",
            "https://eventi.fabiodalez.it/biglietti",
        ).forEach { assertNull(it, parse(it)) }
    }

    @Test fun otherHostsSchemesPortsAndCredentialsAreRejected() {
        listOf(
            "https://altro.example/gestione-biglietti/41",
            "http://eventi.fabiodalez.it/gestione-biglietti/41",
            "https://eventi.fabiodalez.it:8443/gestione-biglietti/41",
            "https://utente@eventi.fabiodalez.it/gestione-biglietti/41",
            "non è un indirizzo",
        ).forEach { assertNull(it, parse(it)) }
    }

    /* P12: ogni pagina del pannello con una schermata nativa, nelle due varianti (locale e organizzazione). */
    @Test fun everyPanelPageWithANativeScreenIsTranslated() {
        val venue = SubjectRef(SubjectKind.Venue, slug = "teatro-verdi")
        val organizer = SubjectRef(SubjectKind.Organizer, slug = "jazz-club")
        fun panel(url: String) = parse(url) as ManagementDestination.Panel
        mapOf(
            "" to PanelPage.Home,
            "/eventi" to PanelPage.Events,
            "/eventi/nuovo" to PanelPage.NewEvent,
            "/eventi/concerto-jazz" to PanelPage.Event(slug = "concerto-jazz"),
            "/eventi/concerto-jazz/modifica" to PanelPage.Event(slug = "concerto-jazz", edit = true),
            "/locale" to PanelPage.Profile,
            "/collaboratori" to PanelPage.Collaborators,
            "/il-tuo-accesso" to PanelPage.MyAccess,
            "/calendario" to PanelPage.CalendarImport,
            "/statistiche" to PanelPage.Statistics,
            "/statistiche-condivisioni" to PanelPage.ShareAnalytics,
            "/statistiche-condivisioni/event/41" to PanelPage.SubjectAnalytics("event", 41),
            "/statistiche-sponsorizzazioni" to PanelPage.SponsorshipAnalytics,
            "/sponsorizzazioni" to PanelPage.Sponsorships,
            "/social" to PanelPage.Social(),
            "/social?event=41" to PanelPage.Social(eventId = 41),
            "/social?event=0" to PanelPage.Social(),
        ).forEach { (rest, page) ->
            val url = "https://eventi.fabiodalez.it/gestione/teatro-verdi$rest"
            assertEquals(url, ManagementDestination.Panel(venue, page, url), panel(url))
        }
        mapOf(
            "" to PanelPage.Home,
            "/eventi" to PanelPage.Events,
            "/eventi/create" to PanelPage.NewEvent,
            "/eventi/serata-swing" to PanelPage.Event(slug = "serata-swing"),
            "/eventi/serata-swing/edit" to PanelPage.Event(slug = "serata-swing", edit = true),
            "/profile" to PanelPage.Profile,
            "/statistiche" to PanelPage.Statistics,
            "/statistiche-condivisioni/organizer/3" to PanelPage.SubjectAnalytics("organizer", 3),
            "/sponsorizzazioni" to PanelPage.Sponsorships,
        ).forEach { (rest, page) ->
            val url = "https://eventi.fabiodalez.it/organizza/jazz-club$rest"
            assertEquals(url, ManagementDestination.Panel(organizer, page, url), panel(url))
        }
        /* Commenti e recensioni restano la destinazione di P7. */
        assertEquals(ManagementDestination.Feedback(SubjectKind.Venue, "teatro-verdi", reviews = false), parse("https://eventi.fabiodalez.it/gestione/teatro-verdi/event-comments"))
        assertEquals(ManagementDestination.Start, parse("https://eventi.fabiodalez.it/gestione"))
        assertEquals(ManagementDestination.Start, parse("https://eventi.fabiodalez.it/organizza/"))
    }

    @Test fun noticesOfTheServerBecomeAnInternalAddressWithIds() {
        val event = ManagementNoticeTarget("venue", 12, "event", 34)
        assertEquals("incitta://gestione/venue/12/event/34", event.uri())
        assertEquals(ManagementDestination.Panel(SubjectRef(SubjectKind.Venue, id = 12), PanelPage.Event(id = 34)), parse(event.uri()!!))
        val comments = ManagementNoticeTarget("organizer", 3, "comments", 41)
        assertEquals(ManagementDestination.Panel(SubjectRef(SubjectKind.Organizer, id = 3), PanelPage.Comments(41)), parse(comments.uri()!!))
        val home = ManagementNoticeTarget("venue", 12, "home", null)
        assertEquals("incitta://gestione/venue/12/home", home.uri())
        assertEquals(ManagementDestination.Panel(SubjectRef(SubjectKind.Venue, id = 12), PanelPage.Home), parse(home.uri()!!))

        /* Dati incompleti o sconosciuti: vale l'`url` dell'avviso. */
        listOf(ManagementNoticeTarget("venue", 0, "event", 34), ManagementNoticeTarget("altro", 12, "event", 34), ManagementNoticeTarget("venue", 12, "event", null),
            ManagementNoticeTarget("venue", 12, "biglietti", 4), ManagementNoticeTarget()).forEach { assertNull(it.toString(), it.uri()) }
        assertEquals("https://eventi.fabiodalez.it/eventi/concerto", noticeTarget(null, "https://eventi.fabiodalez.it/eventi/concerto"))
        assertEquals("incitta://gestione/venue/12/event/34", noticeTarget(event, "https://eventi.fabiodalez.it/eventi/concerto"))

        /* Indirizzi interni storpiati. */
        listOf("incitta://gestione/venue/12/event", "incitta://gestione/venue/12/home/4", "incitta://gestione/locale/12/home",
            "incitta://account/venue/12/home", "incitta://gestione/venue/0/home").forEach { assertNull(it, parse(it)) }
    }

    @Test fun thePushCarriesTheTargetAsFlatFields() {
        val data = mapOf("url" to "https://eventi.fabiodalez.it/gestione/circolo", "management_subject_type" to "venue",
            "management_subject_id" to "12", "management_area" to "home", "management_id" to "")
        assertEquals(ManagementNoticeTarget("venue", 12, "home", null), ManagementNoticeTarget.fromPush(data))
        assertNull(ManagementNoticeTarget.fromPush(mapOf("url" to "https://eventi.fabiodalez.it/eventi/x")))
        assertNull(ManagementNoticeTarget.fromPush(mapOf("management_subject_type" to "venue", "management_subject_id" to "x")))
    }

    @Test fun theInboxRowOpensTheManagementWhenTheServerSaysSo() {
        val json = kotlinx.serialization.json.Json { ignoreUnknownKeys = true }
        val row = json.decodeFromString<it.fabiodalez.incitta.data.InboxNotification>(
            "{\"id\":\"a\",\"type\":\"event_new_comment\",\"data\":{\"title\":\"Nuovo commento\",\"body\":\"…\",\"url\":\"https://eventi.fabiodalez.it/eventi/x?commento=5#commento-5\",\"management\":{\"subject_type\":\"venue\",\"subject_id\":12,\"area\":\"comments\",\"id\":41}},\"read\":false}",
        )
        assertEquals("incitta://gestione/venue/12/comments/41", row.data.target)
        val old = json.decodeFromString<it.fabiodalez.incitta.data.InboxNotification>("{\"id\":\"b\",\"data\":{\"title\":\"x\",\"url\":\"https://eventi.fabiodalez.it/gestione/circolo\"}}")
        assertEquals("https://eventi.fabiodalez.it/gestione/circolo", old.data.target)
    }
}
