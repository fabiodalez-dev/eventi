package it.fabiodalez.incitta.data

import java.security.MessageDigest
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** Risposta di `GET /v1/ticketing/{occurrence}/manifest`: impronte SHA-256 dei codici e nomi, mai i codici. */
@Serializable
internal data class ManifestPayload(
    @SerialName("generated_at") val generatedAt: String,
    val window: ManifestWindow? = null,
    val tickets: List<ManifestTicket> = emptyList(),
)

@Serializable internal data class ManifestWindow(val from: String? = null, val to: String? = null)
@Serializable internal data class ManifestTicket(val h: String, val name: String, val status: String)

@Serializable
internal data class ManifestEntry(val name: String, val status: String)

/** Che cosa fa la porta con una lettura. */
internal enum class ScanDecision { Send, SendWarnUsed, SendWarnUnknown, RejectUsed, PendingUnknown, Pending }

/**
 * L'elenco aiuta senza rete, non è mai l'ultima parola: con la rete ogni
 * codice va al server, l'unico che conosce le prenotazioni arrivate dopo il
 * download. Senza rete un codice già usato si respinge, uno sconosciuto resta
 * in coda con l'avviso di non far entrare finché il server non conferma.
 */
internal fun scanDecision(online: Boolean, verdict: ManifestVerdict?): ScanDecision = when {
    /* Con la rete decide il server, ma l'avviso locale resta visibile: se il
       server poi non risponde, chi è alla porta sa già che quel codice risultava
       usato o assente dall'elenco. */
    online && verdict is ManifestVerdict.AlreadyUsed -> ScanDecision.SendWarnUsed
    online && verdict == ManifestVerdict.Unknown -> ScanDecision.SendWarnUnknown
    online -> ScanDecision.Send
    verdict is ManifestVerdict.AlreadyUsed -> ScanDecision.RejectUsed
    verdict == ManifestVerdict.Unknown -> ScanDecision.PendingUnknown
    else -> ScanDecision.Pending
}

/** Cosa dice l'elenco scaricato di un codice appena letto. */
internal sealed interface ManifestVerdict {
    /** Non c'è nell'elenco: forse una prenotazione recente; decide il server. */
    data object Unknown : ManifestVerdict
    /** C'è, ma risulta già entrato (dal server o da questo telefono). */
    data class AlreadyUsed(val name: String) : ManifestVerdict
    /** C'è ed è valido: si accoda come sempre, e conferma solo il server. */
    data class Known(val name: String) : ManifestVerdict
}

/**
 * L'elenco per il controllo ingressi senza rete.
 *
 * Riconosce un QR confrontando l'impronta del codice con quelle scaricate; non
 * autorizza niente da solo: un codice riconosciuto va comunque in coda e
 * l'ingresso è registrato solo quando il server risponde. Serve a respingere
 * subito i QR estranei e i doppi ingressi mentre manca la rete.
 *
 * Immutabile e serializzabile: `markUsed` restituisce una copia, che la
 * schermata salva cifrata con `LocalStore.writeManifest`.
 */
@Serializable
internal data class CheckinManifest(
    val occurrenceId: Long,
    val generatedAt: String,
    val entries: Map<String, ManifestEntry>,
    val usedHashes: Set<String> = emptySet(),
    val savedAtMillis: Long = 0,
) {
    val size: Int get() = entries.size

    fun lookup(code: String): ManifestEntry? = entries[hash(code)]

    fun markUsed(code: String): CheckinManifest = hash(code).let { if (it in usedHashes) this else copy(usedHashes = usedHashes + it) }

    fun verdict(code: String): ManifestVerdict {
        val digest = hash(code)
        val entry = entries[digest] ?: return ManifestVerdict.Unknown
        return if (entry.status == "checked_in" || digest in usedHashes) ManifestVerdict.AlreadyUsed(entry.name) else ManifestVerdict.Known(entry.name)
    }

    fun expired(nowMillis: Long): Boolean = nowMillis - savedAtMillis > MAX_AGE_MILLIS

    companion object {
        /** Oltre le 48 ore un elenco non serve più e contiene nomi: si cancella. */
        const val MAX_AGE_MILLIS = 48L * 60 * 60 * 1000

        fun hash(code: String): String = MessageDigest.getInstance("SHA-256")
            .digest(code.toByteArray(Charsets.UTF_8))
            .joinToString("") { "%02x".format(it) }

        fun from(occurrenceId: Long, payload: ManifestPayload, nowMillis: Long): CheckinManifest = CheckinManifest(
            occurrenceId = occurrenceId,
            generatedAt = payload.generatedAt,
            entries = payload.tickets.associate { it.h.lowercase() to ManifestEntry(it.name, it.status) },
            savedAtMillis = nowMillis,
        )
    }
}

/**
 * Un elenco riscaricato sostituisce il vecchio ma conserva gli ingressi
 * segnati su questo telefono e non ancora confermati: altrimenti un secondo
 * passaggio dello stesso QR, senza rete, sembrerebbe di nuovo valido.
 */
internal fun CheckinManifest.refreshedWith(previous: CheckinManifest?): CheckinManifest =
    if (previous == null || previous.occurrenceId != occurrenceId) this
    else copy(usedHashes = previous.usedHashes.filter { entries[it]?.status != "checked_in" && it in entries }.toSet())
