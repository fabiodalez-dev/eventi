package it.fabiodalez.incitta.ui

import androidx.activity.compose.setContent
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.espresso.Espresso.onView
import androidx.test.espresso.action.ViewActions.click
import androidx.test.espresso.matcher.RootMatchers.isDialog
import androidx.test.espresso.matcher.ViewMatchers.withText
import it.fabiodalez.incitta.AppTab
import it.fabiodalez.incitta.MainActivity
import it.fabiodalez.incitta.MainViewModel
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

class HeaderAndNativePickerTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    @Test fun quickThemeIsInEveryTabAndDoesNotChangeTheDefault() {
        lateinit var model: MainViewModel
        compose.runOnIdle {
            model = MainViewModel(compose.activity.application)
            model.setPrivacyConsent(false)
            compose.activity.setContent { InCittaApp(model) }
        }
        val default = model.state.value.defaultAppearance
        val other = if (default == "dark") "light" else "dark"
        compose.onNodeWithContentDescription(if (default == "dark") "Passa al tema chiaro" else "Passa al tema scuro").performClick()
        for (tab in listOf(AppTab.HOME, AppTab.EVENTS, AppTab.SEARCH, AppTab.SAVED, AppTab.ACCOUNT, AppTab.CALENDAR, AppTab.VENUES, AppTab.MAP)) {
            compose.runOnIdle { model.selectTab(tab) }
            compose.onNodeWithContentDescription(if (other == "dark") "Passa al tema chiaro" else "Passa al tema scuro").assertIsDisplayed()
            assertEquals(default, model.state.value.defaultAppearance)
            assertEquals(other, model.state.value.appearance)
        }
    }

    @Test fun interestsUseAndroidSingleChoiceDialog() {
        var selected = "neutral"
        compose.runOnIdle { compose.activity.setContent {
            InCittaTheme { AppSafeArea {
                NativeChoicePicker("Musica", "neutral", listOf("neutral" to "Nessuna preferenza", "interested" to "Mi interessa", "hidden" to "Nascondi")) { selected = it }
            } }
        } }
        compose.onNodeWithText("Nessuna preferenza").performClick()
        onView(withText("Mi interessa")).inRoot(isDialog()).perform(click())
        compose.waitForIdle()
        assertEquals("interested", selected)
    }
}
