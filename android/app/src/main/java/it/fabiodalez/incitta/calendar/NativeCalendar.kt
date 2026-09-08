package it.fabiodalez.incitta.calendar

import android.Manifest
import android.app.job.JobInfo
import android.app.job.JobScheduler
import android.content.*
import android.content.pm.PackageManager
import android.provider.CalendarContract
import androidx.core.content.ContextCompat
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.Serializable

@Serializable
data class CalendarEntry(val id: Long, val title: String, val description: String, val location: String,
    val start: Long, val end: Long, val allDay: Boolean, val timezone: String, val status: String, val url: String)
@Serializable
data class CalendarExport(val events: List<CalendarEntry>, val ics: String)

object NativeCalendar {
    private const val ACCOUNT = "it.fabiodalez.incitta.device"
    private const val JOB = 16001
    private val lock = Any()
    private fun prefs(context: Context) = context.getSharedPreferences("native_calendar", Context.MODE_PRIVATE)
    fun allowed(context: Context) = listOf(Manifest.permission.READ_CALENDAR, Manifest.permission.WRITE_CALENDAR)
        .all { ContextCompat.checkSelfPermission(context, it) == PackageManager.PERMISSION_GRANTED }
    fun enabled(context: Context) = prefs(context).getBoolean("enabled", false)
    fun selection(context: Context): String? = prefs(context).getString("query", null)
    private fun uri(base: android.net.Uri) = base.buildUpon()
        .appendQueryParameter(CalendarContract.CALLER_IS_SYNCADAPTER, "true")
        .appendQueryParameter(CalendarContract.Calendars.ACCOUNT_NAME, ACCOUNT)
        .appendQueryParameter(CalendarContract.Calendars.ACCOUNT_TYPE, CalendarContract.ACCOUNT_TYPE_LOCAL).build()

