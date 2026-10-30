package it.fabiodalez.incitta.data

import kotlinx.coroutines.test.runTest
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.*
import org.junit.Test

/**
 * Il 403 con cui il server chiede l'email confermata prima di prenotare,
 * spedire un biglietto, chiedere il pass Wallet o scrivere a un locale.
 */
class EmailVerificationRequiredTest {
    private val body = """{"error":{"code":"EMAIL_VERIFICATION_REQUIRED","message":"Conferma il tuo indirizzo email per utilizzare il tuo account.","fields":{}}}"""

    /* Un client che risponde sempre allo stesso modo, senza rete. */
    private fun answering(status: Int, payload: String) = OkHttpClient.Builder().addInterceptor { chain ->
        Response.Builder().request(chain.request()).protocol(Protocol.HTTP_1_1).code(status).message("")
            .body(payload.toResponseBody("application/json".toMediaType())).build()
    }.build()

    @Test fun `the server envelope becomes a recognizable error with its message`() = runTest {
        val api = ApiClient("test", answering(403, body))
        val error = runCatching { api.post<ApiEnvelope<Booking>, Map<String, String>>("occurrences/1/bookings", emptyMap(), "token") }.exceptionOrNull()
        assertTrue(error is ApiException)
        assertTrue(requiresEmailVerification(error!!))
        assertEquals("Conferma il tuo indirizzo email per utilizzare il tuo account.", requestFailureMessage(error))
        assertFalse(shouldShowOffline(error, true))
    }

    @Test fun `requests without a body are read the same way`() = runTest {
        val api = ApiClient("test", answering(403, body))
        val error = runCatching { api.send("me/tickets/1/send", "POST", "token") }.exceptionOrNull()
        assertTrue(requiresEmailVerification(error!!))
    }

    @Test fun `only the 403 with that code asks for the confirmation`() {
        val problem = ApiProblem(message = "Conferma", code = EMAIL_VERIFICATION_REQUIRED)
        assertFalse(requiresEmailVerification(ApiException(401, problem)))
        assertFalse(requiresEmailVerification(ApiException(422, problem)))
        assertFalse(requiresEmailVerification(ApiException(403, ApiProblem(message = "Il tuo account è sospeso.", code = "FORBIDDEN"))))
        assertFalse(requiresEmailVerification(ApiException(403, null)))
        assertFalse(requiresEmailVerification(java.io.IOException()))
    }

    @Test fun `a missing server message still invites to confirm the address`() {
        val error = ApiException(403, ApiProblem(code = EMAIL_VERIFICATION_REQUIRED))
        assertEquals("Conferma il tuo indirizzo email per continuare.", requestFailureMessage(error))
    }

    @Test fun `other forbidden answers keep the server message`() {
        val error = ApiException(403, ApiProblem(message = "Il tuo account è sospeso dalla community: questa operazione non è disponibile.", code = "FORBIDDEN"))
        assertEquals("Il tuo account è sospeso dalla community: questa operazione non è disponibile.", requestFailureMessage(error))
    }
}
