package it.fabiodalez.incitta

import it.fabiodalez.incitta.data.CARD_LAYOUTS
import it.fabiodalez.incitta.data.User
import it.fabiodalez.incitta.data.cardRows
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class CardLayoutTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }

    @Test
    fun `le tre viste sono quelle del sito`() {
        assertEquals(listOf("full", "grid", "list"), CARD_LAYOUTS)
    }

    @Test
    fun `nella griglia due per riga, l'ultima puo restare sola`() {
        assertEquals(listOf(listOf(1, 2), listOf(3, 4), listOf(5)), cardRows(listOf(1, 2, 3, 4, 5), "grid"))
        assertEquals(listOf(listOf(1), listOf(2), listOf(3)), cardRows(listOf(1, 2, 3), "list"))
        assertEquals(listOf(listOf(1), listOf(2)), cardRows(listOf(1, 2), "full"))
        assertEquals(emptyList<List<Int>>(), cardRows(emptyList<Int>(), "grid"))
    }

    @Test
    fun `il profilo porta la vista, o nessuna`() {
        val chosen = json.decodeFromString<User>("""{"id":1,"email":"a@b.it","card_layout":"grid"}""")
        val none = json.decodeFromString<User>("""{"id":1,"email":"a@b.it","card_layout":null}""")
        val old = json.decodeFromString<User>("""{"id":1,"email":"a@b.it"}""")
        assertEquals("grid", chosen.cardLayout)
        assertNull(none.cardLayout)
        assertNull(old.cardLayout)
    }
}
