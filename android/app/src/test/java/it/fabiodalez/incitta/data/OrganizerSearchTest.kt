package it.fabiodalez.incitta.data

import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

class OrganizerSearchTest {
    private val json = Json { ignoreUnknownKeys = true }

    @Test fun readsOrganizerSearchLinks() {
        val result = json.decodeFromString<SearchResults>("""{"organizers":[{"id":7,"slug":"collettivo","name":"Collettivo","url":"https://example.test/organizzatori/collettivo"}]}""")
        assertEquals("collettivo", result.organizers.single().slug)
        assertEquals(7L, result.organizers.single().id)
    }

    @Test fun remainsCompatibleWithSearchWithoutOrganizers() {
        assertTrue(json.decodeFromString<SearchResults>("{}").organizers.isEmpty())
    }

    @Test fun recognizesVenueAsFallbackOrganizer() {
        val organizer = json.decodeFromString<Organizer>("""{"name":"Teatro","host_fallback":true}""")
        assertTrue(organizer.hostFallback)
        assertNull(organizer.slug)
    }
}
