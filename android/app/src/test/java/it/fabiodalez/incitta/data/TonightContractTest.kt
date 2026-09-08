package it.fabiodalez.incitta.data

import it.fabiodalez.incitta.ui.TonightPayload
import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

class TonightContractTest {
    private val json = Json { ignoreUnknownKeys = true }

    @Test fun geographicCatalogComesFromTheServer() {
        val data = json.decodeFromString<TonightPayload>("""{"municipalities":["Padova","Abano Terme"],"neighborhood_municipality":"Padova","zones":["Brusegana","Guizza"]}""")
        assertEquals("Padova", data.municipalities.first())
        assertEquals("Padova", data.neighborhood_municipality)
        assertEquals(listOf("Brusegana", "Guizza"), data.zones)
    }

    @Test fun emptyResultIsNotReplacedWithUnrelatedEvents() {
        val data = json.decodeFromString<TonightPayload>("""{"categories":[{"id":7,"name":"Nuova categoria"}],"zones":["Centro"],"results":[]}""")
        assertTrue(data.results.isEmpty())
        assertEquals("Nuova categoria", data.categories.single().name)
    }

    @Test fun preservesExplanationsAndUnknownPracticalInformation() {
        val data = json.decodeFromString<TonightPayload>("""{"results":[{"occurrence":{"occurrence_id":3,"event_id":2,"event_slug":"evento","starts_at":"2026-09-10T21:00:00+02:00","title":"Evento"},"reasons":["Nella zona Centro"],"practical":[{"label":"Costi obbligatori","value":"Non comunicato"}]}]}""")
        assertEquals("Nella zona Centro", data.results.single().reasons.single())
        assertEquals("Non comunicato", data.results.single().practical.single().value)
    }
}
