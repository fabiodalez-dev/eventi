package it.fabiodalez.incitta.ui

import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Column
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.unit.dp
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import it.fabiodalez.incitta.AppUiState
import it.fabiodalez.incitta.MainActivity
import it.fabiodalez.incitta.data.EventDetail
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.User
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

class SafeScreensTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    @Test fun signedInProfileKeepsTitleBelowAndroidAndThemeControlsImmediatelyAvailable() {
        var selected = ""
        compose.runOnIdle { compose.activity.setContent {
            InCittaTheme { AppSafeArea {
                ProfileScreen(
                    state = AppUiState(session = Session("ui-only", User(1, "Giulia", "giulia@example.test"), null)),
                    padding = PaddingValues(0.dp),
                    onTickets = {}, onSaved = {}, onLogout = {}, onDeleteAccount = {}, onInterestsSaved = {},
                    onAppearance = { selected = it }, onProfileSaved = {}, onCommunity = {}, onCarpool = {},
                    onCarpoolMessages = {}, onCommunityInbox = {}, communityTotal = 0,
                )
            } }
        } }
        val title = compose.onNodeWithText("Il mio profilo").assertIsDisplayed().fetchSemanticsNode()
        val top = ViewCompat.getRootWindowInsets(compose.activity.window.decorView)!!.getInsets(WindowInsetsCompat.Type.statusBars()).top
        assertTrue("Title overlaps the status bar", title.boundsInRoot.top >= top)
        compose.onNodeWithText("Chiaro").assertIsDisplayed().performClick()
        assertEquals("light", selected)
    }

    @Test fun savedHeaderComesBeforeTheProfileLinkWithoutExtraTopPadding() {
        compose.runOnIdle { compose.activity.setContent {
            InCittaTheme { AppSafeArea {
                Column {
                BrandHeader(compact = true) { HeaderThemeSwitch("dark", {}) }
                SavedScreen(AppUiState(), PaddingValues(0.dp), {}, {}, onProfile = {})
                }
            } }
        } }
        val header = compose.onNodeWithText("CITTÀ").assertIsDisplayed().fetchSemanticsNode()
        val profile = compose.onNodeWithText("Il mio profilo").assertIsDisplayed().fetchSemanticsNode()
        assertTrue(header.boundsInRoot.top < profile.boundsInRoot.top)
    }

    @Test fun eventKeepsBrandedHeaderAndBackActionWhileContentScrolls() {
        compose.runOnIdle { compose.activity.setContent {
            InCittaTheme { AppSafeArea {
                Column {
                BrandHeader(compact = true, onBack = {}) { HeaderThemeSwitch("dark", {}) }
                CompleteEventDetailScreen(EventDetail(id = 1, slug = "test", title = "Concerto", description = "Descrizione ".repeat(100)), { error("No weather for this fixture") }, emptyList(), emptySet(), {}, {}, {}, {}, {}, {})
                }
            } }
        } }
        compose.onNodeWithText("CITTÀ").assertIsDisplayed()
        compose.onNodeWithText("DESCRIZIONE").performScrollTo()
        compose.onNodeWithText("CITTÀ").assertIsDisplayed()
        compose.onNodeWithContentDescription("Indietro").assertIsDisplayed()
        compose.onNodeWithContentDescription("Passa al tema chiaro").assertIsDisplayed()
    }
}
