package it.fabiodalez.incitta.data

import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Test

class ModelsTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }

    @Test
    fun `occurrence accepts venue or structured custom location`() {
        val payload = """
            {"data":[{
              "occurrence_id":12,"event_id":9,"event_slug":"piazza-live",
              "starts_at":"2026-09-04T21:00:00+02:00","title":"Piazza live",
              "venue":null,"custom_location":{"name":"Piazza Castello","lat":45.1,"lng":11.2}
            }]}
        """.trimIndent()

        val envelope = json.decodeFromString<ApiEnvelope<List<Occurrence>>>(payload)

        assertEquals(12L, envelope.data.single().occurrenceId)
        assertNotNull(envelope.data.single().customLocation)
    }

    @Test
    fun `api error preserves the stable code and field message`() {
        val payload = """{"error":{"code":"VALIDATION_FAILED","message":"Controlla i campi.","fields":{"email":["Email non valida."]}}}"""

        val problem = json.decodeFromString<ApiErrorEnvelope>(payload).error

        assertEquals("VALIDATION_FAILED", problem.code)
        assertEquals("Email non valida.", problem.fields["email"]?.single())
    }

    @Test
    fun `event detail keeps dates tags price venue map and accessibility`() {
        val payload = """
            {"data":{"id":143,"slug":"sei-personaggi","title":"Sei personaggi",
              "description":"Descrizione completa","tags":[{"slug":"rock","name":"rock"}],
              "price":{"type":"ticket","min":20,"max":39},
              "venue":{"id":2,"slug":"geox","name":"Gran Teatro Geox","lat":45.41,"lng":11.85,
                "description":"Arena coperta","accessibility":{"step_free_entrance":true}},
              "occurrences":[
                {"occurrence_id":150,"event_id":143,"event_slug":"sei-personaggi","starts_at":"2026-09-22T20:15:00+02:00","title":"Sei personaggi"},
                {"occurrence_id":151,"event_id":143,"event_slug":"sei-personaggi","starts_at":"2026-09-23T20:15:00+02:00","title":"Sei personaggi"}
              ]}}
        """.trimIndent()

        val detail = json.decodeFromString<ApiEnvelope<EventDetail>>(payload).data

        assertEquals(2, detail.occurrences.size)
        assertEquals("rock", detail.tags.single().slug)
        assertEquals(39.0, detail.price?.max)
        assertEquals(45.41, detail.venue?.lat)
        assertEquals(true, detail.venue?.accessibility?.get("step_free_entrance"))
    }

    @Test
    fun `search keeps venues as first class results`() {
        val payload = """{"data":{"events":[],"venues":[{"id":2,"slug":"geox","name":"Gran Teatro Geox","municipality":"Padova"}],"tags":[]}}"""

        val results = json.decodeFromString<ApiEnvelope<SearchResults>>(payload).data

        assertEquals("Gran Teatro Geox", results.venues.single().name)
    }
}
