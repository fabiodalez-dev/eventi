package it.fabiodalez.incitta.data

import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** Il biglietto dell'app legge locandina, organizzatore e indicazioni su più righe dalla prenotazione. */
class TicketPassModelTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun `booking carries poster, organizer and instructions with their line breaks`() {
        val booking = json.decodeFromString<Booking>("""{"id":15,"occurrence_id":40,"title":"Concerto","status":"confirmed",
            "poster":{"thumb":"https://example.test/t.webp","card":"https://example.test/c.webp","full":null},
            "organizer":"Collettivo Portici Aperti","instructions":"Porte alle 20.45\nIngresso dal cortile\n\nPortate la tessera",
            "tickets":[{"id":24,"attendee_name":"Giulia Rossi","status":"valid","qr_payload":"abc"}]}""")
        assertEquals("https://example.test/t.webp", booking.poster?.thumb)
        assertEquals("Collettivo Portici Aperti", booking.organizer)
        assertEquals(listOf("Porte alle 20.45", "Ingresso dal cortile", "", "Portate la tessera"), booking.instructions?.lines())
    }

    @Test fun `older responses without poster and organizer still decode`() {
        val booking = json.decodeFromString<Booking>("""{"id":1,"occurrence_id":2,"title":"Concerto","status":"confirmed"}""")
        assertNull(booking.poster)
        assertNull(booking.organizer)
    }
}
