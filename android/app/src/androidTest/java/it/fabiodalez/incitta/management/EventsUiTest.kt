package it.fabiodalez.incitta.management

import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.MainActivity
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.User
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.management.ManagementScreen
import java.util.concurrent.CopyOnWriteArrayList
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * Gli eventi della gestione con un server finto: dal menu del locale al
 * modulo, «Salva come bozza» e poi «Pubblica» sulla stessa bozza. I testi si
 * leggono dalle risorse.
 */
class EventsUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()
    private val bodies = CopyOnWriteArrayList<String>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private fun detail(status: String) =
        """{"id":50,"title":"Jazz al circolo","status":{"value":"$status","label":"x"},"price_type":{"value":"free","label":"Ingresso libero"},"abilities":{"update":true}}"""

    @Test fun aVenueSavesADraftAndPublishesIt() {
        val client = ApiClient("events-ui-test", OkHttpClient.Builder().addInterceptor { chain ->
            val request = chain.request()
            val body = request.body?.let { val buffer = okio.Buffer(); it.writeTo(buffer); buffer.readUtf8() }
            if (request.method == "POST" && body != null) bodies += body
            val path = request.url.encodedPath
            val (code, data) = when {
                path.endsWith("/config") -> 200 to """{"data":{"features":{"management":true}}}"""
                path.endsWith("/management/subjects") -> 200 to """{"data":{"venues":[{"id":12,"name":"Circolo Aurora","role":"owner","city":{"name":"Padova","timezone":"Europe/Rome"},"permissions":{"events_create":true,"events_publish_directly":true}}],"organizers":[]}}"""
                path.endsWith("/management/options") -> 200 to """{"data":{}}"""
                path.endsWith("/events/form") -> 200 to """{"data":{"categories":[{"id":4,"name":"Musica dal vivo"}],"defaults":{"category_id":4,"price_type":"free"},"city":{"id":1,"name":"Padova","timezone":"Europe/Rome"}}}"""
                request.method == "POST" && path.endsWith("/events") && body!!.contains("\"publish\":true") -> 201 to """{"data":${detail("published")},"meta":{"outcome":"published"}}"""
                request.method == "POST" && path.endsWith("/events") -> 201 to """{"data":${detail("draft")},"meta":{"outcome":"draft"}}"""
                path.endsWith("/events/50") -> 200 to """{"data":${detail("published")}}"""
                path.contains("/events") -> 200 to """{"data":[],"meta":{"next_page":null,"total":0}}"""
                else -> 200 to """{"data":{}}"""
            }
            Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(code).message("OK").body(data.toResponseBody("application/json".toMediaType())).build()
        }.build())
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { androidx.compose.material3.Surface {
            ManagementScreen(Session("events-ui-session", User(9942, "Referente", "referente@example.test"), null), PaddingValues(), client) {}
        } } } }

        compose.waitUntil(10000) { compose.onAllNodesWithText(text(R.string.management_area_events)).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.management_area_events)).performScrollTo().performClick()
        compose.waitUntil(10000) { compose.onAllNodesWithText(text(R.string.management_events_empty)).fetchSemanticsNodes().isNotEmpty() }
        compose.onAllNodesWithText(text(R.string.management_events_new))[0].performClick()
        compose.waitUntil(10000) { compose.onAllNodesWithText(text(R.string.management_events_save_draft)).fetchSemanticsNodes().isNotEmpty() }

        compose.onNodeWithText(text(R.string.management_events_field_title)).performScrollTo().performTextInput("Jazz al circolo")
        compose.onNodeWithText(text(R.string.management_events_save_draft)).performScrollTo().performClick()
        compose.waitUntil(10000) { bodies.any { it.contains("Jazz al circolo") && it.contains("\"publish\":false") } }

        compose.onNodeWithText(text(R.string.management_events_publish)).performScrollTo().performClick()
        compose.waitUntil(10000) { bodies.any { it.contains("\"publish\":true") && it.contains("\"draft_id\":50") } }
        assertTrue(bodies.none { it.contains("\"draft_id\":") && !it.contains("50") })
    }
}
