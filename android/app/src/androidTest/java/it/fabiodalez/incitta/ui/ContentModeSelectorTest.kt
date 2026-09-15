package it.fabiodalez.incitta.ui

import androidx.activity.compose.setContent
import androidx.compose.runtime.*
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.MainActivity
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

class ContentModeSelectorTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    @Test fun labelChangesSelectionAndDisabledRowsCannotChangeIt() {
        var mode by mutableStateOf("all")
        var enabled by mutableStateOf(true)
        compose.runOnIdle { compose.activity.setContent {
            InCittaTheme { AppSafeArea { ContentModeSelector(mode, enabled) { mode = it } } }
        } }
        compose.onNodeWithText("Solo le categorie che mi interessano").performClick().assertIsSelected()
        assertEquals("selected", mode)
        compose.onNodeWithText("Tutto, tranne ciò che nascondo").performClick().assertIsSelected()
        assertEquals("all", mode)
        compose.runOnIdle { enabled = false }
        compose.onNodeWithText("Solo le categorie che mi interessano").assertIsNotEnabled().performClick()
        assertEquals("all", mode)
    }
}
