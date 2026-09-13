package it.fabiodalez.incitta

import androidx.activity.compose.setContent
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.data.EventWeather
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
}
