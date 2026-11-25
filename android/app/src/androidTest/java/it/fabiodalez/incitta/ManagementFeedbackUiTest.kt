package it.fabiodalez.incitta

import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.User
import it.fabiodalez.incitta.data.management.ManagementDestination
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.management.ManagementScreen
import java.util.concurrent.CopyOnWriteArrayList
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

/*
 * Commenti e recensioni (P7) con un server finto che ha l'area di gestione
 * accesa. I testi si leggono dalle risorse, mai scritti a mano.
 */
class ManagementFeedbackUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()
    private val requests = CopyOnWriteArrayList<String>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private val subjects = """{"data":{"venues":[{"id":12,"slug":"circolo-aurora","name":"Circolo Aurora","city":{"name":"Padova","timezone":"Europe/Rome"},"role":"editor","status":"approved",
        "permissions":{"comments_moderate":true,"reviews_reply":true}}],"organizers":[],"checkin_only":false}}"""
    private val comment = """{"id":5,"event":{"id":41,"title":"Concerto della sera","slug":"concerto"},"author":"Giulia Ferrari","body":"Messaggio fuori tema",
        "reactions_count":0,"status":"published","status_label":"Pubblicato","created_at":"2026-11-20T19:30:00+00:00","revision":1,"can_hide":true,"can_restore":false}"""
    private val hidden = """{"id":5,"event":{"id":41,"title":"Concerto della sera","slug":"concerto"},"author":"Giulia Ferrari","body":"Messaggio fuori tema",
        "reactions_count":0,"status":"hidden","status_label":"Nascosto","created_at":"2026-11-20T19:30:00+00:00","moderated_by":"Staff","moderated_at":"2026-11-21T08:00:00+00:00","revision":2,"can_hide":false,"can_restore":true}"""
    private val review = """{"id":7,"author":"Marco","rating":4,"body":"Cena ottima, servizio lento.","published_at":"2026-11-02T10:00:00+00:00","reply":null,"can_reply":true,"can_remove_reply":false,"can_report":false}"""
    private val replied = """{"id":7,"author":"Marco","rating":4,"body":"Cena ottima, servizio lento.","published_at":"2026-11-02T10:00:00+00:00",
        "reply":{"body":"Grazie della visita!","author":"Circolo Aurora","replied_at":"2026-11-03T10:00:00+00:00"},"can_reply":true,"can_remove_reply":true,"can_report":false}"""

    private fun show(destination: ManagementDestination? = null) {
        val client = ApiClient("management-feedback-test", OkHttpClient.Builder().addInterceptor { chain ->
            val request = chain.request()
            val body = request.body?.let { val buffer = okio.Buffer(); it.writeTo(buffer); buffer.readUtf8() }.orEmpty()
            requests += "${request.method} ${request.url.encodedPath}?${request.url.encodedQuery.orEmpty()} $body"
            val path = request.url.encodedPath
            val data = when {
                path.endsWith("/config") -> """{"data":{"features":{"management":true}}}"""
                path.endsWith("/management/subjects") -> subjects
                path.endsWith("/comments/5/hide") -> """{"data":$hidden,"meta":{"message":"Commento nascosto."}}"""
                path.endsWith("/comments") -> """{"data":[$comment],"meta":{"next_page":null,"total":1}}"""
                path.endsWith("/reviews/7/reply") -> """{"data":$replied,"meta":{"message":"Risposta pubblicata."}}"""
                path.endsWith("/reviews") -> """{"data":[$review],"meta":{"next_page":null,"total":1,"can_report":false}}"""
                else -> """{"data":{}}"""
            }
            Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(200).message("OK").body(data.toResponseBody("application/json".toMediaType())).build()
        }.build())
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { androidx.compose.material3.Surface {
            ManagementScreen(Session("feedback-session", User(9942, "Collaboratore", "staff@example.test"), null), PaddingValues(), client, destination) {}
        } } } }
    }

    private fun waitFor(label: String) = compose.waitUntil(10000) { compose.onAllNodesWithText(label).fetchSemanticsNodes().isNotEmpty() }

    @Test fun theCollaboratorHidesACommentWithANote() {
        show()
        waitFor(text(R.string.management_area_feedback))
        compose.onNodeWithText(text(R.string.management_area_feedback)).performScrollTo().performClick()
        waitFor(text(R.string.feedback_comments_title))
        compose.onNodeWithText(text(R.string.feedback_comments_title)).performScrollTo().performClick()
        waitFor("Messaggio fuori tema")
        compose.onNodeWithText(text(R.string.feedback_hide)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.feedback_hide_note)).performTextInput("Fuori tema")
        compose.onAllNodesWithText(text(R.string.feedback_hide)).onLast().performClick()
        waitFor(text(R.string.feedback_comment_hidden))
        compose.onNodeWithText(text(R.string.feedback_restore)).performScrollTo().assertExists()
        assertTrue(requests.any { it.startsWith("POST ") && it.contains("/management/venues/12/comments/5/hide") && it.contains("\"revision\":1") && it.contains("Fuori tema") })
    }

    @Test fun aLinkFromThePublicPageOpensTheReviewsAndTheReplyIsPublished() {
        show(ManagementDestination.Feedback(SubjectKind.Venue, "circolo-aurora", reviews = true))
        waitFor("Cena ottima, servizio lento.")
        compose.onNodeWithText(text(R.string.feedback_reply_write)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.feedback_reply_field)).performTextInput("Grazie della visita!")
        compose.onNodeWithText(text(R.string.feedback_reply_publish)).performClick()
        waitFor(text(R.string.feedback_reply_saved))
        compose.onNodeWithText(text(R.string.feedback_reply_edit)).performScrollTo().assertExists()
        assertTrue(requests.any { it.startsWith("PUT ") && it.contains("/management/venues/12/reviews/7/reply") && it.contains("Grazie della visita!") })
    }
}
