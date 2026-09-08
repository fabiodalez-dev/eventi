package it.fabiodalez.incitta.data

import java.time.Instant
import java.time.OffsetDateTime

/** End dates are calculated by Laravel, not guessed from the start date. */
internal fun Occurrence.hasEnded(now: Instant = Instant.now()): Boolean =
    (effectiveEndsAt ?: endsAt)?.let { runCatching { OffsetDateTime.parse(it).toInstant() }.getOrNull() }
        ?.let { !it.isAfter(now) } ?: false
