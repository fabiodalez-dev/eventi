package it.fabiodalez.incitta.data

import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.*
import org.junit.Assert.*
import org.junit.Test
import java.time.Instant

class SessionParityTest {
    private val json = Json { ignoreUnknownKeys = true; encodeDefaults = true; explicitNulls = true }

    @Test fun quietDefaultsDisabledAndCustomRemainDistinctInPatches() {
        val defaults = json.parseToJsonElement(json.encodeToString(NotificationPreferences())).jsonObject
        assertEquals(JsonNull, defaults["quiet_hours"])
        val off = json.decodeFromString<NotificationPreferences>("""{"quiet_hours":[],"marketing_opt_in":true,"reminder_hours":[48,2]}""")
        assertTrue(off.marketingOptIn)
        assertEquals(listOf(48, 2), off.reminderHours)
        val custom = json.decodeFromString<NotificationPreferences>("""{"quiet_hours":{"from":"23:00","to":"08:00"},"quiet_hours_effective":{"from":"23:00","to":"08:00"}}""")
        assertEquals("08:00", custom.quietHoursEffective?.to)
        assertEquals("23:00", custom.quietHours?.jsonObject?.get("from")?.jsonPrimitive?.content)
    }

    @Test fun venueLogoAndCoverUseIndependentImageUrls() {
        val venue = json.decodeFromString<Venue>("""{"name":"Circolo","logo":{"card":"https://example.test/logo.webp"},"cover":{"card":"https://example.test/cover.webp"},"is_nonprofit":true}""")
        assertEquals("https://example.test/logo.webp", venue.logo)
        assertEquals("https://example.test/cover.webp", venue.cover)
        assertTrue(venue.isNonprofit)
    }

    @Test fun ticketPeriodsUseEventEndRatherThanStartAndSearchAttendees() {
        val now = Instant.parse("2026-09-12T20:00:00Z")
        val ongoing = Booking(1, 11, "Concerto", status = "confirmed", startsAt = "2026-09-12T19:00:00Z", endsAt = "2026-09-12T21:00:00Z", tickets = listOf(AdmissionTicket(21, "Anna Rossi", "valid")))
        val past = ongoing.copy(id = 2, endsAt = "2026-09-12T20:00:00Z")
        val cancelled = ongoing.copy(id = 3, status = "cancelled")
        val items = listOf(past, cancelled, ongoing)
        assertEquals(listOf(1L), filterBookings(items, BookingPeriod.UPCOMING, "anna", now).map { it.id })
        assertEquals(listOf(2L), filterBookings(items, BookingPeriod.PAST, "", now).map { it.id })
        assertEquals(listOf(3L), filterBookings(items, BookingPeriod.CANCELLED, "", now).map { it.id })
        assertTrue(filterBookings(items, BookingPeriod.UPCOMING, "inesistente", now).isEmpty())
    }

    @Test fun profileAcceptsManagementLinksWithoutRequiringThemFromOlderServers() {
        assertTrue(json.decodeFromString<User>("""{"id":1,"email":"a@example.test"}""").managementLinks.isEmpty())
        val user = json.decodeFromString<User>("""{"id":1,"email":"a@example.test","timezone":"Europe/Paris","management_links":[{"label":"Newsletter","url":"https://example.test/admin/newsletter","icon":"newsletter"}]}""")
        assertEquals("Europe/Paris", user.timezone)
        assertEquals("newsletter", user.managementLinks.single().icon)
    }
}
