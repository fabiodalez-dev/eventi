package it.fabiodalez.incitta.data

import java.time.Instant
import java.time.OffsetDateTime

internal enum class BookingPeriod { UPCOMING, PAST, CANCELLED }

internal fun Booking.endsAtInstant(): Instant? = runCatching { OffsetDateTime.parse(endsAt ?: startsAt).toInstant() }.getOrNull()

internal fun filterBookings(bookings: List<Booking>, period: BookingPeriod, query: String, now: Instant = Instant.now()): List<Booking> {
    val needle = query.trim()
    val selected = bookings.filter { booking ->
        val ended = booking.endsAtInstant()?.let { !it.isAfter(now) } ?: false
        val matchesPeriod = when (period) {
            BookingPeriod.UPCOMING -> booking.status != "cancelled" && !ended
            BookingPeriod.PAST -> booking.status != "cancelled" && ended
            BookingPeriod.CANCELLED -> booking.status == "cancelled"
        }
        matchesPeriod && (needle.isBlank() || (listOf(booking.id.toString(), booking.title, booking.venue.orEmpty()) + booking.tickets.map { it.attendeeName })
            .any { it.contains(needle, ignoreCase = true) })
    }
    return if (period == BookingPeriod.PAST) selected.sortedByDescending { it.startsAt }
    else selected.sortedBy { it.startsAt ?: "9999" }
}
