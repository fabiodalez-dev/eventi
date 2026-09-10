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
        compose.runOnUiThread {
            compose.activity.startActivity(android.content.Intent(compose.activity, MainActivity::class.java)
                .setData(android.net.Uri.parse("incitta://account"))
                .addFlags(android.content.Intent.FLAG_ACTIVITY_SINGLE_TOP))
        }
        compose.waitForIdle()
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

    @Test fun registrationOffersSeparateNamesAndRejectsMismatchedConfirmation() {
        loginScreen()
        compose.onNodeWithText("REGISTRATI").performClick()
        compose.onNodeWithText("NOME (FACOLTATIVO)").assertExists()
        compose.onNodeWithText(compose.activity.getString(R.string.auth_last_name)).assertExists()
        compose.onNodeWithText("EMAIL").performScrollTo().performTextInput("registration-feedback@example.test")
        compose.onNodeWithText("PASSWORD").performScrollTo().performTextInput("password-di-prova")
        compose.onNodeWithText(compose.activity.getString(R.string.auth_confirm_password)).performScrollTo().performTextInput("diversa")
        compose.onNodeWithText("CREA ACCOUNT").performScrollTo().performClick()
        // The IME closes while the persistent error panel is measured.
        compose.waitUntil(5_000) {
            runCatching { compose.onNodeWithText(compose.activity.getString(R.string.auth_confirmation_mismatch)).assertIsDisplayed() }.isSuccess
        }
        compose.onNodeWithText(compose.activity.getString(R.string.auth_confirmation_mismatch)).assertIsDisplayed()
        compose.onNodeWithText(compose.activity.getString(R.string.auth_privacy)).assertExists()
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
