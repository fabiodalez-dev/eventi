package it.fabiodalez.incitta

import it.fabiodalez.incitta.ui.ContentOptions
import it.fabiodalez.incitta.ui.ContentSelection
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

class ContentPreferencesTest {
    private val json = Json { ignoreUnknownKeys = true; encodeDefaults = true }

    @Test fun dynamicCategoriesAndHiddenChoicesDecodeFromServer() {
        val options = json.decodeFromString<ContentOptions>("""{"selection":{"mode":"selected","categories":[7],"hidden_categories":[8],"inferred_ads":false},"options":[{"id":7,"name":"Una nuova categoria"},{"id":8,"name":"Sport"}]}""")
        assertEquals("Una nuova categoria", options.options.first().name)
        assertEquals(listOf(7L), options.selection.categories)
        assertEquals(listOf(8L), options.selection.hiddenCategories)
        assertFalse(options.selection.inferredAds)
    }

    @Test fun resetSendsEmptyArraysAndModeRatherThanOmittingDefaults() {
        val payload = json.encodeToString(ContentSelection())
        assertTrue(payload.contains("\"mode\":\"all\""))
        assertTrue(payload.contains("\"categories\":[]"))
        assertTrue(payload.contains("\"hidden_categories\":[]"))
        assertEquals(ContentSelection(), json.decodeFromString<ContentSelection>(payload))
    }
}
