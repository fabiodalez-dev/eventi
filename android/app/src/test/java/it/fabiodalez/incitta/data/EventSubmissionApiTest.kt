package it.fabiodalez.incitta.data

import it.fabiodalez.incitta.BuildConfig
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Request
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.*
import org.junit.Test

/* Q1: le chiamate di «Proponi un evento» con un server finto, e i collegamenti che aprono la schermata nativa. */
class EventSubmissionApiTest {
    private val seen = CopyOnWriteArrayList<Pair<Request, String>>()

    private fun answering(status: Int, payload: String) = OkHttpClient.Builder().addInterceptor { chain ->
        val request = chain.request()
        val body = request.body?.let { val buffer = okio.Buffer(); it.writeTo(buffer); buffer.readUtf8() }.orEmpty()
        seen += request to body
        Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(status).message("")
            .body(payload.toResponseBody("application/json".toMediaType())).build()
    }.build()

    @Test fun theProposalCarriesItsOwnKeyAndOnlyTheFilledFields() = runTest {
        val api = EventSubmissionApi(ApiClient("installazione-di-prova-123", answering(201,
            """{"data":{"id":41,"status":"pending","message":"Proposta ricevuta: la redazione la valuterà prima della pubblicazione.","created_at":"2026-11-26T10:00:00+01:00"}}""")))
        val receipt = api.submit(EventSubmissionBody(title = "Jazz in cortile", startsAtHint = "2026-12-05T21:00:00+01:00", venueId = 5, contactEmail = "marta@esempio.it"), "chiave-della-proposta-0001")

        assertEquals(41L, receipt.id)
        assertEquals("pending", receipt.status)
        assertEquals("Proposta ricevuta: la redazione la valuterà prima della pubblicazione.", receipt.message)
        val (request, body) = seen.single()
        assertEquals("POST", request.method)
        assertTrue(request.url.encodedPath.endsWith("/submissions"))
        assertEquals("padova", request.url.queryParameter("city"))
        assertEquals("chiave-della-proposta-0001", request.header("Idempotency-Key"))
        assertEquals("installazione-di-prova-123", request.header("X-Installation-ID"))
        /* La proposta è pubblica: niente token, la riconosce l'installazione. */
        assertNull(request.header("Authorization"))
        val sent = Json.parseToJsonElement(body).jsonObject
        assertEquals(setOf("title", "starts_at_hint", "venue_id", "contact_email"), sent.keys)
        assertEquals("2026-12-05T21:00:00+01:00", sent["starts_at_hint"]?.jsonPrimitive?.content)
        assertEquals("5", sent["venue_id"]?.jsonPrimitive?.content)
    }

    @Test fun theServerRulesComeBackFieldByField() = runTest {
        val api = EventSubmissionApi(ApiClient("installazione-di-prova-123", answering(422,
            """{"error":{"code":"VALIDATION_FAILED","message":"Dati non validi","fields":{"contact_email":["Il campo email deve essere un indirizzo valido."]}}}""")))
        val error = runCatching { api.submit(EventSubmissionBody(title = "Jazz", contactEmail = "marta@"), "chiave-della-proposta-0002") }.exceptionOrNull()
        assertTrue(error is ApiException)
        assertEquals(422, (error as ApiException).status)
        assertEquals(listOf("Il campo email deve essere un indirizzo valido."), error.problem?.fields?.get("contact_email"))
    }

    @Test fun theVenuesAreThoseOfTheCityWithTheSearch() = runTest {
        val api = EventSubmissionApi(ApiClient("installazione-di-prova-123", answering(200,
            """{"data":[{"id":5,"slug":"caffe-pedrocchi","name":"Caffè Pedrocchi","municipality":"Padova","zone":"Centro","upcoming_occurrences":3}],"meta":{"next_cursor":null}}""")))
        val venues = api.venues("  caffè ")
        assertEquals(listOf(SubmissionVenue(5, "Caffè Pedrocchi", "Padova", "Centro")), venues)
        val request = seen.single().first
        assertTrue(request.url.encodedPath.endsWith("/venues"))
        assertEquals("padova", request.url.queryParameter("city"))
        assertEquals("caffè", request.url.queryParameter("q"))
        assertEquals("50", request.url.queryParameter("limit"))

        api.venues("")
        assertNull(seen.last().first.url.queryParameter("q"))
    }

    @Test fun theCityGivesNameAndTimezone() = runTest {
        val api = EventSubmissionApi(ApiClient("installazione-di-prova-123", answering(200,
            """{"data":{"id":1,"slug":"padova","name":"Padova","timezone":"Europe/Rome","center":{"lat":45.4,"lng":11.8}}}""")))
        assertEquals(SubmissionCity("padova", "Padova", "Europe/Rome"), api.city())
        assertTrue(seen.single().first.url.encodedPath.endsWith("/cities/padova"))
    }

    @Test fun theSiteFormLinkOpensTheNativeScreen() {
        val site = BuildConfig.API_BASE_URL.substringBefore("/api/")
        assertTrue(isEventSubmissionLink("$site/proponi-evento", BuildConfig.API_BASE_URL))
        assertTrue(isEventSubmissionLink("$site/proponi-evento/", BuildConfig.API_BASE_URL))
        assertTrue(isEventSubmissionLink("$site/proponi-evento?utm_source=newsletter", BuildConfig.API_BASE_URL))
        assertFalse(isEventSubmissionLink("$site/proponi-evento/altro", BuildConfig.API_BASE_URL))
        assertFalse(isEventSubmissionLink("$site/eventi/proponi-evento", BuildConfig.API_BASE_URL))
        assertFalse(isEventSubmissionLink("https://altro.example/proponi-evento", BuildConfig.API_BASE_URL))
        assertFalse(isEventSubmissionLink("not a url", BuildConfig.API_BASE_URL))
    }

}
