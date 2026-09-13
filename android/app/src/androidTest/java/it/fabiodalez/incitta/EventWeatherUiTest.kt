package it.fabiodalez.incitta

import androidx.activity.compose.setContent
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.data.EventWeather
import it.fabiodalez.incitta.ui.EventShareActions
import it.fabiodalez.incitta.ui.EventWeatherSection
import it.fabiodalez.incitta.ui.InCittaTheme
import org.junit.Rule
import org.junit.Test

class EventWeatherUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()
    @Test fun unavailableWeatherIsCompletelyHidden() {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { EventWeatherSection(1) { EventWeather(available = false) } } } }
        compose.waitForIdle()
        compose.onNodeWithText("METEO PER L’EVENTO").assertDoesNotExist()
    }
    @Test fun weatherUsesAccessibleIconAndLabelsLongRangeForecast() {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { EventWeatherSection(1) { EventWeather(available = true, icon = "sun", description = "Sereno", minimum = 15.0, maximum = 25.0, rain = 5.0, indicative = true) } } } }
        compose.onNodeWithContentDescription("Sereno").assertExists()
        compose.onNodeWithText("15.0° / 25.0°").assertExists()
        compose.onNodeWithText("Previsione indicativa: ricontrolla avvicinandoti alla data.").assertExists()
    }
    @Test fun weatherShowsEstimatedStartTemperatureAndDailyRange() {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { EventWeatherSection(1) { EventWeather(available = true, description = "Sereno", temperatureAtStart = 21.0, temperatureEstimated = true, startTime = "18:30", minimum = 15.0, maximum = 25.0) } } } }
        compose.onNodeWithText("Temperatura stimata alle 18:30").assertExists()
        compose.onNodeWithText("21.0°").assertExists()
        compose.onNodeWithText("Min / max: 15.0° / 25.0°").assertExists()
        compose.onNodeWithText("Open-Meteo · CC BY 4.0").assertDoesNotExist()
    }
    @Test fun heroSharingIconsKeepAccessibleTargetsOnOneRow() {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { EventShareActions("Evento", "https://eventi.fabiodalez.it/eventi/evento/1") } } }
        val labels = listOf("Condividi evento", "Condividi su WhatsApp", "Condividi su Telegram", "Condividi via email")
        val boxes = labels.map { label ->
            compose.onNodeWithContentDescription(label).assertIsDisplayed().assertHasClickAction().fetchSemanticsNode().boundsInRoot
        }
        boxes.forEach { box -> org.junit.Assert.assertEquals(boxes.first().top, box.top, 0.5f) }
        boxes.zipWithNext().forEach { (left, right) -> org.junit.Assert.assertTrue(left.right <= right.left) }
    }
}
