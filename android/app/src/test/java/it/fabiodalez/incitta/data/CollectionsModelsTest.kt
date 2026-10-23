package it.fabiodalez.incitta.data

import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class CollectionsModelsTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun `collection list decodes the API shape`() {
        val payload = """{"data":[{"id":3,"name":"Weekend","description":null,"is_public":true,"public_url":"https://eventi.fabiodalez.it/raccolte/AbCdEfGhIjKlMnOpQrStUvWx","items_count":2,"created_at":"2026-10-23T10:00:00+02:00"},
            {"id":4,"name":"Da vedere","is_public":false,"public_url":null,"items_count":0,"created_at":"2026-10-22T10:00:00+02:00"}]}"""
        val list = json.decodeFromString<ApiEnvelope<List<EventCollection>>>(payload).data
        assertEquals(2, list.size)
        assertTrue(list[0].isPublic)
        assertEquals("https://eventi.fabiodalez.it/raccolte/AbCdEfGhIjKlMnOpQrStUvWx", list[0].publicUrl)
        assertEquals(2, list[0].itemsCount)
        assertFalse(list[1].isPublic)
        assertNull(list[1].publicUrl)
    }

    @Test fun `owner detail and shared collection share one model`() {
        val owner = json.decodeFromString<ApiEnvelope<CollectionDetail>>("""{"data":{"id":3,"name":"Weekend","is_public":false,"public_url":null,"items_count":1,"created_at":"2026-10-23T10:00:00+02:00",
            "items":[{"occurrence_id":91,"title":"Concerto","starts_at":"2026-10-25T21:00:00+01:00","venue":"Teatro","url":"https://eventi.fabiodalez.it/eventi/concerto","note":"con Anna"}]}}""").data
        assertEquals(3L, owner.id)
        assertEquals(91L, owner.items.single().occurrenceId)
        assertEquals("con Anna", owner.items.single().note)
        assertEquals(EventCollection(3, "Weekend", null, false, null, 1, "2026-10-23T10:00:00+02:00"), owner.summary())

        val shared = json.decodeFromString<ApiEnvelope<CollectionDetail>>("""{"data":{"name":"Weekend","description":"Per sabato","owner":"Anna",
            "items":[{"occurrence_id":91,"title":null,"starts_at":"2026-10-25T21:00:00+01:00","venue":null,"url":"https://eventi.fabiodalez.it/eventi/concerto","note":null}]}}""").data
        assertNull(shared.id)
        assertNull(shared.summary())
        assertEquals("Anna", shared.owner)
        assertNull(shared.items.single().title)
    }

    @Test fun `bodies use the server field names`() {
        val encoder = Json { explicitNulls = false; encodeDefaults = true }
        assertEquals("""{"name":"Weekend","is_public":true,"occurrence_id":91}""", encoder.encodeToString(CollectionBody("Weekend", null, true, 91)))
        assertEquals("""{"occurrence_id":91,"note":"Ci vediamo lì"}""", encoder.encodeToString(CollectionItemBody(91, "Ci vediamo lì")))
    }
}
