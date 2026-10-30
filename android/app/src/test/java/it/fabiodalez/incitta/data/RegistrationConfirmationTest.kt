package it.fabiodalez.incitta.data

import it.fabiodalez.incitta.R
import kotlinx.coroutines.test.runTest
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import okio.Buffer
import org.junit.Assert.*
import org.junit.Test

/**
 * La registrazione che non rivela se un indirizzo è già registrato (D64): 202
 * «Ti abbiamo scritto» senza token, la sfida PKCE nella richiesta e il 403
 * `EMAIL_CONFIRMATION_PENDING` dell'accesso a un account mai confermato.
 */
class RegistrationConfirmationTest {
    private val sent = """{"data":{"message":"Ti abbiamo scritto: apri il messaggio per entrare."}}"""
    private val pending = """{"error":{"code":"EMAIL_CONFIRMATION_PENDING","message":"Prima conferma l'indirizzo: ti abbiamo appena scritto un collegamento per entrare.","fields":{}}}"""

    /* Un client che risponde sempre allo stesso modo e ricorda il corpo della richiesta, senza rete. */
    private class Answering(private val status: Int, private val payload: String) {
        var requestBody: String? = null
        val client: OkHttpClient = OkHttpClient.Builder().addInterceptor { chain ->
            requestBody = chain.request().body?.let { body -> Buffer().also(body::writeTo).readUtf8() }
            Response.Builder().request(chain.request()).protocol(Protocol.HTTP_1_1).code(status).message("")
                .body(payload.toResponseBody("application/json".toMediaType())).build()
        }.build()
    }

    @Test fun `the 202 of the registration is a success with a message and no session`() = runTest {
        val server = Answering(202, sent)
        val api = ApiClient("test", server.client)
        val body = RegisterBody(null, "nuovo@example.test", "Password-Scelta-123", "Password-Scelta-123", "Telefono", codeChallenge = "c".repeat(43))

        val response = api.post<ApiEnvelope<ApiMessage>, RegisterBody>("auth/register", body)

        assertEquals("Ti abbiamo scritto: apri il messaggio per entrare.", response.data.message)
        assertTrue(server.requestBody!!.contains("\"code_challenge\":\"${"c".repeat(43)}\""))
    }

    @Test fun `the 202 body cannot be read as a session`() = runTest {
        val api = ApiClient("test", Answering(202, sent).client)
        val error = runCatching { api.post<ApiEnvelope<AuthPayload>, Map<String, String>>("auth/register", emptyMap()) }.exceptionOrNull()
        assertTrue(error is ApiPayloadException)
    }

    @Test fun `login sends the installation challenge only when given`() {
        val json = ApiClient("test").json
        assertTrue(json.encodeToString(LoginBody.serializer(), LoginBody("a@example.test", "secret", "Telefono", "d".repeat(43))).contains("\"code_challenge\""))
        assertFalse(json.encodeToString(LoginBody.serializer(), LoginBody("a@example.test", "secret", "Telefono")).contains("code_challenge"))
    }

    @Test fun `the pending confirmation answer of the login is recognized`() = runTest {
        val api = ApiClient("test", Answering(403, pending).client)
        val error = runCatching { api.post<ApiEnvelope<AuthPayload>, LoginBody>("auth/login", LoginBody("a@example.test", "secret", "Telefono", "d".repeat(43))) }.exceptionOrNull()
        assertTrue(error is ApiException)
        assertTrue(awaitsEmailConfirmation(error!!))
        assertFalse(requiresEmailVerification(error))
    }

    @Test fun `only the 403 with that code opens check your mail`() {
        val problem = ApiProblem(message = "Conferma", code = EMAIL_CONFIRMATION_PENDING)
        assertTrue(awaitsEmailConfirmation(ApiException(403, problem)))
        assertFalse(awaitsEmailConfirmation(ApiException(401, problem)))
        assertFalse(awaitsEmailConfirmation(ApiException(403, ApiProblem(code = EMAIL_VERIFICATION_REQUIRED))))
        assertFalse(awaitsEmailConfirmation(ApiException(403, null)))
        assertFalse(awaitsEmailConfirmation(java.io.IOException()))
    }

    @Test fun `wrong credentials keep the message that does not reveal the account`() {
        assertEquals(R.string.auth_invalid_credentials, loginFailureMessage(ApiException(401, ApiProblem(code = "INVALID_CREDENTIALS"))))
    }

    @Test fun `check your mail remembers where it came from`() {
        assertFalse(PendingEmailConfirmation("nuovo@example.test").fromLogin)
        assertTrue(PendingEmailConfirmation("nuovo@example.test", fromLogin = true).fromLogin)
    }
}
