package it.fabiodalez.incitta.data

import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class EventExtrasModelsTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun `event detail reads gallery video and co-organizers`() {
        val payload = """
            {"data":{"id":5,"slug":"rassegna","title":"Rassegna",
              "organizer":{"name":"Circolo","slug":"circolo","id":1},
              "organizers":[{"id":1,"name":"Circolo","slug":"circolo","url":"https://example.test/organizzatori/circolo"},
                            {"id":2,"name":"Associazione Vento","slug":"vento","url":"https://example.test/organizzatori/vento"},
                            {"id":2,"name":"Associazione Vento","slug":"vento","url":"https://example.test/organizzatori/vento"}],
              "gallery":[{"thumb":"https://example.test/t.webp","card":"https://example.test/c.webp","full":"https://example.test/f.jpg","blurhash":"LEHV6nWB2yk8","width":1200,"height":800}],
              "video":{"provider":"youtube","embed_url":"https://www.youtube-nocookie.com/embed/abc","watch_url":"https://www.youtube.com/watch?v=abc"}}}
        """.trimIndent()
        val detail = json.decodeFromString<ApiEnvelope<EventDetail>>(payload).data
        assertEquals(1, detail.gallery.size)
        assertEquals("https://example.test/c.webp", detail.gallery[0].card)
        assertEquals(1200, detail.gallery[0].width)
        assertEquals("youtube", detail.video?.provider)
        assertEquals("https://www.youtube.com/watch?v=abc", detail.video?.watchUrl)
        assertEquals("https://www.youtube-nocookie.com/embed/abc", detail.video?.embedUrl)
        assertEquals(3, detail.organizers.size)
        assertEquals(listOf("vento"), detail.coOrganizers().map { it.slug })
    }

    @Test fun `event detail without the new fields keeps working`() {
        val detail = json.decodeFromString<ApiEnvelope<EventDetail>>("""{"data":{"id":5,"slug":"concerto","title":"Concerto"}}""").data
        assertTrue(detail.gallery.isEmpty())
        assertNull(detail.video)
        assertTrue(detail.organizers.isEmpty())
        assertTrue(detail.coOrganizers().isEmpty())
    }

    @Test fun `null video and empty lists are accepted`() {
        val detail = json.decodeFromString<ApiEnvelope<EventDetail>>("""{"data":{"id":5,"slug":"concerto","title":"Concerto","video":null,"gallery":[],"organizers":[]}}""").data
        assertNull(detail.video)
        assertTrue(detail.gallery.isEmpty())
    }
}
