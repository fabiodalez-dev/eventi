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

    private fun dismissConsent() {
        compose.waitForIdle()
        if (compose.onAllNodesWithText("RIFIUTA").fetchSemanticsNodes().isNotEmpty()) {
            compose.onNodeWithText("RIFIUTA").performClick()
        }
    }

    private fun revealNavigation() {
        compose.onRoot().performTouchInput { swipeUp() }
        compose.waitForIdle()
        compose.onRoot().performTouchInput { swipeDown() }
        compose.waitForIdle()
    }

    @Test fun guestSavedOpensProfileLogin() {
        dismissConsent()
        revealNavigation()
        compose.onNode(hasText("SALVATI") and hasClickAction()).performClick()
        compose.onNodeWithText("EMAIL").assertExists()
        compose.onNodeWithText("REGISTRATI").assertExists()
        compose.onNodeWithText(compose.activity.getString(R.string.calendar_export_saved)).assertDoesNotExist()
    }

    @Test fun homeBannerOpensCalendarConfiguration() {
        dismissConsent()
        compose.onAllNodes(hasScrollAction()).onFirst().performScrollToNode(hasText(compose.activity.getString(R.string.calendar_banner_title)))
        compose.onNodeWithText(compose.activity.getString(R.string.calendar_customize)).performScrollTo().assertIsDisplayed().performClick()
        // Calendar configuration is a lazy item below the month header.
        compose.onAllNodes(hasScrollAction()).onFirst().performScrollToNode(hasText(compose.activity.getString(R.string.calendar_sync_help)))
        compose.onNodeWithText(compose.activity.getString(R.string.calendar_sync_help)).assertIsDisplayed()
    }

    @Test fun liveSearchAndMapKeepBottomNavigationAvailable() {
        compose.waitForIdle()
        if (compose.onAllNodesWithText("RIFIUTA").fetchSemanticsNodes().isNotEmpty()) {
            compose.onNodeWithText("RIFIUTA").performClick()
        }
        revealNavigation()
        compose.onNode(hasText("CERCA") and hasClickAction()).performClick()
        compose.onNodeWithText("EVENTO, LUOGO, CATEGORIA").performTextInput("teatro")
        compose.onNodeWithText(compose.activity.getString(R.string.search_live_help)).assertExists()
        // The app intentionally hides bottom navigation while the IME is open.
        androidx.test.espresso.Espresso.closeSoftKeyboard()
        compose.onNodeWithText("CERCA").performTouchInput { click() }
        compose.waitUntil(5_000) {
            compose.onAllNodes(hasText("MAPPA") and hasClickAction()).fetchSemanticsNodes().isNotEmpty()
        }
        compose.onNode(hasText("MAPPA") and hasClickAction()).performClick()
        compose.onNodeWithText("OGGI").assertExists()
        compose.onNodeWithText("MAPPA").performTouchInput { click() }
        compose.onNode(hasText("CERCA") and hasClickAction()).assertExists()
        compose.onNodeWithText("OGGI").assertExists()
    }
}