    fun schedule(context: Context) {
        if (!enabled(context)) return
        context.getSystemService(JobScheduler::class.java).schedule(JobInfo.Builder(JOB, ComponentName(context, CalendarSyncJob::class.java))
            .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY).setPeriodic(6 * 60 * 60 * 1000L).build())
    }

    suspend fun refresh(context: Context, selection: String? = null): Int = sync(context, selection) { token, query ->
        ApiClient(LocalStore(context).installationId).get<ApiEnvelope<CalendarExport>>("me/calendar/export?$query", token).data
    }

    internal suspend fun sync(context: Context, selection: String?, load: suspend (String, String) -> CalendarExport): Int = withContext(Dispatchers.IO) {
        val store = LocalStore(context)
        val session = requireNotNull(store.readSession()) { "Accedi per collegare il calendario." }
        check(allowed(context)) { "Consenti l'accesso al calendario nelle impostazioni Android." }
        val saved = prefs(context)
        if (selection == null) {
            check(enabled(context) && saved.getLong("owner", -1) == session.user.id) { "Calendario scollegato." }
        }
        val query = selection ?: saved.getString("query", "days=30")!!
        val data = try { load(session.token, query) }
        catch (e: ApiException) {
            if (e.status == 401) synchronized(lock) {
                if (store.readSession()?.token == session.token) disconnect(context)
            }
            throw e
        }
        require(data.events.all { it.end > it.start }) { "Date calendario non valide." }
        synchronized(lock) {
            check(store.readSession()?.token == session.token) { "Sessione cambiata. Accedi di nuovo." }
            if (selection == null) check(enabled(context) && saved.getLong("owner", -1) == session.user.id && saved.getString("query", "days=30") == query)
            val resolver = context.contentResolver
            var calendarId: Long? = null
            resolver.query(CalendarContract.Calendars.CONTENT_URI, arrayOf(CalendarContract.Calendars._ID),
                "account_name = ? AND account_type = ?", arrayOf(ACCOUNT, CalendarContract.ACCOUNT_TYPE_LOCAL), null)?.use {
                if (it.moveToFirst()) calendarId = it.getLong(0)
            }
            if (calendarId == null) {
                val values = ContentValues().apply {
                    put(CalendarContract.Calendars.ACCOUNT_NAME, ACCOUNT)
                    put(CalendarContract.Calendars.ACCOUNT_TYPE, CalendarContract.ACCOUNT_TYPE_LOCAL)
                    put(CalendarContract.Calendars.NAME, "incitta")
                    put(CalendarContract.Calendars.CALENDAR_DISPLAY_NAME, "inCittà · Eventi")
                    put(CalendarContract.Calendars.CALENDAR_COLOR, 0xffccff00.toInt())
                    put(CalendarContract.Calendars.CALENDAR_ACCESS_LEVEL, CalendarContract.Calendars.CAL_ACCESS_READ)
                    put(CalendarContract.Calendars.OWNER_ACCOUNT, ACCOUNT)
                    put(CalendarContract.Calendars.SYNC_EVENTS, 1)
                    put(CalendarContract.Calendars.VISIBLE, 1)
                    put(CalendarContract.Calendars.CALENDAR_TIME_ZONE, "Europe/Rome")
                }
                calendarId = ContentUris.parseId(requireNotNull(resolver.insert(uri(CalendarContract.Calendars.CONTENT_URI), values)))
            }
            val id = requireNotNull(calendarId)
            if (selection != null) resolver.update(uri(ContentUris.withAppendedId(CalendarContract.Calendars.CONTENT_URI, id)), ContentValues().apply {
                put(CalendarContract.Calendars.VISIBLE, 1)
                put(CalendarContract.Calendars.SYNC_EVENTS, 1)
            }, null, null)
            val existing = mutableMapOf<String, Long>()
            resolver.query(CalendarContract.Events.CONTENT_URI, arrayOf(CalendarContract.Events._SYNC_ID, CalendarContract.Events._ID),
                "calendar_id = ?", arrayOf(id.toString()), null)?.use { while (it.moveToNext()) existing[it.getString(0).orEmpty()] = it.getLong(1) }
            val operations = arrayListOf<ContentProviderOperation>()
            data.events.distinctBy { it.id }.forEach { event ->
                val values = ContentValues().apply {
                    put(CalendarContract.Events.CALENDAR_ID, id)
                    put(CalendarContract.Events._SYNC_ID, event.id.toString())
                    put(CalendarContract.Events.TITLE, event.title)
                    put(CalendarContract.Events.DESCRIPTION, event.description.take(1000) + "\n\n" + event.url)
                    put(CalendarContract.Events.EVENT_LOCATION, event.location)
                    put(CalendarContract.Events.DTSTART, event.start)
                    put(CalendarContract.Events.DTEND, event.end)
                    put(CalendarContract.Events.EVENT_TIMEZONE, event.timezone)
                    put(CalendarContract.Events.ALL_DAY, if (event.allDay) 1 else 0)
                    put(CalendarContract.Events.STATUS, when(event.status) { "cancelled" -> CalendarContract.Events.STATUS_CANCELED; "postponed", "moved" -> CalendarContract.Events.STATUS_TENTATIVE; else -> CalendarContract.Events.STATUS_CONFIRMED })
                    put(CalendarContract.Events.HAS_ALARM, 0)
                }
                val previous = existing.remove(event.id.toString())
                val operation = if (previous == null) ContentProviderOperation.newInsert(uri(CalendarContract.Events.CONTENT_URI))
                    else ContentProviderOperation.newUpdate(uri(ContentUris.withAppendedId(CalendarContract.Events.CONTENT_URI, previous)))
                operations += operation.withValues(values).build()
            }
            existing.values.forEach { operations += ContentProviderOperation.newDelete(uri(ContentUris.withAppendedId(CalendarContract.Events.CONTENT_URI, it))).build() }
            if (operations.isNotEmpty()) resolver.applyBatch(CalendarContract.AUTHORITY, operations)
            saved.edit().putBoolean("enabled", true).putLong("owner", session.user.id).putString("query", query).putLong("updated", System.currentTimeMillis()).apply()
        }
        schedule(context)
        data.events.size
    }

    fun disconnect(context: Context) = synchronized(lock) {
        prefs(context).edit().clear().apply()
        context.getSystemService(JobScheduler::class.java).cancel(JOB)
        if (allowed(context)) context.contentResolver.delete(uri(CalendarContract.Calendars.CONTENT_URI),
            "account_name = ? AND account_type = ?", arrayOf(ACCOUNT, CalendarContract.ACCOUNT_TYPE_LOCAL))
        Unit
    }

    fun open(context: Context) {
        // Show a day containing an imported date, rather than an empty today view.
        var time = System.currentTimeMillis()
        if (allowed(context)) context.contentResolver.query(CalendarContract.Events.CONTENT_URI,
            arrayOf(CalendarContract.Events.DTSTART), "account_name = ? AND account_type = ? AND dtend > ?",
            arrayOf(ACCOUNT, CalendarContract.ACCOUNT_TYPE_LOCAL, time.toString()), "dtstart ASC")?.use {
            if (it.moveToFirst()) time = it.getLong(0)
        }
        val calendar = CalendarContract.CONTENT_URI.buildUpon().appendPath("time").appendPath(time.toString()).build()
        context.startActivity(Intent(Intent.ACTION_VIEW, calendar).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }
}
