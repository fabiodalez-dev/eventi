package it.fabiodalez.incitta.data

import java.time.LocalDate
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class VenueNoticeTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun `venue detail reads the current notice`() {
        val venue = json.decodeFromString<ApiEnvelope<Venue>>("""{"data":{"name":"Circolo","slug":"circolo","notice":{"kind":"pause","body":"Chiusi per lavori.","until":"2026-11-15"}}}""").data
        assertEquals("pause", venue.notice?.kind)
        assertEquals("Chiusi per lavori.", venue.notice?.body)
        assertEquals(LocalDate.of(2026, 11, 15), venue.notice?.untilDate())
    }

    @Test fun `missing or null notice and open-ended notices are accepted`() {
        assertNull(json.decodeFromString<Venue>("""{"name":"Circolo"}""").notice)
        assertNull(json.decodeFromString<Venue>("""{"name":"Circolo","notice":null}""").notice)
        assertNull(json.decodeFromString<Venue>("""{"name":"Circolo","notice":{"kind":"info","body":"Nuovi orari","until":null}}""").notice?.untilDate())
    }

    @Test fun `public calendar URL is built from the API base`() {
        assertEquals("https://eventi.fabiodalez.it", publicSiteBase("https://eventi.fabiodalez.it/api/v1/"))
        assertEquals("https://eventi.fabiodalez.it/locali/circolo-arci/calendario.ics", venueCalendarUrl("https://eventi.fabiodalez.it/api/v1/", "circolo-arci"))
        assertEquals("http://10.0.2.2:8095/locali/teatro/calendario.ics", venueCalendarUrl("http://10.0.2.2:8095/api/v1/", "teatro"))
    }

    @Test fun `inbox keeps venue notice notifications`() {
        val item = json.decodeFromString<InboxNotification>("""{"id":"n1","type":"venue_notice","data":{"type":"venue_notice","title":"Circolo","body":"Chiusi per lavori.","url":"https://eventi.fabiodalez.it/locali/circolo","kind":"pause"},"read":false}""")
        assertEquals("Chiusi per lavori.", item.data.body)
        assertEquals("https://eventi.fabiodalez.it/locali/circolo", item.data.url)
    }
}
