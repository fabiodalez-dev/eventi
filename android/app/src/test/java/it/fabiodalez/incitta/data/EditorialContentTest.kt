package it.fabiodalez.incitta.data

import kotlinx.serialization.json.*
import org.junit.Assert.*
import org.junit.Test

class EditorialContentTest {
    private val json = Json { ignoreUnknownKeys = true }

    @Test fun `details accept both empty and populated editorial information`() {
        val empty = json.decodeFromString<EventDetail>("""{"id":1,"slug":"evento","title":"Evento","content_details":[]}""")
        assertTrue(empty.contentDetails is JsonArray)
        val full = json.decodeFromString<EventDetail>("""{"id":1,"slug":"evento","title":"Evento","content_details":{"faqs":[{"question":"Dove?","answer":"Al teatro"}],"agenda":[{"title":"Concerto","when":"21:00"}]}}""")
        assertEquals("Dove?", full.contentDetails!!.jsonObject["faqs"]!!.jsonArray[0].jsonObject["question"]!!.jsonPrimitive.content)
        val venue = json.decodeFromString<Venue>("""{"name":"Teatro","content_details":{"parking_notes":"Parcheggio esterno"}}""")
        assertEquals("Parcheggio esterno", venue.contentDetails!!.jsonObject["parking_notes"]!!.jsonPrimitive.content)
    }
}
