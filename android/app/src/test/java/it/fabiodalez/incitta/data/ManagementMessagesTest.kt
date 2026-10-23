package it.fabiodalez.incitta.data

import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Test

class ManagementMessagesTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true; encodeDefaults = true }

    @Test fun `message body uses the server field names`() {
        assertEquals(
            """{"subject":"Cambio orario","body":"Si apre alle 21.","include_waitlisted":true}""",
            json.encodeToString(AttendeeMessageBody("Cambio orario", "Si apre alle 21.", true)),
        )
    }

    @Test fun `message response reports the recipients`() {
        val sent = json.decodeFromString<ApiEnvelope<AttendeeMessage>>("""{"data":{"id":4,"recipients_count":37,"created_at":"2026-10-23T18:00:00+02:00"}}""").data
        assertEquals(37, sent.recipientsCount)
        assertEquals(4L, sent.id)
    }

    @Test fun `ticketing limit error surfaces the server text`() {
        val problem = json.decodeFromString<ApiErrorEnvelope>("""{"error":{"message":"Dati non validi","fields":{"ticketing":["Hai già inviato il numero massimo di messaggi di oggi per questa data."]}}}""").error
        assertEquals("Hai già inviato il numero massimo di messaggi di oggi per questa data.", ApiException(422, problem).message)
    }

    @Test fun `guest booking body keeps one name per line and drops empty fields`() {
        val body = guestBookingBody("  Ada Rossi \n\n Bruno Bianchi\r\n", " ", "+39 333 123 4567", "")
        assertEquals("""{"names":"Ada Rossi\nBruno Bianchi","phone":"+393331234567"}""", Json { explicitNulls = false }.encodeToString(body))
    }

    @Test fun `guest booking response is a booking with its tickets`() {
        val booking = json.decodeFromString<ApiEnvelope<Booking>>("""{"data":{"id":90,"occurrence_id":12,"title":"Concerto","status":"confirmed",
            "tickets":[{"id":1,"attendee_name":"Ada Rossi","status":"valid"},{"id":2,"attendee_name":"Bruno Bianchi","status":"valid"}]}}""").data
        assertEquals(2, booking.tickets.size)
    }
}
