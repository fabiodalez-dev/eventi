package it.fabiodalez.incitta.data

import java.time.Instant
import java.time.OffsetDateTime

/**
 * La stessa regola del sito (`EventOccurrence::isAhead()`): una data si terrà
 * se non è annullata né rinviata, ed è «davanti» finché non è finita.
 *
 * Passaggi, «chi ci va», calendario e prenotazione si offrono solo su date
 * davanti: su una data annullata il sito rispondeva 404 ai passaggi e rifiutava
 * il «ci vado», e l'app mostrava gli stessi pulsanti.
 */
fun Occurrence.isCalledOff(): Boolean = status == "cancelled" || status == "postponed"

fun Occurrence.isAhead(now: Instant = Instant.now()): Boolean {
    if (isCalledOff()) return false
    val end = listOfNotNull(effectiveEndsAt, endsAt, startsAt)
        .firstNotNullOfOrNull { runCatching { OffsetDateTime.parse(it).toInstant() }.getOrNull() }
        ?: return false
    return end.isAfter(now)
}
