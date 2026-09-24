package it.fabiodalez.incitta.widget

import it.fabiodalez.incitta.data.Occurrence
import it.fabiodalez.incitta.data.placeName
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.serialization.Serializable

/*
 * Quel che il widget «stasera in città» sa fare senza Android intorno.
 *
 * Tutto ciò che si può decidere con dei dati e un orologio sta qui, in
 * funzioni pure: quali date mostrare, come scriverne l'ora, quando vale la
 * pena rifare il giro di rete, quali voci della copia in cache sono ormai
 * passate. `TonightWidget.kt` tiene solo il disegno e il contorno di sistema.
 *
 * Il motivo non è l'eleganza: un widget non si apre e non si ispeziona, e
 * l'unico modo di sapere che sbaglia è vederlo sbagliare sulla schermata
 * iniziale di qualcuno. Se la regola è una funzione, un test la verifica prima.
 */

/** Tre date: oltre, in due righe di widget non si leggono più. */
internal const val TONIGHT_WIDGET_LIMIT = 3

/** Due aggiornamenti al giorno. Una bacheca della sera non cambia ogni ora. */
internal const val TONIGHT_WIDGET_INTERVAL_MILLIS = 12L * 60 * 60 * 1000

/**
 * Quanto una data resta in vetrina dopo l'inizio quando il server non dice
 * dove finisce: tre ore, cioè il concerto che è appena cominciato è ancora
 * un'informazione utile, quello di ieri sera no.
 */
private const val ASSUMED_LENGTH_MILLIS = 3L * 60 * 60 * 1000

private val ROME: ZoneId = ZoneId.of("Europe/Rome")
private val CLOCK: DateTimeFormatter = DateTimeFormatter.ofPattern("HH:mm", Locale.ITALIAN)

@Serializable
internal data class TonightWidgetEntry(
    val occurrenceId: Long,
    val title: String,
    /** Già formattata per la città; vuota quando la data dura tutto il giorno. */
    val time: String,
    val place: String,
    val url: String,
    /** Istante oltre il quale la voce non è più «stasera», in millisecondi. */
    val expiresAt: Long,
)

@Serializable
internal data class TonightWidgetSnapshot(
    val entries: List<TonightWidgetEntry> = emptyList(),
    val updatedAt: Long = 0L,
    /*
     * Distingue «non ho ancora chiesto niente» da «ho chiesto e stasera non
     * c'è nulla». Senza, il widget appena posato direbbe che la città è vuota
     * mentre sta solo aspettando la prima risposta.
     */
    val loaded: Boolean = false,
    /*
     * L'ultimo tentativo, riuscito o no. Senza questo, un giro di rete fallito
     * lascia `loaded` falso e il widget scrive «cerco» per sempre: visto sulla
     * schermata iniziale, «cerco» che non finisce mai è indistinguibile da un
     * widget bloccato, e non lo si può nemmeno aprire per capire.
     */
    val lastAttemptAt: Long = 0L,
)

/** Cosa ha da dire il widget, quando non ha date da mostrare. */
internal enum class TonightWidgetState { Loading, Unreachable, Empty, Entries }

/**
 * Lo stato da disegnare.
 *
 * Le date in cache vengono prima di tutto: se ci sono e valgono ancora, un
 * aggiornamento fallito non deve cancellare quello che si sapeva ieri.
 */
internal fun tonightWidgetState(
    snapshot: TonightWidgetSnapshot,
    visible: List<TonightWidgetEntry>,
): TonightWidgetState = when {
    visible.isNotEmpty() -> TonightWidgetState.Entries
    snapshot.loaded -> TonightWidgetState.Empty
    snapshot.lastAttemptAt > 0L -> TonightWidgetState.Unreachable
    else -> TonightWidgetState.Loading
}

/** L'indirizzo che apre la singola data: lo stesso che apre un link condiviso. */
internal fun tonightWidgetUrl(occurrence: Occurrence): String =
    occurrence.dateUrl
        ?: occurrence.url
        ?: "https://eventi.fabiodalez.it/eventi/${occurrence.eventSlug}"

internal fun tonightWidgetTime(occurrence: Occurrence): String =
    if (occurrence.isAllDay) {
        ""
    } else {
        runCatching { OffsetDateTime.parse(occurrence.startsAt).atZoneSameInstant(ROME).format(CLOCK) }
            .getOrDefault("")
    }

private fun instantOrNull(value: String?): Long? =
    value?.let { runCatching { OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() }

internal fun tonightWidgetEntries(items: List<Occurrence>, limit: Int = TONIGHT_WIDGET_LIMIT): List<TonightWidgetEntry> =
    items.asSequence()
        // Mandare qualcuno a una serata annullata è peggio di un widget vuoto.
        .filter { it.status != "cancelled" }
        .distinctBy(Occurrence::occurrenceId)
        .take(limit)
        .map { occurrence ->
            val start = instantOrNull(occurrence.startsAt)
            TonightWidgetEntry(
                occurrenceId = occurrence.occurrenceId,
                title = occurrence.title,
                time = tonightWidgetTime(occurrence),
                place = occurrence.placeName().orEmpty(),
                url = tonightWidgetUrl(occurrence),
                expiresAt = instantOrNull(occurrence.effectiveEndsAt)
                    ?: instantOrNull(occurrence.endsAt)
                    ?: start?.plus(ASSUMED_LENGTH_MILLIS)
                    ?: Long.MAX_VALUE,
            )
        }
        .toList()

/**
 * Le voci ancora buone della copia in cache.
 *
 * Serve perché fra un aggiornamento e l'altro passano dodici ore: senza questo
 * filtro il widget mostrerebbe fino a mezzogiorno il programma di ieri sera,
 * con l'aria di essere aggiornato.
 */
internal fun tonightWidgetVisible(snapshot: TonightWidgetSnapshot, now: Long): List<TonightWidgetEntry> =
    snapshot.entries.filter { it.expiresAt > now }

/**
 * Se rifare il giro di rete.
 *
 * In risparmio energetico non si chiede niente: il widget continua a disegnare
 * quello che ha già, che è esattamente il compromesso che il risparmio chiede.
 * L'orologio che torna indietro (fuso cambiato a mano, ora legale) altrimenti
 * congelerebbe il widget fino a superare di nuovo `updatedAt`, e nel caso
 * peggiore per sempre: un istante futuro non arriva mai a scadenza.
 */
internal fun tonightWidgetShouldRefresh(
    snapshot: TonightWidgetSnapshot,
    now: Long,
    powerSaveMode: Boolean,
): Boolean = when {
    powerSaveMode -> false
    !snapshot.loaded -> true
    now < snapshot.updatedAt -> true
    else -> now - snapshot.updatedAt >= TONIGHT_WIDGET_INTERVAL_MILLIS
}
