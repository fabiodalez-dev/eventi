package it.fabiodalez.incitta.data

import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class NoShowTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun `bookings envelope carries the recent no-shows`() {
        val page = json.decodeFromString<ApiEnvelope<List<Booking>>>("""{"data":[
            {"id":12,"occurrence_id":40,"title":"Concerto","status":"no_show","tickets":[{"id":1,"attendee_name":"Ada Rossi","status":"no_show"}]}
        ],"meta":{"next_page":null,"no_shows":2}}""")
        assertEquals(2, page.meta?.noShows)
        assertEquals("no_show", page.data.single().status)
        assertEquals("no_show", page.data.single().tickets.single().status)
    }

    @Test fun `older responses without the counter still decode`() {
        val page = json.decodeFromString<ApiEnvelope<List<Booking>>>("""{"data":[],"meta":{"next_page":2}}""")
        assertNull(page.meta?.noShows)
        assertEquals(2, page.meta?.nextPage)
    }

    @Test fun `a no-show booking belongs to the past bookings`() {
        val booking = Booking(id = 1, occurrenceId = 2, title = "Concerto", status = "no_show", startsAt = "2026-09-01T21:00:00+02:00")
        assertEquals(listOf(booking), filterBookings(listOf(booking), BookingPeriod.PAST, ""))
        assertEquals(emptyList<Booking>(), filterBookings(listOf(booking), BookingPeriod.CANCELLED, ""))
    }
}
