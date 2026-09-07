package it.fabiodalez.incitta

import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class NavigationSmokeTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    @Test fun liveSearchAndMapKeepBottomNavigationAvailable() {
        compose.waitForIdle()
        if (compose.onAllNodesWithText("RIFIUTA").fetchSemanticsNodes().isNotEmpty()) {
            compose.onNodeWithText("RIFIUTA").performClick()
        }
        compose.onNode(hasText("CERCA") and hasClickAction()).performClick()
        compose.onNodeWithText("EVENTO, LUOGO, CATEGORIA").performTextInput("teatro")
        compose.onNodeWithText(compose.activity.getString(R.string.search_live_help)).assertExists()
        compose.onNode(hasText("MAPPA") and hasClickAction()).performClick()
        compose.onNodeWithText("CERCA").assertExists()
        compose.onNodeWithText("OGGI").assertExists()
    }
}
