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

    @Test fun genericRateLimitAsksToWaitAMinute() {
        val generic = ApiException(429, ApiProblem(message = "Troppe richieste: riprova fra poco.", code = "RATE_LIMITED"))
        assertTrue(requestFailureMessage(generic).contains("Attendi un minuto"))
        assertTrue(requestFailureMessage(ApiException(429, null)).contains("Attendi un minuto"))
    }

    @Test fun explainedRateLimitShowsTheServerMessage() {
        // Il tetto giornaliero di un recapito non passa in un minuto: vale il testo del server.
        val daily = ApiException(429, ApiProblem(message = "Questo recapito ha ricevuto troppi messaggi oggi. Riprova domani.", code = "CONTACT_DAILY_LIMIT"))
        assertEquals("Questo recapito ha ricevuto troppi messaggi oggi. Riprova domani.", requestFailureMessage(daily))
        val field = ApiException(429, ApiProblem(code = "RATE_LIMITED", fields = mapOf("message" to listOf("Riprova domani."))))
        assertEquals("Riprova domani.", requestFailureMessage(field))
        val validation = ApiException(422, ApiProblem(code = "VALIDATION_FAILED", fields = mapOf("message" to listOf("Riprova domani."))))
        assertEquals("Riprova domani.", requestFailureMessage(validation))
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
