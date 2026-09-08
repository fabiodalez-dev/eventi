package it.fabiodalez.incitta.data

import java.time.Instant
import org.junit.Assert.*
import org.junit.Test

class SavedAgendaTest {
    @Test fun movesToPastAtEffectiveEndNotMidnightOrStart() {
        val event = Occurrence(occurrenceId = 1, eventId = 1, eventSlug = "night", title = "Serata", startsAt = "2026-09-08T23:00:00+02:00", effectiveEndsAt = "2026-09-09T03:00:00+02:00")
        assertFalse(event.hasEnded(Instant.parse("2026-09-09T00:00:00Z")))
        assertTrue(event.hasEnded(Instant.parse("2026-09-09T01:00:00Z")))
    }

    @Test fun unknownEndIsNotSilentlyDiscarded() {
        val event = Occurrence(occurrenceId = 1, eventId = 1, eventSlug = "unknown", title = "Evento", startsAt = "2026-09-08T12:00:00Z")
        assertFalse(event.hasEnded())
    }
}
