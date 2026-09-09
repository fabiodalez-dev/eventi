package it.fabiodalez.incitta.data

import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.*
import org.junit.Test

class GoogleCalendarTest {
    private val json = Json { ignoreUnknownKeys = true }

    @Test fun parsesDisconnectedAndUnconfiguredWithoutPretendingSuccess() {
        val status = json.decodeFromString<GoogleCalendarState>("""{"configured":false,"connected":false,"synced_at":null,"selection":null}""")
        assertFalse(status.configured)
        assertFalse(status.connected)
        assertNull(status.syncedAt)
    }

    @Test fun parsesPendingSyncAndRecoverableAuthorizationError() {
        val status = json.decodeFromString<GoogleCalendarState>("""{"configured":true,"connected":true,"error_code":"authorization","event_count":12,"selection":{"days":"7","free":"1","categories":[]}}""")
        assertNull(status.syncedAt)
        assertEquals("authorization", status.errorCode)
        assertEquals("7", status.selection?.get("days")?.jsonPrimitive?.content)
    }

    @Test fun sendsExplicitEmptyCategoriesAndFreeFalseToClearPreviousFilters() {
        val selection = GoogleCalendarSelection(emptyList(), 30, null, false)
        val encoded = json.encodeToString(selection)
        assertTrue(encoded.contains("\"categories\":[]"))
        assertTrue(encoded.contains("\"free\":false"))
        assertFalse(encoded.contains("token"))
    }
}
