package it.fabiodalez.incitta.data

import it.fabiodalez.incitta.ui.priceText
import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

class DecisionCostsTest {
    @Test fun partialAmountNeverLooksLikeTheFinalPrice() {
        val price = Json.decodeFromString<Price>("""{"type":"ticket","min":12.0,"is_partial":true,"notes":"Costi parziali"}""")
        assertTrue(priceText(price).contains("Costi parziali"))
        assertTrue(priceText(price).contains("12"))
    }

    @Test fun zeroWithMissingComponentsNeverLooksFree() {
        val price = Price(type = "ticket", min = 0.0, isPartial = true, notes = "Costi parziali")
        assertFalse(priceText(price).contains("gratuito"))
        assertTrue(priceText(price).contains("Costi parziali"))
    }
}
