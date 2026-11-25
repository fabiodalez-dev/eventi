package it.fabiodalez.incitta

import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.ui.test.*
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.data.*
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.management.ManagementScreen
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Response
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test
import java.util.concurrent.CopyOnWriteArrayList

/*
 * La biglietteria di una data con un server finto. I testi si leggono dalle
 * risorse (`text(R.string…)`), mai scritti a mano: quando una stringa cambia
 * il test la segue. Il server finto non manda `features.management`, quindi
 * l'area si comporta come fino alla 1.23.0 e si atterra sull'elenco delle date.
 */
class ManagementUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()
    private val bodies = CopyOnWriteArrayList<String>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private fun show(manager: Boolean) {
        val date = """{"id":41,"title":"Concerto della sera","starts_at":"2026-09-25T20:00:00Z","can_manage":$manager,"can_check_in":true,"staff":[],"statistics":{"checked_in":3},"practical_details":{},"cost_breakdown":{}}"""
        val booking = """{"id":5,"status":"confirmed","email":"ada@example.test","tickets_count":1,"tickets":[{"id":7,"attendee_name":"Ada Rossi","status":"valid","code":"${"A".repeat(64)}"}]}"""
        val client = ApiClient("management-ui-test", OkHttpClient.Builder().addInterceptor { chain ->
            val request = chain.request()
            request.body?.let { body -> val buffer = okio.Buffer(); body.writeTo(buffer); bodies += buffer.readUtf8() }
            val data = when {
                request.url.encodedPath.endsWith("/config") -> """{"data":{"features":{}}}"""
                request.url.encodedPath.endsWith("/check-in") -> """{"data":{"id":1,"attendee_name":"Ada Rossi","status":"checked_in"}}"""
                request.url.encodedPath.endsWith("/staff") || request.url.encodedPath.endsWith("/details") -> """{"data":{"message":"Salvato"}}"""
                request.url.encodedPath.endsWith("/bookings") -> """{"data":[$booking],"meta":{"next_page":null,"counts":{"all":1,"confirmed":1,"checked_in":0,"waitlisted":0,"cancelled":0}}}"""
                request.url.encodedPath.endsWith("/cancel") -> """{"data":${booking.replace("\"confirmed\"", "\"cancelled\"")}}"""
                request.url.encodedPath.endsWith("/manifest") -> """{"data":{"generated_at":"2026-09-25T18:00:00Z","tickets":[]}}"""
                request.url.encodedPath.endsWith("/dates") -> """{"data":[$date],"meta":{"next_page":null}}"""
                else -> """{"data":$date}"""
            }
            Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(200).message("OK").body(data.toResponseBody("application/json".toMediaType())).build()
        }.build())
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { androidx.compose.material3.Surface {
            ManagementScreen(Session("test-session-$manager", User(9941, "Staff", "staff@example.test"), null), PaddingValues(), client) {}
        } } } }
        compose.waitUntil(10000) { compose.onAllNodesWithText("Concerto della sera").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Concerto della sera").performScrollTo().performClick()
        compose.waitUntil(10000) { compose.onAllNodesWithText(text(R.string.management_scan)).fetchSemanticsNodes().isNotEmpty() }
    }
    private fun screenshot(name: String) {
        val bitmap = compose.onRoot().captureToImage().asAndroidBitmap()
        val values = android.content.ContentValues().apply {
            put(android.provider.MediaStore.Images.Media.DISPLAY_NAME, "$name.png")
            put(android.provider.MediaStore.Images.Media.MIME_TYPE, "image/png")
            put(android.provider.MediaStore.Images.Media.RELATIVE_PATH, "Pictures/inCitta-test")
        }
        val resolver = compose.activity.contentResolver
        val uri = requireNotNull(resolver.insert(android.provider.MediaStore.Images.Media.EXTERNAL_CONTENT_URI, values))
        resolver.openOutputStream(uri)!!.use { bitmap.compress(android.graphics.Bitmap.CompressFormat.PNG, 100, it) }
    }
    @Test fun staffCanConfirmAReadButCannotSeeManagementControls() {
        show(false)
        compose.onNodeWithText(text(R.string.management_manual)).performScrollTo().performTextInput("A".repeat(64))
        compose.onNodeWithText(text(R.string.management_check_code)).performScrollTo().performClick()
        compose.waitUntil(10000) { compose.onAllNodesWithText(text(R.string.management_accepted, "Ada Rossi")).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.management_staff)).assertDoesNotExist()
        screenshot("management-staff")
        assertTrue(bodies.any { it.contains("request_key") && it.contains("A".repeat(64)) })
    }
    @Test fun managerCanAssignStaffAndSaveDeclaredCosts() {
        show(true)
        compose.onNodeWithText(text(R.string.management_tab_staff)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.management_email)).performScrollTo().performTextInput("porta@example.test")
        compose.onNodeWithText(text(R.string.management_assign)).performScrollTo().performClick()
        compose.waitUntil(10000) { bodies.any { it.contains("porta@example.test") } }
        compose.waitUntil(10000) { compose.onAllNodesWithText(text(R.string.management_saved)).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.management_tab_details)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.management_edit_details)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.management_admission)).performScrollTo().performTextInput("12,50")
        compose.onNodeWithText(text(R.string.management_save)).performScrollTo().performClick()
        compose.waitUntil(10000) { bodies.any { it.contains("cost_breakdown") && it.contains("12.50") } }
        screenshot("management-owner")
        assertTrue(bodies.any { it.contains("\"remove\":false") })
    }
    @Test fun managerCancelsABookingWithAReason() {
        show(true)
        compose.onNodeWithText(text(R.string.management_tab_bookings)).performScrollTo().performClick()
        compose.waitUntil(10000) { compose.onAllNodesWithText(text(R.string.management_booking_number, 5)).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.management_cancel_booking)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.management_cancel_reason)).performTextInput("Serata annullata")
        compose.onAllNodesWithText(text(R.string.management_cancel_booking)).onLast().performClick()
        compose.waitUntil(10000) { bodies.any { it.contains("Serata annullata") } }
        compose.waitUntil(10000) { compose.onAllNodesWithText(text(R.string.management_booking_cancelled)).fetchSemanticsNodes().isNotEmpty() }
    }
}
