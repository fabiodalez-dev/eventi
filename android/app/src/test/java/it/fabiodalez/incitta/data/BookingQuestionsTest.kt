package it.fabiodalez.incitta.data

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class BookingQuestionsTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }

    private val availability = json.decodeFromString<ApiEnvelope<BookingAvailability>>(
        """{"data":{"enabled":true,"open":true,"questions":[
            {"id":"qabcdefgh","label":"Taglia","type":"choice","options":["S","M","L"],"required":true},
            {"id":"qbcdefghi","label":"Allergie","type":"text","options":[],"required":false},
            {"id":"qcdefghij","label":"In bici?","type":"yes_no","options":[],"required":true}]}}""",
    ).data

    @Test fun `the booking form decodes the manager's questions, and an old server without them reads as none`() {
        assertEquals(listOf("choice", "text", "yes_no"), availability.questions.map { it.type })
        assertEquals(listOf("S", "M", "L"), availability.questions.first().options)
        assertTrue(json.decodeFromString<BookingAvailability>("""{"enabled":true}""").questions.isEmpty())
    }

    @Test fun `required answers must be given and a choice must come from the list`() {
        val questions = availability.questions
        assertFalse(answersComplete(questions, mapOf("qcdefghij" to "1")))
        assertFalse(answersComplete(questions, mapOf("qabcdefgh" to "XXL", "qcdefghij" to "1")))
        assertFalse(answersComplete(questions, mapOf("qabcdefgh" to "M", "qcdefghij" to "forse")))
        assertTrue(answersComplete(questions, mapOf("qabcdefgh" to "M", "qcdefghij" to "0")))
    }

    @Test fun `only given answers travel, trimmed, and an unknown optional type does not block the booking`() {
        val questions = availability.questions + BookingQuestion("qdefghijk", "Firma", type = "signature")
        assertTrue(answersComplete(questions, mapOf("qabcdefgh" to "M", "qcdefghij" to "1")))
        assertEquals(mapOf("qabcdefgh" to "M", "qcdefghij" to "1"), answersPayload(questions, mapOf("qabcdefgh" to " M ", "qbcdefghi" to "  ", "qcdefghij" to "1", "qdefghijk" to "x")))
        assertFalse(answersComplete(questions + BookingQuestion("qefghijkl", "Nuovo", type = "date", required = true), mapOf("qabcdefgh" to "M", "qcdefghij" to "1")))
    }

    @Test fun `the reservation body carries the answers and the booking reads them back`() {
        val body = json.parseToJsonElement(json.encodeToString(ReserveBody(emptyList(), "k", false, true, emptyMap(), mapOf("qabcdefgh" to "M")))).jsonObject
        assertEquals("\"M\"", body.getValue("answers").jsonObject.getValue("qabcdefgh").toString())
        val booking = json.decodeFromString<Booking>("""{"id":1,"occurrence_id":2,"title":"Corsa","status":"confirmed","answers":[{"question":"Taglia","answer":"M"}]}""")
        assertEquals(BookingAnswer("Taglia", "M"), booking.answers.single())
    }
}
