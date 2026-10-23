package it.fabiodalez.incitta.data

import java.net.URLEncoder
import java.time.LocalDate
import kotlinx.serialization.Serializable

/**
 * L'avviso in vista sulla scheda del locale (D60): pausa, chiusura,
 * riapertura o comunicazione. `until` è una data `YYYY-MM-DD` oppure null.
 */
@Serializable
data class VenueNotice(val kind: String, val body: String, val until: String? = null) {
    fun untilDate(): LocalDate? = until?.let { runCatching { LocalDate.parse(it) }.getOrNull() }
}

/** L'indirizzo pubblico del sito, ricavato da quello dell'API (`…/api/v1/`). */
internal fun publicSiteBase(apiBaseUrl: String): String = apiBaseUrl.substringBefore("/api/").trimEnd('/')

/** Il calendario pubblico di un locale, senza account: `/locali/{slug}/calendario.ics`. */
internal fun venueCalendarUrl(apiBaseUrl: String, slug: String): String =
    "${publicSiteBase(apiBaseUrl)}/locali/${URLEncoder.encode(slug, "UTF-8")}/calendario.ics"
