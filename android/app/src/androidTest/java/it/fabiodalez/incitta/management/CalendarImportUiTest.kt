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
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

/*
 * Il calendario collegato (P9) con un server finto: il referente incolla
 * l'indirizzo, guarda l'anteprima e solo dopo collega. I testi si leggono
 * dalle risorse, mai scritti a mano.
 */
class CalendarImportUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()
    private val requests = CopyOnWriteArrayList<String>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private val subjects = """{"data":{"venues":[{"id":12,"slug":"circolo-aurora","name":"Circolo Aurora","city":{"name":"Padova","timezone":"Europe/Rome"},"role":"owner","status":"approved",
        "permissions":{"import_manage":true}}],"organizers":[],"checkin_only":false}}"""
    private val empty = """{"source":null,"form":{"type":"ics","url":null,"exclude_keywords":["chiuso"]},"default_exclude_keywords":["chiuso"],"limits":{}}"""
    private val connected = """{"source":{"id":1,"type":"ics","type_label":"ICS","address":"https://calendario.test/eventi.ics","url":"https://calendario.test/eventi.ics",
        "is_upload":false,"is_active":true,"last_run_at":null,"last_status":null,"last_status_label":null,"last_error":null,"can_run":true,"can_update":true,"can_delete":true},
        "form":{"type":"ics","url":"https://calendario.test/eventi.ics","exclude_keywords":["chiuso"]},"default_exclude_keywords":["chiuso"],"limits":{}}"""

    private fun show() {
        val client = ApiClient("calendar-import-test", OkHttpClient.Builder().addInterceptor { chain ->
            val request = chain.request()
            val body = request.body?.let { val buffer = okio.Buffer(); it.writeTo(buffer); buffer.readUtf8() }.orEmpty()
            requests += "${request.method} ${request.url.encodedPath} $body"
            val path = request.url.encodedPath
            val data = when {
                path.endsWith("/config") -> """{"data":{"features":{"management":true}}}"""
                path.endsWith("/management/subjects") -> subjects
                path.endsWith("/import/preview") -> """{"data":{"rows":[{"title":"Jazz in cortile","when":"05/12/2026 21:00","where":"Cortile","notes":[]}],"error":null,"preview_token":"segno"}}"""
                path.endsWith("/import") && request.method == "POST" -> """{"data":$connected,"message":"Calendario collegato."}"""
                path.endsWith("/import") -> """{"data":$empty}"""
                else -> """{"data":{}}"""
            }
            Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(200).message("OK").body(data.toResponseBody("application/json".toMediaType())).build()
        }.build())
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { androidx.compose.material3.Surface {
            ManagementScreen(Session("calendar-session", User(9943, "Referente", "owner@example.test"), null), PaddingValues(), client, null) {}
        } } } }
    }

    private fun waitFor(label: String) = compose.waitUntil(10000) { compose.onAllNodesWithText(label).fetchSemanticsNodes().isNotEmpty() }

    @Test fun theOwnerConnectsOnlyAfterThePreview() {
        show()
        waitFor(text(R.string.management_area_import))
        compose.onNodeWithText(text(R.string.management_area_import)).performScrollTo().performClick()
        waitFor(text(R.string.calendar_import_none))
        compose.onNodeWithText(text(R.string.calendar_import_connect)).performScrollTo().assertIsNotEnabled()
        compose.onNodeWithText(text(R.string.calendar_import_form_url)).performScrollTo().performTextInput("https://calendario.test/eventi.ics")
        compose.onNodeWithText(text(R.string.calendar_import_preview)).performScrollTo().performClick()
        waitFor("Jazz in cortile")
        compose.onNodeWithText(text(R.string.calendar_import_connect)).performScrollTo().assertIsEnabled().performClick()
        waitFor("Calendario collegato.")
        compose.onNodeWithText(text(R.string.calendar_import_run_now)).performScrollTo().assertExists()
        assertTrue(requests.any { it.startsWith("POST ") && it.contains("/management/venues/12/import ") && it.contains("\"preview_token\":\"segno\"") })
    }
}
