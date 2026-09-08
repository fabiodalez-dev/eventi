package it.fabiodalez.incitta.data

import java.io.IOException
import org.junit.Assert.*
import org.junit.Test

class RequestFeedbackTest {
    @Test fun errorsDoNotExposeDebugCodesOrSensitiveDetails() {
        val error = IOException("sensitive URL and token", java.net.UnknownHostException("private host"))
        assertFalse(requestFailureMessage(error).contains("[DNS]"))
        assertFalse(requestFailureMessage(error).contains("private host"))
        assertFalse(requestFailureMessage(error).contains("sensitive"))
    }
    @Test fun sessionAndServerFailuresAreNotOffline() {
        for (status in listOf(401, 403, 429, 500, 503)) {
            assertFalse(shouldShowOffline(ApiException(status, null), true))
        }
        assertTrue(requestFailureMessage(ApiException(401, null)).contains("accedi di nuovo"))
        assertTrue(requestFailureMessage(ApiException(503, null)).contains("server"))
    }

    @Test fun invalidPayloadIsNotANetworkFailure() {
        val error = ApiPayloadException(IllegalArgumentException())
        assertFalse(shouldShowOffline(error, true))
        assertTrue(requestFailureMessage(error).contains("Aggiorna"))
    }

    @Test fun offlineRequiresNetworkFailureAndCachedEvents() {
        assertTrue(shouldShowOffline(IOException(), true))
        assertFalse(shouldShowOffline(IOException(), false))
        assertTrue(requestFailureMessage(IOException()).contains("Wi-Fi"))
    }
}
