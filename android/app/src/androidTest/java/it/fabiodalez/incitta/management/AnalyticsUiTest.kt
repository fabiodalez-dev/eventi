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
 * Le statistiche della gestione con un server finto: dal menu del locale a
 * «Statistiche», con i riquadri, la card «Cosa contano questi numeri» e il
 * cambio di periodo. I testi si leggono dalle risorse.
 */
class AnalyticsUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private val paths = CopyOnWriteArrayList<String>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    @Test fun aVenueOpensItsStatisticsAndChangesThePeriod() {
        val client = ApiClient("analytics-ui-test", OkHttpClient.Builder().addInterceptor { chain ->
            val request = chain.request()
            val path = request.url.encodedPath
            paths += path + "?" + request.url.encodedQuery.orEmpty()
            val data = when {
                path.endsWith("/config") -> """{"data":{"features":{"management":true}}}"""
                path.endsWith("/management/subjects") -> """{"data":{"venues":[{"id":12,"name":"Circolo Aurora","role":"owner","city":{"name":"Padova","timezone":"Europe/Rome"},"permissions":{"analytics_view":true,"analytics_channels":true}}],"organizers":[]}}"""
                path.endsWith("/analytics/summary") -> """{"data":{"period":{"value":"month","days":30,"from":"2026-08-12","until":"2026-09-10"},
                    "periods":[{"value":"week","label":"7 giorni"},{"value":"month","label":"30 giorni"},{"value":"quarter","label":"90 giorni"}],
                    "cards":[{"key":"event_views","label":"Aperture eventi","value":1234,"hint":"Visite alle pagine dei tuoi eventi."}],
                    "empty":false,"series":[{"date":"2026-09-09","views":3,"profile_views":7,"clicks":1},{"date":"2026-09-10","views":10,"profile_views":2,"clicks":0}],
                    "interactions":[{"key":"phone_clicks","label":"Telefono","value":4}],"profile":[],"details":[],
                    "events":{"columns":[],"data":[],"meta":{"page":1,"next_page":null,"total":0}},"shares":{"data":[],"meta":{"page":1,"next_page":null,"total":0}}}}"""
                else -> """{"data":{}}"""
            }
            Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(200).message("OK").body(data.toResponseBody("application/json".toMediaType())).build()
        }.build())
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { androidx.compose.material3.Surface {
            ManagementScreen(Session("analytics-ui-session", User(9943, "Referente", "referente@example.test"), null), PaddingValues(), client) {}
        } } } }

        compose.waitUntil(10000) { compose.onAllNodesWithText(text(R.string.management_area_analytics)).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.management_area_analytics)).performScrollTo().performClick()
        compose.waitUntil(10000) { compose.onAllNodesWithText(text(R.string.analytics_summary_help)).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.analytics_summary_help)).performClick()

        compose.waitUntil(10000) { compose.onAllNodesWithText(text(R.string.analytics_consent_title)).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.analytics_consent_title)).assertExists()
        compose.onNodeWithText("7 giorni").performClick()
        compose.waitUntil(10000) { paths.any { it.contains("/analytics/summary?period=week") } }
        assertTrue(paths.any { it.contains("management/venues/12/analytics/summary?period=month") })
    }
}
