package it.fabiodalez.incitta

import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class LoginFeedbackTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    private fun loginScreen() {
        compose.waitForIdle()
        if (compose.onAllNodesWithText("RIFIUTA").fetchSemanticsNodes().isNotEmpty()) {
            compose.onNodeWithText("RIFIUTA").performClick()
        }
        compose.onNode(hasText("PROFILO") and hasClickAction()).performClick()
    }

    private fun submit() = compose.onAllNodes(hasText("ACCEDI") and hasClickAction()).onLast().performScrollTo().performClick()

    @Test fun missingEmailIsVisibleAndClearsWhenEditing() {
        loginScreen()
        submit()
        val message = compose.activity.getString(R.string.auth_email_required)
        compose.onNodeWithText(message).assertIsDisplayed()
        compose.onNodeWithText("EMAIL").performTextInput("not-an-email")
        compose.onNodeWithText(message).assertDoesNotExist()
        submit()
        compose.onNodeWithText(compose.activity.getString(R.string.auth_email_invalid)).assertIsDisplayed()
    }

    @Test fun emptyPasswordHasAnExplanationInsteadOfDisabledButton() {
        loginScreen()
        compose.onNodeWithText("EMAIL").performTextInput("login-feedback@example.test")
        submit()
        compose.onNodeWithText(compose.activity.getString(R.string.auth_password_required)).assertIsDisplayed()
    }

    /** One deliberately invalid request, no real credentials or account changes. */
    @Test fun rejectedShortPasswordIsVisibleAndPersistent() {
        loginScreen()
        compose.onNodeWithText("EMAIL").performTextInput("login-feedback@example.test")
        compose.onNodeWithText("PASSWORD").performTextInput("wrong")
        submit()
        val message = compose.activity.getString(R.string.auth_invalid_credentials)
        compose.waitUntil(30_000) { compose.onAllNodesWithText(message).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(message).assertIsDisplayed()
        // Clearing the global snackbar must not clear the persistent form error.
        compose.mainClock.advanceTimeBy(6_000)
        compose.onNodeWithText(message).assertIsDisplayed()
        compose.onNodeWithText("PASSWORD").performTextInput("2")
        compose.onNodeWithText(message).assertDoesNotExist()
    }
}
