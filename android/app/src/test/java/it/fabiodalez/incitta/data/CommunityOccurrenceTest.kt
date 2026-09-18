package it.fabiodalez.incitta.data

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class CommunityOccurrenceTest {
    /** Same configuration as ApiClient.json. */
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    private fun post(occurrence: String?) = json.decodeFromString<JsonObject>(
        if (occurrence == null) """{"id":1,"intent":"attend"}""" else """{"id":1,"intent":"attend","occurrence":$occurrence}""")

    @Test fun validOccurrenceIsDecoded() {
        val occurrence = post("""{"occurrence_id":12,"event_id":9,"event_slug":"piazza-live","starts_at":"2026-09-04T21:00:00+02:00","title":"Piazza live","extra":true}""").occurrenceOrNull(json)
        assertEquals(12L, occurrence?.occurrenceId)
        assertEquals("Piazza live", occurrence?.title)
    }

    @Test fun missingOccurrenceIsNull() {
        assertNull(post(null).occurrenceOrNull(json))
    }

    @Test fun nullOccurrenceIsNull() {
        assertNull(post("null").occurrenceOrNull(json))
        assertNull(buildJsonObject { put("occurrence", JsonNull) }.occurrenceOrNull(json))
    }

    @Test fun malformedOccurrenceIsNullWithoutThrowing() {
        assertNull(post("[]").occurrenceOrNull(json))
        assertNull(post("\"12\"").occurrenceOrNull(json))
        assertNull(post("""{"title":"Senza identificativi"}""").occurrenceOrNull(json))
        assertNull(post("""{"occurrence_id":"dodici","event_id":9,"event_slug":"x","starts_at":"2026-09-04T21:00:00+02:00","title":"X"}""").occurrenceOrNull(json))
    }
}
