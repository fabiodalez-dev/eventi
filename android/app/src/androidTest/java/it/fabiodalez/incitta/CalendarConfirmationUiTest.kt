package it.fabiodalez.incitta

import androidx.activity.compose.setContent
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.Column
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.data.*
import it.fabiodalez.incitta.calendar.NativeCalendar
import it.fabiodalez.incitta.ui.CalendarSubscriptionPanel
import it.fabiodalez.incitta.ui.InCittaTheme
import org.junit.Rule
import org.junit.Test
import org.junit.Assert.assertFalse

class CalendarConfirmationUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    @Test fun asksBeforeAddingAndCancelDoesNotConnectAnything() {
        val store = LocalStore(compose.activity)
        try {
            NativeCalendar.disconnect(compose.activity)
            store.writeSession(Session("confirmation-test", User(990, "Test", "test@example.test"), "2099-01-01T00:00:00Z"))
            compose.runOnIdle { compose.activity.setContent {
                InCittaTheme { Column(Modifier.verticalScroll(rememberScrollState())) { CalendarSubscriptionPanel(initiallyExpanded = true) } }
            } }
            compose.waitUntil(15_000) { compose.onAllNodesWithText("Altre opzioni: calendario del telefono").fetchSemanticsNodes().isNotEmpty() }
            compose.onNodeWithText("Altre opzioni: calendario del telefono").performScrollTo().performClick()
            compose.onNodeWithText("Collega al calendario del telefono").performScrollTo()
            compose.waitUntil(15_000) { compose.onAllNodes(hasText("Collega al calendario del telefono") and isEnabled()).fetchSemanticsNodes().isNotEmpty() }
            compose.onNodeWithText("Collega al calendario del telefono").performClick()
            // Dialogs use a separate Android window; wait for it to be laid out
            // after the click rather than treating its first frame as a failure.
            compose.waitUntil(5_000) {
                runCatching { compose.onNodeWithText("Aggiungere il calendario inCittà?").assertIsDisplayed() }.isSuccess
            }
            compose.onNodeWithText("Aggiungere il calendario inCittà?").assertIsDisplayed()
            compose.onNodeWithText("Annulla").performClick()
            assertFalse(NativeCalendar.enabled(compose.activity))
        } finally { store.clearSession() }
    }
}
