package it.fabiodalez.incitta

import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assume.assumeTrue
import org.junit.Rule
import org.junit.Test

/** Explicit opt-in smoke test, no credentials in source or test output. */
class AdminLoginLiveTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    @Test fun administratorSignsInAndLoadsMap() {
        val args = InstrumentationRegistry.getArguments()
        val password = args.getString("adminLivePassword")
        assumeTrue(!password.isNullOrBlank())
        compose.waitForIdle()
        if (compose.onAllNodesWithText("RIFIUTA").fetchSemanticsNodes().isNotEmpty()) compose.onNodeWithText("RIFIUTA").performClick()
        compose.onNode(hasText("PROFILO") and hasClickAction()).performClick()
        compose.onNodeWithText("EMAIL").performTextInput("admin@incitta.test")
        compose.onNodeWithText("PASSWORD").performTextInput(requireNotNull(password))
        compose.onAllNodes(hasText("ACCEDI") and hasClickAction()).onLast().performScrollTo().performClick()
        compose.waitUntil(30_000) { compose.onAllNodesWithText("IL TUO PROFILO").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("admin@incitta.test").assertExists()
        compose.onNode(hasText("MAPPA") and hasClickAction()).performClick()
        compose.onNodeWithText("OGGI").assertExists()
        compose.onNodeWithText("Errore interno del server.").assertDoesNotExist()
        compose.onNode(hasText("PROFILO") and hasClickAction()).performClick()
        compose.onNodeWithText("ESCI DA QUESTO DISPOSITIVO").performScrollTo().performClick()
        compose.waitUntil(30_000) { compose.onAllNodesWithText("ENTRA IN CITTÀ").fetchSemanticsNodes().isNotEmpty() }
    }
}
