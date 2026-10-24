package it.fabiodalez.incitta.data

import java.time.Duration
import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/*
 * Le dieci funzioni per chi usa inCittà (contratto docs/PIANO-UTENTE-2026-10-23.md).
 * Qui stanno le forme JSON e le regole che non dipendono da Compose, così che
 * i test le provino senza dispositivo.
 */

/** 3. Una data salvata che si sovrappone a quella appena salvata. */
@Serializable
data class SavedConflict(
    @SerialName("occurrence_id") val occurrenceId: Long,
    val title: String,
    @SerialName("starts_at") val startsAt: String,
    @SerialName("ends_at") val endsAt: String? = null,
    val kind: String = "saved",
)

/** 3. La risposta di `POST /v1/me/saved`: il messaggio resta, `conflicts` si aggiunge. */
@Serializable
data class SaveResult(
    val message: String? = null,
    val conflicts: List<SavedConflict> = emptyList(),
)

/** 3. Titolo e ora («21:00») della prima sovrapposizione, o null se non ce ne sono. */
internal fun firstConflict(conflicts: List<SavedConflict>, zone: ZoneId = ZoneId.of("Europe/Rome")): Pair<String, String>? {
    val first = conflicts.firstOrNull() ?: return null
    val clock = runCatching {
        OffsetDateTime.parse(first.startsAt).atZoneSameInstant(zone).format(DateTimeFormatter.ofPattern("HH:mm", Locale.ITALIAN))
    }.getOrNull() ?: first.startsAt
    return first.title to clock
}

/** 4. Il riepilogo del lunedì porta a `/i-miei-salvataggi`; le mie serate a `/le-mie-serate`. */
internal enum class PersonalDestination { SAVED, ATTENDED }

internal fun personalDestination(pathSegments: List<String>): PersonalDestination? = when (pathSegments.firstOrNull()) {
    "i-miei-salvataggi" -> PersonalDestination.SAVED
    "le-mie-serate" -> PersonalDestination.ATTENDED
    else -> null
}

/** 5. «Aperto adesso»: tre stati, perché un locale senza orari non è «chiuso». */
internal enum class OpenState { OPEN, OPEN_UNTIL, CLOSED, CLOSURE, UNKNOWN }

internal fun Venue.openState(): OpenState = when {
    closureNow != null -> OpenState.CLOSURE
    else -> when (openNow) {
        null -> OpenState.UNKNOWN
        false -> OpenState.CLOSED
        true -> if (openUntil.isNullOrBlank()) OpenState.OPEN else OpenState.OPEN_UNTIL
    }
}

/** Un giorno di chiusura come lo legge una persona: «3 novembre». */
internal fun closureDay(day: String, withYear: Boolean = false): String = runCatching {
    java.time.LocalDate.parse(day).format(java.time.format.DateTimeFormatter.ofPattern(if (withYear) "d MMMM yyyy" else "d MMMM", java.util.Locale.ITALIAN))
}.getOrDefault(day)

/** 9. I valori di `AccessibilityFeature`, nell'ordine in cui il sito li mostra. */
internal val ACCESS_NEEDS = listOf(
    "step_free_entrance",
    "accessible_toilets",
    "reserved_seating",
    "tactile_path",
    "assistance_on_request",
    "guide_dog_allowed",
)

/**
 * 8 e 9. La parte delle preferenze di contenuto che serve fuori dal pannello:
 * i locali nascosti (per l'azione nella scheda) e le esigenze di accesso (per
 * precompilare i filtri). I campi mancano su un server vecchio: liste vuote.
 */
@Serializable
data class ContentProfileSelection(
    @SerialName("hidden_venues") val hiddenVenues: List<Long> = emptyList(),
    @SerialName("access_needs") val accessNeeds: List<String> = emptyList(),
)

@Serializable
data class ContentProfile(val selection: ContentProfileSelection = ContentProfileSelection())

/** 8. Un locale nascosto, con il nome per poterlo mostrare nell'elenco. */
@Serializable
data class HiddenVenueOption(val id: Long, val name: String, val slug: String? = null)

/** 9. Il filtro `access` precompilato dal profilo, se l'indirizzo non ne porta già uno. */
internal fun profileAccessFilters(filters: Map<String, String>, needs: List<String>, dismissed: Boolean): Map<String, String>? {
    if (dismissed || filters.containsKey("access")) return null
    val valid = needs.filter { it in ACCESS_NEEDS }
    if (valid.isEmpty()) return null
    return filters + ("access" to valid.joinToString(","))
}

