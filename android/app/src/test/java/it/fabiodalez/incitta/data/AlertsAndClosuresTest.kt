package it.fabiodalez.incitta.data

import java.time.Instant
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Avviso di apertura delle prenotazioni, chiusure straordinarie e prossima edizione (D63). */
class AlertsAndClosuresTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }
    private val now = Instant.parse("2026-10-24T10:00:00Z")

    private fun date(opensAt: String?, enabled: Boolean = true, status: String = "scheduled", startsAt: String = "2026-11-20T21:00:00+01:00") =
        Occurrence(occurrenceId = 1, eventId = 1, eventSlug = "e", title = "Evento", startsAt = startsAt, bookingEnabled = enabled, bookingOpensAt = opensAt, status = status)

    @Test fun `the opening alert is offered only while the opening is ahead on a bookable date`() {
        assertTrue(date("2026-11-01T10:00:00+01:00").offersOpeningAlert(now))
        assertFalse(date("2026-10-20T10:00:00+02:00").offersOpeningAlert(now))
        assertFalse(date(null).offersOpeningAlert(now))
        assertFalse(date("2026-11-01T10:00:00+01:00", enabled = false).offersOpeningAlert(now))
        assertFalse(date("2026-11-01T10:00:00+01:00", status = "cancelled").offersOpeningAlert(now))
        assertEquals("2026-11-01T10:00:00+01:00", json.decodeFromString<Occurrence>("""{"occurrence_id":1,"event_id":1,"event_slug":"e","title":"E","starts_at":"2026-11-20T21:00:00+01:00","booking_opens_at":"2026-11-01T10:00:00+01:00"}""").bookingOpensAt)
    }

    @Test fun `a closure in progress wins over the hours and lists the upcoming ones`() {
        val venue = json.decodeFromString<Venue>("""{"id":3,"name":"Osteria","open_now":false,"open_until":null,
            "closure_now":{"from":"2026-10-24","to":"2026-10-26","note":"Lavori"},
            "closures":[{"from":"2026-10-24","to":"2026-10-26","note":"Lavori"},{"from":"2026-12-24","to":"2027-01-06"}]}""")
        assertEquals(OpenState.CLOSURE, venue.openState())
        assertEquals("26 ottobre", closureDay(venue.closureNow!!.to))
        assertEquals("6 gennaio 2027", closureDay(venue.closures[1].to, withYear = true))
        assertNull(venue.closures[1].note)
        val old = json.decodeFromString<Venue>("""{"id":4,"name":"Bar","open_now":true}""")
        assertEquals(OpenState.OPEN, old.openState())
        assertTrue(old.closures.isEmpty())
    }

    @Test fun `the event detail says whether the next edition alert is offered, and old servers mean no`() {
        val base = """"id":9,"slug":"sagra","title":"Sagra""""
        assertTrue(json.decodeFromString<EventDetail>("{$base,\"edition_alert_offered\":true}").editionAlertOffered)
        assertFalse(json.decodeFromString<EventDetail>("{$base}").editionAlertOffered)
    }
}
