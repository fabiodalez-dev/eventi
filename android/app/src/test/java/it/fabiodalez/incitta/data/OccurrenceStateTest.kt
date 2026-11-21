package it.fabiodalez.incitta.data

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.Instant

class OccurrenceStateTest {
    private val now = Instant.parse("2026-09-07T10:00:00Z")

    private fun date(status: String = "scheduled", starts: String = "2026-09-10T19:00:00Z", effectiveEnds: String? = null) =
        Occurrence(occurrenceId = 1, eventId = 1, eventSlug = "x", startsAt = starts, effectiveEndsAt = effectiveEnds, status = status, title = "x")

    @Test fun `a future scheduled date is ahead`() = assertTrue(date().isAhead(now))

    @Test fun `cancelled and postponed dates are never ahead`() {
        assertFalse(date(status = "cancelled").isAhead(now))
        assertFalse(date(status = "postponed").isAhead(now))
    }

    @Test fun `a sold out or moved date still takes place`() {
        assertTrue(date(status = "sold_out").isAhead(now))
        assertTrue(date(status = "moved").isAhead(now))
    }

    @Test fun `a date that already ended today is no longer ahead`() =
        assertFalse(date(starts = "2026-09-07T06:00:00Z", effectiveEnds = "2026-09-07T09:00:00Z").isAhead(now))
}
