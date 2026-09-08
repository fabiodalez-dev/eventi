package it.fabiodalez.incitta.ui

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.provider.CalendarContract
import android.widget.Toast
import it.fabiodalez.incitta.data.Occurrence
import java.time.OffsetDateTime
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter

private val googleDateTimeFormatter = DateTimeFormatter.ofPattern("yyyyMMdd'T'HHmmss'Z'").withZone(ZoneOffset.UTC)
private val googleAllDayFormatter = DateTimeFormatter.BASIC_ISO_DATE

internal fun googleCalendarUri(
    event: Occurrence,
    title: String = event.title,
    description: String? = event.shortDescription,
    location: String? = event.venue?.let { venue ->
        listOfNotNull(venue.name, venue.address, venue.municipality).joinToString(", ")
    },
    url: String? = event.url,
): Uri? {
    val start = runCatching { OffsetDateTime.parse(event.startsAt) }.getOrNull() ?: return null
    val parsedEnd = runCatching {
        OffsetDateTime.parse(event.endsAt ?: event.effectiveEndsAt ?: event.startsAt)
    }.getOrNull()
    val dates = if (event.isAllDay) {
        val startDate = start.toLocalDate()
        val endDate = parsedEnd?.toLocalDate()?.takeIf { it.isAfter(startDate) } ?: startDate.plusDays(1)
        "${startDate.format(googleAllDayFormatter)}/${endDate.format(googleAllDayFormatter)}"
    } else {
        val end = parsedEnd?.toInstant()?.takeIf { it.isAfter(start.toInstant()) }
            ?: start.toInstant().plusSeconds(7200)
        "${googleDateTimeFormatter.format(start.toInstant())}/${googleDateTimeFormatter.format(end)}"
    }

    return Uri.parse("https://calendar.google.com/calendar/render").buildUpon()
        .appendQueryParameter("action", "TEMPLATE")
        .appendQueryParameter("text", title)
        .appendQueryParameter("dates", dates)
        .appendQueryParameter("ctz", "Europe/Rome")
        .appendQueryParameter("details", listOfNotNull(description, url).joinToString("\n\n"))
        .appendQueryParameter("location", location)
        .build()
}

internal fun openGoogleCalendar(
    context: Context,
    event: Occurrence,
    title: String = event.title,
    description: String? = event.shortDescription,
    location: String? = event.venue?.let { venue ->
        listOfNotNull(venue.name, venue.address, venue.municipality).joinToString(", ")
    },
    url: String? = event.url,
) {
    val uri = googleCalendarUri(event, title, description, location, url) ?: return
    val insert = calendarInsertIntent(event, title, description, location, url) ?: return
    // A web TEMPLATE link can merely open the Google app's home screen.
    // ACTION_INSERT requests the actual editor, where the user confirms Save.
    val opened = runCatching { context.startActivity(Intent(insert).setPackage("com.google.android.calendar")) }.isSuccess ||
        runCatching { context.startActivity(insert) }.isSuccess ||
        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, uri)) }.isSuccess
    if (!opened) Toast.makeText(context, "Nessuna app disponibile per aggiungere l’evento al calendario.", Toast.LENGTH_LONG).show()
}

internal fun calendarInsertIntent(event: Occurrence, title: String = event.title, description: String? = event.shortDescription, location: String? = null, url: String? = event.url): Intent? {
    val start = runCatching { OffsetDateTime.parse(event.startsAt) }.getOrNull() ?: return null
    val parsedEnd = runCatching { OffsetDateTime.parse(event.endsAt ?: event.effectiveEndsAt ?: event.startsAt) }.getOrNull()
    val startInstant = if (event.isAllDay) start.toLocalDate().atStartOfDay().toInstant(ZoneOffset.UTC) else start.toInstant()
    val end = if (event.isAllDay) {
        (parsedEnd?.toLocalDate()?.takeIf { it.isAfter(start.toLocalDate()) } ?: start.toLocalDate().plusDays(1)).atStartOfDay().toInstant(ZoneOffset.UTC)
    } else parsedEnd?.toInstant()?.takeIf { it.isAfter(startInstant) } ?: startInstant.plusSeconds(7200)
    return Intent(Intent.ACTION_INSERT, CalendarContract.Events.CONTENT_URI)
        .putExtra(CalendarContract.Events.TITLE, title)
        .putExtra(CalendarContract.Events.DESCRIPTION, listOfNotNull(description, url).joinToString("\n\n"))
        .putExtra(CalendarContract.Events.EVENT_LOCATION, location)
        .putExtra(CalendarContract.EXTRA_EVENT_BEGIN_TIME, startInstant.toEpochMilli())
        .putExtra(CalendarContract.EXTRA_EVENT_END_TIME, end.toEpochMilli())
        .putExtra(CalendarContract.EXTRA_EVENT_ALL_DAY, event.isAllDay)
}