/** 9. La striscia «Filtri del tuo profilo» si vede finché il filtro è quello del profilo. */
internal fun showsProfileAccess(filters: Map<String, String>, needs: List<String>): Boolean {
    val current = filters["access"]?.split(',')?.filter(String::isNotBlank)?.toSet() ?: return false
    return current.isNotEmpty() && current == needs.filter { it in ACCESS_NEEDS }.toSet()
}

/** 11. La copia dei biglietti per quando manca la rete, con l'ora dell'ultima lettura riuscita. */
@Serializable
data class StoredBookings(
    @SerialName("user_id") val userId: Long,
    @SerialName("saved_at") val savedAt: String,
    val bookings: List<Booking>,
)

/** 12. L'invio per email: il consenso è dichiarato da chi prenota, l'indirizzo non si conserva. */
@Serializable
data class TicketSendBody(val email: String, val consent: Boolean)

internal fun plausibleEmail(value: String): Boolean {
    val trimmed = value.trim()
    val at = trimmed.indexOf('@')
    return at > 0 && at == trimmed.lastIndexOf('@') && trimmed.indexOf('.', at) > at + 1 && !trimmed.endsWith('.') && trimmed.none(Char::isWhitespace)
}

/** 12. Il link personale esiste solo per un biglietto valido. */
internal fun AdmissionTicket.shareable(): Boolean = status == "valid" && !shareUrl.isNullOrBlank()

/** 13. Una data è esaurita se lo dice il suo stato o se tutte le fasce di prezzo lo sono. */
internal fun Occurrence.isSoldOut(eventTiers: List<TicketTier> = emptyList()): Boolean {
    if (status == "sold_out") return true
    val tiers = this.tiers.ifEmpty { eventTiers }
    return tiers.isNotEmpty() && tiers.all { it.status == "sold_out" }
}

/** 13. L'avviso ha senso solo su una data esaurita e non ancora passata. */
internal fun Occurrence.offersAvailabilityAlert(eventTiers: List<TicketTier>, now: Instant = Instant.now()): Boolean {
    if (status == "cancelled" || !isSoldOut(eventTiers)) return false
    val start = runCatching { OffsetDateTime.parse(startsAt).toInstant() }.getOrNull() ?: return false
    return start.isAfter(now)
}

/** L'avviso di apertura ha senso solo se le prenotazioni aprono più avanti e la data non è passata né annullata. */
internal fun Occurrence.offersOpeningAlert(now: Instant = Instant.now()): Boolean {
    if (status == "cancelled" || !bookingEnabled) return false
    val opens = bookingOpensAt?.let { runCatching { OffsetDateTime.parse(it).toInstant() }.getOrNull() } ?: return false
    val start = runCatching { OffsetDateTime.parse(startsAt).toInstant() }.getOrNull() ?: return false
    return opens.isAfter(now) && start.isAfter(now)
}

/** 14. Una serata a cui si è entrati davvero. */
@Serializable
data class NamedLink(val name: String, val slug: String? = null)

@Serializable
data class AttendedEvening(
    @SerialName("occurrence_id") val occurrenceId: Long,
    val title: String,
    @SerialName("event_slug") val eventSlug: String? = null,
    @SerialName("starts_at") val startsAt: String,
    val url: String? = null,
    val venue: NamedLink? = null,
    val organizer: NamedLink? = null,
    @SerialName("checked_in") val checkedIn: Int = 0,
)

/** 15. «Sono qui». */
@Serializable
data class PresenceBody(
    @SerialName("occurrence_id") val occurrenceId: Long,
    @SerialName("display_name") val displayName: String? = null,
    val note: String? = null,
)

@Serializable
data class PresenceLink(
    val token: String,
    val url: String,
    @SerialName("expires_at") val expiresAt: String? = null,
)

internal fun PresenceLink.activeAt(now: Instant = Instant.now()): Boolean {
    val expiry = expiresAt?.let { runCatching { OffsetDateTime.parse(it).toInstant() }.getOrNull() } ?: return true
    return expiry.isAfter(now)
}

/**
 * 15. Il server accetta il link solo per una data che comincia entro dodici
 * ore o che è in corso. Senza una fine dichiarata si considera in corso per
 * sei ore: è una stima prudente, il server resta l'arbitro e un 422 si legge.
 */
internal fun Occurrence.offersPresence(now: Instant = Instant.now()): Boolean {
    if (status == "cancelled" || status == "postponed") return false
    val start = runCatching { OffsetDateTime.parse(startsAt).toInstant() }.getOrNull() ?: return false
    val end = (effectiveEndsAt ?: endsAt)?.let { runCatching { OffsetDateTime.parse(it).toInstant() }.getOrNull() }
        ?: start.plus(Duration.ofHours(6))
    return !start.isAfter(now.plus(Duration.ofHours(12))) && end.isAfter(now)
}
