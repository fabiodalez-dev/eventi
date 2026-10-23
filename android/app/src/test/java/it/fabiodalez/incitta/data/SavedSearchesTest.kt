package it.fabiodalez.incitta.data

import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class SavedSearchesTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun `saved searches decode the API shape`() {
        val list = json.decodeFromString<ApiEnvelope<List<SavedSearch>>>("""{"data":[
            {"id":7,"name":"jazz · Musica","filters":{"category":"musica","q":"jazz"},"url":"https://eventi.fabiodalez.it/eventi?category=musica&q=jazz","last_checked_at":"2026-10-23T09:00:00+02:00","created_at":"2026-10-20T09:00:00+02:00"},
            {"id":8,"name":"Vuota","filters":[]}]}""").data
        assertEquals(mapOf("category" to "musica", "q" to "jazz"), list[0].filters)
        assertEquals("2026-10-23T09:00:00+02:00", list[0].lastCheckedAt)
        assertEquals(emptyMap<String, String>(), list[1].filters)
    }

    @Test fun `app filters become site keys and drop what the server discards`() {
        val saved = SavedSearchFilters.fromApp(
            mapOf("categories" to "musica,teatro", "tags" to "jazz", "time_of_day" to "evening", "price" to "max10",
                "preset" to "weekend", "from" to "2026-10-24", "near" to "45.4,11.8", "radius_km" to "5", "sort" to "distance", "outdoor" to "1"),
            " concerto ",
        )
        assertEquals(mapOf("category" to "musica,teatro", "outdoor" to "1", "price" to "max10", "q" to "concerto", "tag" to "jazz", "time" to "evening"), saved)
    }

    @Test fun `button is visible only when something would be saved`() {
        assertFalse(SavedSearchFilters.canSave(emptyMap(), ""))
        assertFalse(SavedSearchFilters.canSave(emptyMap(), "   "))
        assertFalse(SavedSearchFilters.canSave(mapOf("preset" to "today", "sort" to "start"), ""))
        assertTrue(SavedSearchFilters.canSave(emptyMap(), "jazz"))
        assertTrue(SavedSearchFilters.canSave(mapOf("categories" to "musica"), ""))
    }

    @Test fun `opening a saved search restores the app keys`() {
        assertEquals(mapOf("categories" to "musica", "tags" to "jazz", "time_of_day" to "night", "municipality" to "Padova"),
            SavedSearchFilters.toApp(mapOf("category" to "musica", "tag" to "jazz", "time" to "night", "municipality" to "Padova", "q" to "jazz")))
    }

    @Test fun `create body omits a missing name`() {
        val encoder = Json { explicitNulls = false; encodeDefaults = true }
        assertEquals("""{"filters":{"category":"musica"}}""", encoder.encodeToString(SavedSearchBody(mapOf("category" to "musica"))))
    }
}
