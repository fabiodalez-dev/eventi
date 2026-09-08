package it.fabiodalez.incitta.data

import java.net.HttpURLConnection
import java.net.URI
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assume.assumeTrue
import org.junit.Test

class ApiCompatibilityTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun `empty venue maps accept older arrays without breaking discovery`() {
        for (empty in listOf("[]", "{}", "null")) {
            val venue = json.decodeFromString<Venue>("""{"name":"Locale","socials":$empty,"accessibility":$empty}""")
            assertEquals(emptyMap<String, String>(), venue.socials)
            assertEquals(emptyMap<String, Boolean>(), venue.accessibility)
        }
        val venue = json.decodeFromString<Venue>("""{"name":"Locale","accessibility":{"step_free_entrance":true}}""")
        assertEquals(true, venue.accessibility["step_free_entrance"])
    }

    @Test
    fun `venue cover accepts variants and legacy cached URLs`() {
        val venue = json.decodeFromString<Venue>("""{"name":"Teatro","cover":{"thumb":"https://example.test/thumb.webp","card":"https://example.test/card.webp","full":"https://example.test/full.jpg","width":800}}""")
        assertEquals("https://example.test/card.webp", venue.cover)
        assertEquals("https://example.test/full.jpg", json.decodeFromString<Venue>("""{"name":"Teatro","cover":{"full":"https://example.test/full.jpg"}}""").cover)
        assertEquals("https://example.test/old.jpg", json.decodeFromString<Venue>("""{"name":"Teatro","cover":"https://example.test/old.jpg"}""").cover)
        assertEquals(null, json.decodeFromString<Venue>("""{"name":"Teatro","cover":null}""").cover)
        assertEquals(null, json.decodeFromString<Venue>("""{"name":"Teatro","cover":{}}""").cover)
        assertEquals(venue, json.decodeFromString<Venue>(json.encodeToString(Venue.serializer(), venue)))
    }

    @Test
    fun `public API responses decode with the actual Android models`() {
        val base = System.getenv("INCITTA_API_CONTRACT_URL")
        assumeTrue("Opt-in read-only public API contract check", base != null)
        val baseUrl = requireNotNull(base)
        fun fetch(path: String): String {
            val connection = URI.create(baseUrl + path).toURL().openConnection() as HttpURLConnection
            connection.connectTimeout = 15000
            connection.readTimeout = 15000
            connection.setRequestProperty("Accept", "application/json")
            return try {
                assertEquals(path, 200, connection.responseCode)
                connection.inputStream.bufferedReader().use { it.readText() }
            } finally { connection.disconnect() }
        }
        val events = json.decodeFromString<ApiEnvelope<List<Occurrence>>>(fetch("events?city=padova")).data
        val venues = json.decodeFromString<ApiEnvelope<List<Venue>>>(fetch("venues?city=padova")).data
        json.decodeFromString<ApiEnvelope<SearchResults>>(fetch("search?city=padova&q=teatro"))
        json.decodeFromString<ApiEnvelope<List<MapMarker>>>(fetch("map/occurrences?city=padova&preset=today"))
        events.map { it.eventSlug }.distinct().forEach { slug ->
            json.decodeFromString<ApiEnvelope<EventDetail>>(fetch("events/$slug"))
        }
        venues.forEach { venue ->
            json.decodeFromString<ApiEnvelope<Venue>>(fetch("venues/${venue.slug}"))
        }
        events.firstOrNull()?.let { event ->
            json.decodeFromString<ApiEnvelope<BookingAvailability>>(fetch("occurrences/${event.occurrenceId}/booking"))
        }
    }
}
