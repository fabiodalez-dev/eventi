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
 * profilo, …) quando la schermata nativa esiste: la biglietteria, i commenti
 * e le recensioni.
 * Gli id servono solo a scegliere la schermata: il permesso lo decide sempre
 * il server quando la schermata carica i dati.
 */
internal sealed interface ManagementDestination {
    /** `/gestione-biglietti`: l'elenco delle date gestite o da controllare. */
    data object TicketingDates : ManagementDestination
    /** `/gestione-biglietti/{data}` e `/gestione-biglietti/{data}/scanner`: la scheda della data. */
    data class TicketingDate(val occurrenceId: Long) : ManagementDestination
    /**
     * `/gestione/{locale}/event-comments` e `/organizza/{organizzazione}/event-comments`
     * (con `?event=` per i soli commenti di un evento), `…/recensioni`: le
     * pagine «Commenti agli eventi» e «Recensioni ricevute» (P7).
     */
    data class Feedback(val kind: SubjectKind, val slug: String, val reviews: Boolean, val eventId: Long? = null) : ManagementDestination
    /**
     * `/inviti-locali/…` e `/inviti-ingressi/…` (P8), più la voce «I tuoi
     * inviti» del profilo: l'elenco degli inviti ricevuti. Il collegamento
     * firmato non si usa (l'app risponde con l'account confermato): serve
     * solo a sapere che la persona sta cercando i suoi inviti.
     */
    data object Invitations : ManagementDestination

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
            Regex("^/(gestione|organizza)/([a-z0-9][a-z0-9-]{0,190})/(event-comments|recensioni)$").matchEntire(path)?.let { match ->
                val kind = if (match.groupValues[1] == "gestione") SubjectKind.Venue else SubjectKind.Organizer
                val reviews = match.groupValues[3] == "recensioni"
                val event = if (reviews) null else queryLong(uri.rawQuery, "event")
                return Feedback(kind, match.groupValues[2], reviews, event)
            }
            if (Regex("^/inviti-locali/[0-9]{1,18}/[0-9]{1,18}/[0-9]{1,18}$").matches(path) || Regex("^/inviti-ingressi/[0-9]{1,18}/[0-9]{1,18}$").matches(path)) return Invitations
            null
        }.getOrNull()
    }
}

/* Un numero positivo da un parametro dell'indirizzo; `null` se manca o non è un id. */
private fun queryLong(query: String?, name: String): Long? = query.orEmpty().split('&')
    .firstOrNull { it.substringBefore('=') == name }?.substringAfter('=', "")
    ?.takeIf { Regex("^[1-9][0-9]{0,17}$").matches(it) }?.toLongOrNull()
