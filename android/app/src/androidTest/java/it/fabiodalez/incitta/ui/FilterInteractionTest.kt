package it.fabiodalez.incitta.ui

import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.Column
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.*
import androidx.activity.compose.setContent
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.MainActivity
import it.fabiodalez.incitta.AppUiState
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

class FilterInteractionTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()
    private var applied: Map<String, String>? = null

    private fun screen(filters: Map<String, String>) {
        compose.runOnIdle { compose.activity.setContent {
            InCittaTheme {
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    SearchFilters(AppUiState(discoveryFilters = filters)) { next, _ -> applied = next }
                }
            }
        }
        }
    }

    @Test fun distanceCrossKeepsAllOtherFilters() {
        val filters = mapOf("preset" to "tomorrow", "categories" to "teatro-e-danza", "accessible" to "1", "near" to "45.37852,11.87256", "radius_km" to "25", "sort" to "distance")
        screen(filters)
        compose.onNodeWithText("Entro 25 km ×").performScrollTo().performClick()
        compose.waitUntil(5000) { applied != null }
        assertEquals(filters - setOf("near", "radius_km", "sort"), applied)
    }

    @Test fun priceCrossRemovesOnlyPriceEvenBeforeFacetsArrive() {
        val filters = mapOf("preset" to "tomorrow", "price" to "max10")
        screen(filters)
        compose.onNodeWithText("Fino a 10 € ×").performScrollTo().performClick()
        compose.waitUntil(5000) { applied != null }
        assertEquals(mapOf("preset" to "tomorrow"), applied)
    }

    @Test fun resetClearsAllFiltersWithoutWaitingForNetwork() {
        screen(mapOf("preset" to "tomorrow", "near" to "45,11", "radius_km" to "1"))
        compose.onNodeWithText("Azzera i filtri ×").performClick()
        compose.waitUntil(5000) { applied != null }
        assertEquals(emptyMap<String, String>(), applied)
    }
}
