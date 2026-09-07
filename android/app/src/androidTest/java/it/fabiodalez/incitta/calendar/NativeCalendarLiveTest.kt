package it.fabiodalez.incitta.calendar

import android.Manifest
import android.content.Context
import androidx.test.core.app.ApplicationProvider
import androidx.test.platform.app.InstrumentationRegistry
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.runBlocking
import org.junit.Test
import org.junit.Assume.assumeTrue
import org.junit.Assert.assertTrue

/** Opt-in release smoke test. Never checks credentials into source or prints the token. */
class NativeCalendarLiveTest {
    @Test fun authenticatedServerExportReachesNativeCalendarApp() = runBlocking {
        val args = InstrumentationRegistry.getArguments()
        val token = args.getString("calendarLiveToken")
        assumeTrue("Requires a short-lived, explicitly supplied release verification token", !token.isNullOrBlank())
        val context = ApplicationProvider.getApplicationContext<Context>()
        val store = LocalStore(context)
        val automation = InstrumentationRegistry.getInstrumentation().uiAutomation
        automation.grantRuntimePermission(context.packageName, Manifest.permission.READ_CALENDAR)
        automation.grantRuntimePermission(context.packageName, Manifest.permission.WRITE_CALENDAR)
        try {
            val user = ApiClient(store.installationId).get<ApiEnvelope<User>>("me", token).data
            store.writeSession(Session(requireNotNull(token), user, "2099-01-01T00:00:00Z"))
            assertTrue(NativeCalendar.refresh(context, "days=30") > 0)
            NativeCalendar.open(context)
            var opened = false
            repeat(30) {
                if (automation.rootInActiveWindow?.packageName?.toString() == "com.google.android.calendar") opened = true
                if (!opened) android.os.SystemClock.sleep(100)
            }
            assertTrue("The native Calendar application must open, not a browser", opened)
        } finally {
            if (args.getString("keepCalendarForVisualCheck") != "true") store.clearSession()
        }
    }
}
