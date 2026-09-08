package it.fabiodalez.incitta.data

import it.fabiodalez.incitta.ui.OrganizerFollowRequest
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

class OrganizerFollowTest {
    @Test fun requestAlwaysContainsTypeAndExplicitNotificationChoice() {
        val payload = Json.encodeToString(OrganizerFollowRequest(7, false, "organizer"))
        assertTrue(payload.contains("\"type\":\"organizer\""))
        assertTrue(payload.contains("\"notify\":false"))
    }
}
