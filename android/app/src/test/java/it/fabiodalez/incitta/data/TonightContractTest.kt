package it.fabiodalez.incitta.data

import it.fabiodalez.incitta.ui.TonightPayload
import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

class TonightContractTest {
    private val json = Json { ignoreUnknownKeys = true }

    @Test fun categoryAndBudgetCountsIncludeDisabledZeros() {
        val data = json.decodeFromString<TonightPayload>("""{"counts":{"total":2,"everywhere":2,"categories":{"7":2,"8":0},"budgets":{"":2,"0":0,"20":2}}}""")
        assertEquals(2, data.counts!!.categories["7"])
        assertEquals(0, data.counts!!.categories["8"])
        assertEquals(0, data.counts!!.budgets["0"])
        assertEquals(2, data.counts!!.budgets[""])
    }

    @Test fun emptyFacetMapsRemainDecodable() {
        val data = json.decodeFromString<TonightPayload>("""{"counts":{"total":0,"everywhere":0,"categories":{},"budgets":{},"zones":{},"municipalities":{}}}""")
        assertTrue(data.counts!!.categories.isEmpty())
        val facets = json.decodeFromString<ApiEnvelope<Map<String, Map<String, Int>>>>("""{"data":{"category":{},"time":{"night":0}}}""")
        assertTrue(facets.data.getValue("category").isEmpty())
        assertEquals(0, facets.data.getValue("time")["night"])
    }

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
