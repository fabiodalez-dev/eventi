package it.fabiodalez.incitta.ui

import android.content.Context
import android.content.Intent
import android.net.Uri
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
    val googleCalendar = Intent(Intent.ACTION_VIEW, uri).setPackage("com.google.android.calendar")
    val intent = if (googleCalendar.resolveActivity(context.packageManager) != null) {
        googleCalendar
    } else {
        Intent(Intent.ACTION_VIEW, uri)
    }
    runCatching { context.startActivity(intent) }
}
