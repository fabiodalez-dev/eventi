package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.coroutines.test.runTest
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Request
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.*
import org.junit.Test

/*
 * Le scritture dei moduli che l'app manda da sola portano la chiave della
 * bozza come `Idempotency-Key`: il server risponde a un invio ripetuto con la
 * risposta del primo. Senza chiave la richiesta è quella di prima.
 */
class ApplicationSendKeyTest {
    private val seen = CopyOnWriteArrayList<Request>()
    private val client = OkHttpClient.Builder().addInterceptor { chain ->
        seen += chain.request()
        Response.Builder().request(chain.request()).protocol(Protocol.HTTP_1_1).code(201).message("")
            .body("""{"data":{"state":"queued","message":"ok","application":{"id":9}}}""".toResponseBody("application/json".toMediaType())).build()
    }.build()
    private val api = ManagementApplicationsApi(ApiClient("installazione-di-prova-123", client), "token")

    @Test fun everyAutomaticWriteCarriesTheKeyOfTheDraft() = runTest {
        api.submitVenue(VenueApplicationDraft(venueName = "Circolo", address = "Via Roma 1", contactName = "Marta"), null, "chiave-della-bozza-0001")
        api.submitVenue(VenueApplicationDraft(venueName = "Circolo", address = "Via Roma 1", contactName = "Marta"), PreparedImage(ByteArray(10), 800, 600), "chiave-della-bozza-0002")
        api.claim("osteria-ponte", ClaimDraft(contactName = "Marta", message = "Sono la titolare dal 2019."), "chiave-della-bozza-0003")
        val sent = api.submitOrganizer(OrganizerApplicationDraft(name = "Collettivo", contactName = "Marta"), "chiave-della-bozza-0004")

        assertEquals(9L, sent.application.id)
        assertEquals(listOf("chiave-della-bozza-0001", "chiave-della-bozza-0002", "chiave-della-bozza-0003", "chiave-della-bozza-0004"), seen.map { it.header("Idempotency-Key") })
        assertEquals(listOf("/me/venue-applications", "/me/venue-applications", "/venues/osteria-ponte/claim", "/me/organizer-applications"), seen.map { "/" + it.url.encodedPath.substringAfter("/v1/") })
        assertTrue(seen[1].body?.contentType()?.toString().orEmpty().startsWith("multipart/form-data"))
        assertTrue(seen.all { it.header("Authorization") == "Bearer token" })
    }

    @Test fun withoutAKeyTheRequestIsTheOneOfBefore() = runTest {
        api.submitVenue(VenueApplicationDraft(venueName = "Circolo"), null)
        api.claim("osteria-ponte", ClaimDraft(message = "Sono la titolare dal 2019."))
        api.submitOrganizer(OrganizerApplicationDraft(name = "Collettivo"))

        assertTrue(seen.all { it.header("Idempotency-Key") == null })
    }
}
