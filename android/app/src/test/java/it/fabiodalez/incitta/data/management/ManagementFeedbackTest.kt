package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.EventCommentPage
import it.fabiodalez.incitta.data.VenueReviewPage
import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

/* Commenti e recensioni (P7): forma delle risposte, filtri dell'elenco, collegamenti del sito. */
class ManagementFeedbackTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }
    private val venue = ManagedVenue(id = 12, slug = "circolo-aurora", name = "Circolo Aurora").toSubject()

    @Test fun readsTheCommentsListOfTheServer() {
        val payload = """{"data":[{"id":5,"event":{"id":41,"title":"Concerto","slug":"concerto"},"author":"Giulia Ferrari","body":"Domanda sul parcheggio",
            "is_reply":false,"reactions_count":3,"status":"hidden","status_label":"Nascosto","created_at":"2026-11-20T19:30:00+00:00",
            "moderated_by":"Ada Rossi","moderated_at":"2026-11-21T08:00:00+00:00","revision":2,"can_hide":false,"can_restore":true,"nuovo":1}],
            "meta":{"next_page":2,"total":31}}"""
        val page = json.decodeFromString<ManagedCommentPage>(payload)
        val comment = page.data.single()
        assertEquals(41L, comment.event?.id)
        assertTrue(comment.hidden)
        assertTrue(comment.canRestore)
        assertEquals(2, comment.revision)
        assertEquals(2, page.meta.nextPage)
        assertEquals(31, page.meta.total)
        /* Un server che manda meno campi non fa fallire la lettura. */
        assertEquals(ManagedComment(id = 9), json.decodeFromString<ManagedCommentResult>("""{"data":{"id":9}}""").data)
    }

    @Test fun readsTheReviewsListAndTreatsAnEmptyReplyAsNone() {
        val payload = """{"data":[
            {"id":7,"author":"Marco","rating":4,"body":"Cena ottima","published_at":"2026-11-02T10:00:00+00:00","reply":{"body":"Grazie!","author":"Circolo Aurora","replied_at":null},"can_reply":true,"can_remove_reply":true,"can_report":false},
            {"id":8,"author":"Sara","rating":0,"body":null,"reply":{"body":""}}],"meta":{"next_page":null,"total":2,"can_report":false}}"""
        val page = json.decodeFromString<ManagedReviewPage>(payload)
        assertEquals("Grazie!", page.data[0].givenReply?.body)
        assertEquals(4, page.data[0].stars)
        assertNull(page.data[1].givenReply)
        assertNull(page.data[1].stars)
        assertFalse(page.data[1].canReply)
    }

    @Test fun buildsTheCommentsPathWithOnlyTheFiltersInUse() {
        assertEquals("management/venues/12/comments?page=1", commentsPath(venue, null, "  ", null, 1))
        assertEquals("management/venues/12/comments?status=hidden&q=caff%C3%A8%20e%20cornetto&event=41&page=2", commentsPath(venue, "hidden", " caffè e cornetto ", 41, 2))
        assertEquals("management/venues/12/comments?q=a%26b%3Dc&page=1", commentsPath(venue, null, "a&b=c", 0, 1))
    }

    @Test fun theSitePagesOfCommentsAndReviewsOpenTheNativeScreens() {
        val origin = "https://eventi.fabiodalez.it/api/v1/"
        fun parse(url: String) = ManagementDestination.parse(url, origin)
        assertEquals(ManagementDestination.Feedback(SubjectKind.Venue, "circolo-aurora", reviews = false),
            parse("https://eventi.fabiodalez.it/gestione/circolo-aurora/event-comments"))
        assertEquals(ManagementDestination.Feedback(SubjectKind.Venue, "circolo-aurora", reviews = false, eventId = 41),
            parse("https://eventi.fabiodalez.it/gestione/circolo-aurora/event-comments?event=41"))
        assertEquals(ManagementDestination.Feedback(SubjectKind.Organizer, "collettivo-portici", reviews = true),
            parse("https://eventi.fabiodalez.it/organizza/collettivo-portici/recensioni/"))
        /* Un id di evento sbagliato non blocca la pagina: si aprono tutti i commenti. */
        assertEquals(ManagementDestination.Feedback(SubjectKind.Venue, "circolo-aurora", reviews = false),
            parse("https://eventi.fabiodalez.it/gestione/circolo-aurora/event-comments?event=-1"))
        listOf(
            "https://eventi.fabiodalez.it/gestione/circolo-aurora/event-comments/5",
            "https://eventi.fabiodalez.it/gestione/Circolo%20Aurora/recensioni",
            "https://altro.example/gestione/circolo-aurora/recensioni",
            "https://eventi.fabiodalez.it/admin/recensioni",
        ).forEach { assertNull(it, parse(it)) }
    }

    @Test fun thePublicPagesReadTheManagementAddressOnlyWhenTheServerSendsIt() {
        assertNull(json.decodeFromString<EventCommentPage>("""{"comments":[]}""").manageUrl)
        assertEquals("https://eventi.fabiodalez.it/gestione/circolo-aurora/event-comments?event=41",
            json.decodeFromString<EventCommentPage>("""{"comments":[],"manage_url":"https://eventi.fabiodalez.it/gestione/circolo-aurora/event-comments?event=41"}""").manageUrl)
        assertNull(json.decodeFromString<VenueReviewPage>("""{"reviews":[],"manage_url":null}""").manageUrl)
    }
}
