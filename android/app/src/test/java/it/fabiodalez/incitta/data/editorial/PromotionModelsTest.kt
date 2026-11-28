package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.ui.editorial.promotion.WizardDraft
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.*
import org.junit.Test

/* I modelli di R8: la lettura delle risposte del server e i corpi che l'app manda. */
class PromotionModelsTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }

    @Test fun aCampaignRowIsReadWithItsProgressAndAbilities() {
        val row = json.decodeFromString(CampaignRow.serializer(), """
            {"id": 7, "event": {"id": 3, "title": "Jazz in cortile", "published": false, "timezone": "Europe/Rome"},
             "placement": {"value": "list_top", "label": "In cima"}, "status": {"value": "active", "label": "Attiva"},
             "phase": {"value": "running", "label": "In corso", "color": "success"},
             "progress": {"state": "on_track", "state_label": "In linea", "color": "success", "goal": 1000, "delivered": 250, "percent": 25.0, "summary": "250 di 1.000"},
             "click_rate": "1.5%", "abilities": {"update": true, "toggle": true, "destroy": false}, "unknown": 1}
        """.trimIndent())
        assertEquals("Jazz in cortile", row.title)
        assertFalse(row.event!!.published)
        assertEquals(25.0, row.progress!!.percent!!, 0.0)
        assertEquals(1000L, row.progress!!.goal)
    }

    @Test fun anExternalSponsorAlwaysSendsOneEventAndAFreeGrantSendsNoPayment() {
        val external = WizardDraft(sponsorType = "external", scope = "all", eventId = 9, placement = "list_top", amount = "150,5", invoiceReference = "F-1").body(step = 2)
        assertEquals("2", external["step"]?.jsonPrimitive?.content)
        assertEquals("event", external["scope"]?.jsonPrimitive?.content)
        assertEquals("9", external["event_id"]?.jsonPrimitive?.content)
        assertEquals("150.5", external["amount"]?.jsonPrimitive?.content)
        assertEquals("F-1", external["invoice_reference"]?.jsonPrimitive?.content)

        val all = WizardDraft(sponsorType = "venue", venueId = 3, scope = "all", eventId = 9, complimentary = true, amount = "10", notes = "Ospite").body()
        assertFalse("event_id" in all)
        assertFalse("amount" in all)
        assertFalse("step" in all)
        assertEquals("true", all["complimentary"]?.jsonPrimitive?.content)
    }

    @Test fun theSettlementAndThePriceBodiesUseTheServerNames() {
        val paid = Settlement(amount = "140,00", method = "cash", reference = " CRO 1 ").body()
        assertEquals("paid", paid["settlement"]?.jsonPrimitive?.content)
        assertEquals("140.00", paid["amount"]?.jsonPrimitive?.content)
        assertEquals("CRO 1", paid["payment_reference"]?.jsonPrimitive?.content)
        val free = Settlement(free = true, reason = " Partner ").body()
        assertEquals(setOf("settlement", "reason"), free.keys)
        assertEquals("Partner", free["reason"]?.jsonPrimitive?.content)

        val price = PriceDraft(placement = "home_hero", months = "3", amount = "450,00", active = false).body()
        assertEquals("3", price["months"]?.jsonPrimitive?.content)
        assertEquals("false", price["is_active"]?.jsonPrimitive?.content)
        assertEquals(2, (bulkBody(listOf(7, 8, 7))["ids"] as JsonArray).size)
    }
}
