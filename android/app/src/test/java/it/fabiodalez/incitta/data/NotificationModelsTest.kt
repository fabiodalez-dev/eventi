package it.fabiodalez.incitta.data

import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.*
import org.junit.Test

class NotificationModelsTest {
    @Test fun deviceRegistrationAlwaysIncludesPlatform() {
        val data = Json.parseToJsonElement(Json.encodeToString(PushDeviceBody("token", "installation-123456", "android"))).jsonObject
        assertEquals("android", data.getValue("platform").jsonPrimitive.content)
    }
    @Test fun preferencePatchCanExplicitlyDisableDefaultValues() {
        val json = Json { encodeDefaults = true }
        val data = json.parseToJsonElement(json.encodeToString(NotificationPreferences(dailyDigest = false, reminders = false))).jsonObject
        assertEquals("false", data.getValue("daily_digest").jsonPrimitive.content)
        assertEquals("false", data.getValue("reminders").jsonPrimitive.content)
    }

    @Test fun theMonthlyReportToggleFollowsTheServer() {
        val json = Json { ignoreUnknownKeys = true; encodeDefaults = true }
        val manager = json.decodeFromString<NotificationPreferences>("""{"venue_report":false,"venue_report_available":true,"comments":false}""")
        assertTrue(manager.venueReportAvailable)
        assertFalse(manager.venueReport)
        assertFalse(manager.comments)
        /* Un server vecchio non dice niente: l'interruttore non compare. */
        assertFalse(json.decodeFromString<NotificationPreferences>("{}").venueReportAvailable)
        /* La scelta letta torna al server così com'è. */
        val patch = json.parseToJsonElement(json.encodeToString(manager)).jsonObject
        assertEquals("false", patch.getValue("venue_report").jsonPrimitive.content)
    }
}
