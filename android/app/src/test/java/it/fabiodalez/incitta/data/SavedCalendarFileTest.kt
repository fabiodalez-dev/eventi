package it.fabiodalez.incitta.data

import org.junit.Assert.*
import org.junit.Test

class SavedCalendarFileTest {
    private fun event() = Occurrence(occurrenceId = 42, eventId = 1, eventSlug = "concerto", startsAt = "2026-09-12T19:00:00Z", effectiveEndsAt = "2026-09-12T21:00:00Z", title = "Musica, parole;\ninsieme")

    @Test fun `exports all local saved dates and deduplicates by occurrence`() {
        val file = SavedCalendarFile.render(listOf(event(), event(), event().copy(occurrenceId = 43)), "https://example.test/api/v1/", "Salvati")
        assertEquals(2, Regex("BEGIN:VEVENT").findAll(file).count())
        assertTrue(file.contains("UID:occorrenza-42@example.test"))
        assertTrue(file.contains("SUMMARY:Musica\\, parole\\;\\ninsieme"))
        assertTrue(file.contains("DTSTART:20260912T190000Z"))
    }

    @Test fun `unicode folding preserves content and respects octet limits`() {
        val title = "È una serata 🎵 ".repeat(30)
        val file = SavedCalendarFile.render(listOf(event().copy(title = title)), "https://example.test/", "Salvati")
        assertTrue(file.split("\r\n").all { it.toByteArray(Charsets.UTF_8).size <= 75 })
        assertTrue(file.replace("\r\n ", "").contains("SUMMARY:$title"))
    }

    @Test fun `all day dates and cancellations keep their semantics`() {
        val file = SavedCalendarFile.render(listOf(event().copy(isAllDay = true, startsAt = "2026-09-11T22:00:00Z", effectiveEndsAt = "2026-09-12T21:59:59Z", status = "cancelled")), "https://example.test/", "Salvati")
        assertTrue(file.contains("DTSTART;VALUE=DATE:20260912"))
        assertTrue(file.contains("DTEND;VALUE=DATE:20260913"))
        assertTrue(file.contains("STATUS:CANCELLED"))
    }
}
