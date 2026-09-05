package it.fabiodalez.incitta.ui

import androidx.test.ext.junit.runners.AndroidJUnit4
import it.fabiodalez.incitta.data.Occurrence
import it.fabiodalez.incitta.data.Venue
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class CalendarLinksTest {
    @Test
    fun timedEventCreatesAGoogleCalendarTemplateWithVenue() {
        val event = occurrence(
            startsAt = "2026-09-04T17:45:00+02:00",
            endsAt = "2026-09-04T19:15:00+02:00",
        )

        val uri = checkNotNull(googleCalendarUri(event))

        assertEquals("calendar.google.com", uri.host)
        assertEquals("TEMPLATE", uri.getQueryParameter("action"))
        assertEquals("20260904T154500Z/20260904T171500Z", uri.getQueryParameter("dates"))
        assertTrue(checkNotNull(uri.getQueryParameter("location")).contains("Libreria Feltrinelli"))
    }

    @Test
    fun allDayEventUsesAnExclusiveEndDate() {
        val event = occurrence(
            startsAt = "2026-09-04T00:00:00+02:00",
            endsAt = null,
            isAllDay = true,
        )

        assertEquals("20260904/20260905", googleCalendarUri(event)?.getQueryParameter("dates"))
    }

    private fun occurrence(startsAt: String, endsAt: String?, isAllDay: Boolean = false) = Occurrence(
        occurrenceId = 1,
        eventId = 2,
        eventSlug = "evento",
        startsAt = startsAt,
        endsAt = endsAt,
        isAllDay = isAllDay,
        title = "Evento prova",
        venue = Venue(name = "Libreria Feltrinelli", address = "Via San Francesco, 7", municipality = "Padova"),
    )
}
