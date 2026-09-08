package it.fabiodalez.incitta.data

import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import org.junit.Assert.*
import org.junit.Test

class SelectedOccurrenceTest {
    private fun date(id: Long) = Occurrence(occurrenceId = id, eventId = 7, eventSlug = "evento", title = "Evento", startsAt = "2026-09-10T20:00:00+02:00")
    private val detail = EventDetail(id = 7, slug = "evento", title = "Evento", occurrences = listOf(date(1), date(2)))

    @Test fun selectsLaterDateWithoutDuplicatingIt() {
        val selected = detail.selectOccurrence(date(2), null)
        assertEquals(listOf(2L, 1L), selected.occurrences.map { it.occurrenceId })
        assertEquals(listOf(1L, 2L), detail.occurrences.map { it.occurrenceId })
    }

    @Test fun usesDateInformationAndActualHostFallback() {
        val facts = buildJsonObject { put("transit_notes", "Tram") }
        val selected = detail.copy(organizer = Organizer(hostFallback = true))
            .selectOccurrence(date(3).copy(contentDetails = facts), Venue(name = "Altro teatro"))
        assertEquals(facts, selected.contentDetails)
        assertEquals("Altro teatro", selected.organizer.name)
    }

    @Test fun preservesExplicitOrganizer() {
        val selected = detail.copy(organizer = Organizer(name = "Collettivo", slug = "collettivo"))
            .selectOccurrence(date(2), Venue(name = "Teatro"))
        assertEquals("Collettivo", selected.organizer.name)
    }

    @Test(expected = IllegalArgumentException::class) fun rejectsDateFromAnotherEvent() {
        detail.selectOccurrence(date(2).copy(eventId = 99), null)
    }
}
