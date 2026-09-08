package it.fabiodalez.incitta.calendar

import android.Manifest
import android.content.*
import android.provider.CalendarContract
import androidx.test.core.app.ApplicationProvider
import androidx.test.platform.app.InstrumentationRegistry
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.runBlocking
import org.junit.*
import org.junit.Assert.*

class NativeCalendarTest {
    private val context = ApplicationProvider.getApplicationContext<Context>()
    private val store = LocalStore(context)
    private val session = Session("calendar-test-token", User(160, "Test", "calendar@example.test"), "2099-12-01T00:00:00Z")
    private fun entry(title: String = "Concerto") = CalendarEntry(7, title, "Descrizione", "Teatro", 1800000000000, 1800003600000, false, "Europe/Rome", "scheduled", "https://example.test/eventi/concerto")
    private fun eventRows(): List<Pair<Long, String>> {
        val rows = mutableListOf<Pair<Long,String>>()
        context.contentResolver.query(CalendarContract.Events.CONTENT_URI, arrayOf("_id", "title"), "_sync_id = ?", arrayOf("7"), null)?.use {
            while (it.moveToNext()) rows += it.getLong(0) to it.getString(1)
        }
        return rows
    }
    @Before fun before() {
        val automation = InstrumentationRegistry.getInstrumentation().uiAutomation
        automation.grantRuntimePermission(context.packageName, Manifest.permission.READ_CALENDAR)
        automation.grantRuntimePermission(context.packageName, Manifest.permission.WRITE_CALENDAR)
        NativeCalendar.disconnect(context); store.writeSession(session)
    }
    @After fun after() { store.clearSession() }

    @Test fun updatesWithoutDuplicatesAndRemovesWithdrawnDates() = runBlocking {
        NativeCalendar.sync(context, "days=30") { token, _ -> assertEquals(session.token, token); CalendarExport(listOf(entry()), "") }
        val first = eventRows().single()
        NativeCalendar.sync(context, null) { _, _ -> CalendarExport(listOf(entry("Titolo aggiornato")), "") }
        assertEquals(listOf(first.first to "Titolo aggiornato"), eventRows())
        NativeCalendar.sync(context, null) { _, _ -> CalendarExport(emptyList(), "") }
        assertTrue(eventRows().isEmpty())
    }

    @Test fun openingCalendarTargetsADayWithImportedEvents() = runBlocking {
        NativeCalendar.sync(context, "days=30") { _, _ -> CalendarExport(listOf(entry()), "") }
        var opened: Intent? = null
        val capture = object : ContextWrapper(context) {
            override fun startActivity(intent: Intent) { opened = intent }
        }
        NativeCalendar.open(capture)
        assertEquals(Intent.ACTION_VIEW, opened?.action)
        assertEquals(entry().start.toString(), opened?.data?.lastPathSegment)
    }

    @Test fun logoutAndAccountSwitchRemoveOwnedCalendar() = runBlocking {
        NativeCalendar.sync(context, "days=7") { _, _ -> CalendarExport(listOf(entry()), "") }
        store.writeSession(session.copy(user = User(161, "Altro", "altro@example.test")))
        assertFalse(NativeCalendar.enabled(context))
        assertTrue(eventRows().isEmpty())
        store.writeSession(session)
        NativeCalendar.sync(context, "days=7") { _, _ -> CalendarExport(listOf(entry()), "") }
        store.clearSession()
        assertFalse(NativeCalendar.enabled(context))
        assertTrue(eventRows().isEmpty())
    }

    @Test fun guestAndChangedSessionCannotImportEvenAfterAnInFlightRequest() = runBlocking {
        store.clearSession()
        var requested = false
        assertTrue(runCatching { NativeCalendar.sync(context, "days=30") { _, _ -> requested = true; CalendarExport(listOf(entry()), "") } }.isFailure)
        assertFalse(requested)
        store.writeSession(session)
        assertTrue(runCatching { NativeCalendar.sync(context, "days=30") { _, _ -> store.clearSession(); CalendarExport(listOf(entry()), "") } }.isFailure)
        assertTrue(eventRows().isEmpty())
    }

    @Test fun disconnectNeverDeletesOtherCalendars() = runBlocking {
        val foreign = CalendarContract.Calendars.CONTENT_URI.buildUpon()
            .appendQueryParameter(CalendarContract.CALLER_IS_SYNCADAPTER, "true")
            .appendQueryParameter("account_name", "calendar-isolation-test")
            .appendQueryParameter("account_type", CalendarContract.ACCOUNT_TYPE_LOCAL).build()
        val created = requireNotNull(context.contentResolver.insert(foreign, ContentValues().apply {
            put("account_name", "calendar-isolation-test"); put("account_type", CalendarContract.ACCOUNT_TYPE_LOCAL)
            put("name", "Foreign test"); put("calendar_displayName", "Foreign test")
            put("ownerAccount", "calendar-isolation-test"); put("calendar_access_level", 700)
        }))
        try {
            NativeCalendar.sync(context, "days=30") { _, _ -> CalendarExport(listOf(entry()), "") }
            NativeCalendar.disconnect(context)
            context.contentResolver.query(created, arrayOf("_id"), null, null, null)!!.use { assertTrue(it.moveToFirst()) }
        } finally {
            context.contentResolver.delete(foreign, "_id = ?", arrayOf(ContentUris.parseId(created).toString()))
        }
    }
}
