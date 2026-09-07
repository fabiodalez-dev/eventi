package it.fabiodalez.incitta.data

import java.net.URI
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

object SavedCalendarFile {
    fun render(items: List<Occurrence>, baseUrl: String, name: String): String {
        val origin = URI(baseUrl)
        val utc = DateTimeFormatter.ofPattern("yyyyMMdd'T'HHmmss'Z'").withZone(ZoneId.of("UTC"))
        fun escape(value: String) = value.replace("\\", "\\\\").replace("\r\n", "\n").replace("\r", "\n").replace("\n", "\\n").replace(";", "\\;").replace(",", "\\,")
        val lines = mutableListOf("BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//inCitta//IT", "CALSCALE:GREGORIAN", "X-WR-CALNAME:${escape(name)}")
        items.distinctBy { it.occurrenceId }.forEach { item ->
            val start = Instant.parse(item.startsAt)
            val end = Instant.parse(requireNotNull(item.effectiveEndsAt ?: item.endsAt))
            lines += listOf("BEGIN:VEVENT", "UID:occorrenza-${item.occurrenceId}@${origin.host}", "DTSTAMP:${utc.format(Instant.now())}", "SUMMARY:${escape(item.title)}")
            if (item.isAllDay) {
                val zone = ZoneId.of("Europe/Rome")
                lines += "DTSTART;VALUE=DATE:${start.atZone(zone).toLocalDate().format(DateTimeFormatter.BASIC_ISO_DATE)}"
                lines += "DTEND;VALUE=DATE:${end.minusSeconds(1).atZone(zone).toLocalDate().plusDays(1).format(DateTimeFormatter.BASIC_ISO_DATE)}"
            } else {
                lines += "DTSTART:${utc.format(start)}"
                lines += "DTEND:${utc.format(end)}"
            }
            lines += "STATUS:${when (item.status) { "cancelled" -> "CANCELLED"; "postponed", "moved" -> "TENTATIVE"; else -> "CONFIRMED" }}"
            lines += "URL:${escape(item.url ?: "${origin.scheme}://${origin.authority}/eventi/${item.eventSlug}")}"
            item.shortDescription?.let { lines += "DESCRIPTION:${escape(it)}" }
            item.venue?.let { lines += "LOCATION:${escape(listOfNotNull(it.name, it.address, it.municipality).joinToString(", "))}" }
            lines += "END:VEVENT"
        }
        lines += "END:VCALENDAR"
        return lines.joinToString("\r\n", postfix = "\r\n") { line ->
            buildString {
                var width = 0
                line.codePoints().forEachOrdered { point ->
                    val character = String(Character.toChars(point))
                    val bytes = character.toByteArray(Charsets.UTF_8).size
                    if (width + bytes > 75) { append("\r\n "); width = 1 }
                    append(character); width += bytes
                }
            }
        }
    }
}
