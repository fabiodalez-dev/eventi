package it.fabiodalez.incitta.management

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.User
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.submission.EventSubmissionRoute
import java.util.concurrent.CopyOnWriteArrayList
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

/*
 * «Proponi un evento» (Q1) con un server finto: i due campi obbligatori si
 * controllano prima di inviare, il locale si sceglie dall'elenco della città,
 * la proposta parte con la sua chiave e la conferma dice che non è pubblicata.
 * I testi si leggono dalle risorse, mai scritti a mano.
 */
class EventSubmissionUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private val requests = CopyOnWriteArrayList<String>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private fun show(session: Session?) {
        val client = ApiClient("event-submission-test", OkHttpClient.Builder().addInterceptor { chain ->
            val request = chain.request()
            val body = request.body?.let { val buffer = okio.Buffer(); it.writeTo(buffer); buffer.readUtf8() }.orEmpty()
            requests += "${request.method} ${request.url.encodedPath}?${request.url.encodedQuery} key=${request.header("Idempotency-Key")} $body"
            val path = request.url.encodedPath
            val (code, data) = when {
                path.endsWith("/cities/padova") -> 200 to """{"data":{"id":1,"slug":"padova","name":"Padova","timezone":"Europe/Rome"}}"""
                path.endsWith("/venues") -> 200 to """{"data":[{"id":5,"slug":"caffe-pedrocchi","name":"Caffè Pedrocchi","municipality":"Padova","zone":"Centro"},{"id":8,"slug":"circolo-aurora","name":"Circolo Aurora","municipality":"Padova"}],"meta":{}}"""
                path.endsWith("/submissions") -> 201 to """{"data":{"id":41,"status":"pending","message":"Proposta ricevuta: la redazione la valuterà prima della pubblicazione."}}"""
                else -> 200 to """{"data":{}}"""
            }
            Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(code).message("OK").body(data.toResponseBody("application/json".toMediaType())).build()
        }.build())
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { androidx.compose.material3.Surface {
            EventSubmissionRoute(session, PaddingValues(), onBack = {}, client = client)
        } } } }
    }

    private fun waitFor(label: String, substring: Boolean = false) =
        compose.waitUntil(10000) { compose.onAllNodesWithText(label, substring = substring).fetchSemanticsNodes().isNotEmpty() }

    @Test fun aGuestProposesAnEventWithAVenueFromTheList() {
        show(null)
        waitFor(text(R.string.event_submission_lead, "Padova"))

        compose.onNodeWithText(text(R.string.event_submission_submit)).performScrollTo().performClick()
        waitFor(text(R.string.event_submission_title_required))
        compose.onNodeWithText(text(R.string.event_submission_email_required)).assertExists()
        assertTrue(requests.none { it.startsWith("POST ") })

        compose.onNodeWithText(text(R.string.event_submission_field_title), substring = true).performScrollTo().performTextInput("Jazz in cortile")
        compose.onNodeWithText(text(R.string.event_submission_venue_choose)).performScrollTo().performClick()
        waitFor("Caffè Pedrocchi")
        compose.onNodeWithText("Caffè Pedrocchi").performClick()
        compose.waitUntil(10000) { compose.onAllNodesWithText(text(R.string.event_submission_venue_clear)).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.event_submission_field_contact_email), substring = true).performScrollTo().performTextInput("marta@esempio.it")
        compose.onNodeWithText(text(R.string.event_submission_submit)).performScrollTo().performClick()

        waitFor(text(R.string.event_submission_sent_title))
        compose.onNodeWithText(text(R.string.event_submission_sent_body)).assertExists()
        compose.onNodeWithText("Proposta ricevuta: la redazione la valuterà prima della pubblicazione.").assertExists()
        val post = requests.single { it.startsWith("POST ") }
        assertTrue(post, post.contains("/submissions?city=padova"))
        assertTrue(post, Regex("key=[0-9a-f-]{36} ").containsMatchIn(post))
        assertTrue(post, post.contains("\"title\":\"Jazz in cortile\"") && post.contains("\"venue_id\":5") && post.contains("\"contact_email\":\"marta@esempio.it\""))

        compose.onNodeWithText(text(R.string.event_submission_sent_another)).performScrollTo().performClick()
        waitFor(text(R.string.event_submission_submit))
        /* L'email resta, il resto riparte da capo. */
        compose.onNodeWithText("marta@esempio.it").assertExists()
        compose.onNodeWithText(text(R.string.event_submission_venue_choose)).assertExists()
    }

    @Test fun aSignedInPersonFindsTheEmailAlreadyWritten() {
        show(Session("submission-session", User(9944, "Marta Rossi", "marta@esempio.it"), null))
        waitFor(text(R.string.event_submission_lead, "Padova"))
        compose.onNodeWithText("marta@esempio.it").performScrollTo().assertExists()
        compose.onNodeWithText("Marta Rossi").performScrollTo().assertExists()
        assertEquals(0, requests.count { it.startsWith("POST ") })
    }
}
