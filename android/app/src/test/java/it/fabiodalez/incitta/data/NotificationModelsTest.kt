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
}
