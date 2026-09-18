package it.fabiodalez.incitta.data

import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class WhatsappDeliveryTest {
    @Test fun uncertainDeliveryIsReported() {
        assertTrue(whatsappDeliveryUncertain(buildJsonObject { put("challenge_id", "x"); put("delivery", "uncertain") }))
    }

    @Test fun sentDeliveryIsNotUncertain() {
        assertFalse(whatsappDeliveryUncertain(buildJsonObject { put("delivery", "sent") }))
    }

    @Test fun missingDeliveryMeansSentForOlderServers() {
        assertFalse(whatsappDeliveryUncertain(buildJsonObject { put("challenge_id", "x") }))
    }

    @Test fun nonStringDeliveryIsIgnored() {
        assertFalse(whatsappDeliveryUncertain(buildJsonObject { put("delivery", buildJsonObject { put("status", "uncertain") }) }))
        assertFalse(whatsappDeliveryUncertain(buildJsonObject { put("delivery", buildJsonArray { add(kotlinx.serialization.json.JsonPrimitive("uncertain")) }) }))
        assertFalse(whatsappDeliveryUncertain(buildJsonObject { put("delivery", JsonNull) }))
        assertFalse(whatsappDeliveryUncertain(buildJsonObject { put("delivery", 1) }))
    }
}
