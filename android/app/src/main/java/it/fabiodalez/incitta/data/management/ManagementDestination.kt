package it.fabiodalez.incitta.data.management

import java.net.URI

/**
 * Un indirizzo della gestione sul sito tradotto nella schermata nativa che
 * fa la stessa cosa. Arriva da un avviso o da un collegamento aperto
 * nell'app; gli App Links del manifest non includono la gestione, quindi un
 * collegamento cliccato fuori dall'app continua ad aprire il browser.
 *
 * Elenco chiuso: un indirizzo che non è qui torna `null` e si apre nel
 * browser come prima. Le voci si aggiungono con i pacchetti (eventi, date,
 * profilo, …) quando la schermata nativa esiste; per ora c'è la biglietteria.
 * Gli id servono solo a scegliere la schermata: il permesso lo decide sempre
 * il server quando la schermata carica i dati.
 */
internal sealed interface ManagementDestination {
    /** `/gestione-biglietti`: l'elenco delle date gestite o da controllare. */
    data object TicketingDates : ManagementDestination
    /** `/gestione-biglietti/{data}` e `/gestione-biglietti/{data}/scanner`: la scheda della data. */
    data class TicketingDate(val occurrenceId: Long) : ManagementDestination

    companion object {
        fun parse(url: String, allowedOrigin: String): ManagementDestination? = runCatching {
            val uri = URI(url)
            val allowed = URI(allowedOrigin)
            if (uri.scheme != allowed.scheme || uri.host != allowed.host || uri.port != allowed.port || uri.userInfo != null) return null
            val path = uri.path.orEmpty().trimEnd('/')
            if (path == "/gestione-biglietti") return TicketingDates
            Regex("^/gestione-biglietti/([1-9][0-9]{0,17})(/scanner)?$").matchEntire(path)?.let { match ->
                return match.groupValues[1].toLongOrNull()?.let(::TicketingDate)
            }
            null
        }.getOrNull()
    }
}
