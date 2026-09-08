package it.fabiodalez.incitta.data

import it.fabiodalez.incitta.R
import java.io.IOException
import org.junit.Assert.*
import org.junit.Test

class AuthFeedbackTest {
    @Test fun credentialsDoNotRevealAccountExistence() {
        for (status in listOf(401, 422)) {
            assertEquals(R.string.auth_invalid_credentials, loginFailureMessage(ApiException(status, ApiProblem(message = "Private detail"))))
        }
    }

    @Test fun networkThrottleAndServerErrorsAreDistinct() {
        assertEquals(R.string.auth_connection_failed, loginFailureMessage(IOException("internal network details")))
        assertEquals(R.string.auth_rate_limited, loginFailureMessage(ApiException(429, null)))
        assertEquals(R.string.auth_server_unavailable, loginFailureMessage(ApiException(503, null)))
        assertEquals(R.string.auth_failed, loginFailureMessage(IllegalStateException()))
    }

    @Test fun emptyAndMalformedFieldsExplainWhatToFix() {
        assertEquals(R.string.auth_email_required, authValidationMessage("", "", false))
        assertEquals(R.string.auth_email_invalid, authValidationMessage("not-an-email", "secret", false))
        assertEquals(R.string.auth_password_required, authValidationMessage("a@example.test", "", false))
    }

    @Test fun loginDoesNotEnforceRegistrationPasswordLength() {
        assertNull(authValidationMessage(" a@example.test ", "short", false))
        assertEquals(R.string.auth_password_short, authValidationMessage("a@example.test", "short", true))
        assertNull(authValidationMessage("a@example.test", "long-password", true))
    }

    @Test fun magicLinkNeedsEmailButNotPassword() {
        assertNull(authValidationMessage("a@example.test", "", false, magic = true))
        assertEquals(R.string.auth_email_required, authValidationMessage("", "", false, magic = true))
    }
}
