package it.fabiodalez.incitta.data.management

import java.net.URI

/**
 * Un indirizzo della gestione sul sito tradotto nella schermata nativa che
 * fa la stessa cosa. Arriva da un avviso o da un collegamento aperto
 * nell'app; gli App Links del manifest non includono la gestione, quindi un
 * collegamento cliccato fuori dall'app continua ad aprire il browser.
 *
 * Elenco chiuso: un indirizzo che non è qui torna `null` e si apre nel
 * browser come prima. P12 ha chiuso il giro: ogni pagina di `/gestione/…` e
 * di `/organizza/…` che ha una schermata nativa è qui (`Panel`, più
 * `Feedback` per commenti e recensioni), con lo studio social di P10 e il
 * riepilogo di P11; restano al browser solo le pagine senza schermata e i
 * file (CSV, PDF).
 * Gli avvisi a chi gestisce arrivano con un indirizzo interno
 * (`incitta://gestione/…`, vedi `ManagementNoticeTarget.uri`) che porta gli id e non
 * gli slug, così l'app non indovina il soggetto da un indirizzo pensato per
 * l'email.
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
    /**
     * P13: `/le-mie-richieste`, `/registra-il-tuo-locale`,
     * `/registra-la-tua-organizzazione` e `/locali/{locale}/rivendica`, più le
     * voci del profilo, della home e della scheda del locale. Da collegati
     * si aprono nella gestione; da ospite (Q2) i tre moduli si aprono fuori
     * dalla gestione (`GuestRegistrationScreen`) e «Le mie richieste» chiede
     * prima l'accesso.
     */
    data class Registration(val form: RegistrationForm, val slug: String? = null) : ManagementDestination
    /**
     * `/app/sponsorizzazioni/ritorno?…` (P6): Stripe riporta qui chi ha
     * pagato (o chiuso il pagamento) dall'app. È l'unico indirizzo di questo
     * elenco dichiarato App Link: si apre la pagina delle sponsorizzazioni
     * del soggetto, che controlla l'ordine con il server.
     */
    data class SponsorshipCheckout(val result: SponsorshipReturn) : ManagementDestination
    /** `/gestione` e `/organizza` (il rapporto mensile, la pagina dopo l'accesso): la gestione, dove si atterra di solito. */
    data object Start : ManagementDestination
    /**
     * Una pagina del pannello di un soggetto (`/gestione/{slug}/…`,
     * `/organizza/{slug}/…`) o un avviso a chi gestisce. `webUrl` è
     * l'indirizzo da aprire nel browser se la persona non gestisce (più)
     * quel soggetto o la gestione nell'app è spenta; gli avvisi non ce
     * l'hanno, e allora si resta nella gestione.
     */
    data class Panel(val subject: SubjectRef, val page: PanelPage, val webUrl: String? = null) : ManagementDestination

    companion object {
        fun parse(url: String, allowedOrigin: String): ManagementDestination? = runCatching {
            val uri = URI(url)
            if (uri.scheme == NOTICE_SCHEME) return parseNotice(uri)
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
            when (path) {
                "/le-mie-richieste" -> return Registration(RegistrationForm.Applications)
                "/registra-il-tuo-locale" -> return Registration(RegistrationForm.Venue)
                "/registra-la-tua-organizzazione" -> return Registration(RegistrationForm.Organizer)
            }
            Regex("^/locali/([a-z0-9][a-z0-9-]{0,190})/rivendica$").matchEntire(path)?.let { match ->
                return Registration(RegistrationForm.Claim, match.groupValues[1])
            }
            SponsorshipReturn.parse(uri)?.let { return SponsorshipCheckout(it) }
            if (Regex("^/inviti-locali/[0-9]{1,18}/[0-9]{1,18}/[0-9]{1,18}$").matches(path) || Regex("^/inviti-ingressi/[0-9]{1,18}/[0-9]{1,18}$").matches(path)) return Invitations
            if (path == "/gestione" || path == "/organizza") return Start
            parsePanel(path, uri.rawQuery, url)
        }.getOrNull()

        /* `/gestione/{slug}/…` e `/organizza/{slug}/…`: le pagine con una schermata nativa, una per una. */
        private fun parsePanel(path: String, query: String?, url: String): ManagementDestination? {
            val match = Regex("^/(gestione|organizza)/([a-z0-9][a-z0-9-]{0,190})((?:/[a-z0-9-]{1,191}){0,3})$").matchEntire(path) ?: return null
            val kind = if (match.groupValues[1] == "gestione") SubjectKind.Venue else SubjectKind.Organizer
            val slug = match.groupValues[2]
            if (slug in PANEL_RESERVED) return null
            val rest = match.groupValues[3].removePrefix("/").split('/').filter(String::isNotEmpty)
            val venue = kind == SubjectKind.Venue
            val page: PanelPage = when {
                rest.isEmpty() -> PanelPage.Home
                rest == listOf("eventi") -> PanelPage.Events
                rest == listOf("eventi", if (venue) "nuovo" else "create") -> PanelPage.NewEvent
                rest.size == 2 && rest[0] == "eventi" -> PanelPage.Event(slug = rest[1])
                rest.size == 3 && rest[0] == "eventi" && rest[2] == (if (venue) "modifica" else "edit") -> PanelPage.Event(slug = rest[1], edit = true)
                rest == listOf(if (venue) "locale" else "profile") -> PanelPage.Profile
                venue && rest == listOf("collaboratori") -> PanelPage.Collaborators
                venue && rest == listOf("il-tuo-accesso") -> PanelPage.MyAccess
                venue && rest == listOf("calendario") -> PanelPage.CalendarImport
                rest == listOf("statistiche") -> PanelPage.Statistics
                rest == listOf("statistiche-condivisioni") -> PanelPage.ShareAnalytics
                rest.size == 3 && rest[0] == "statistiche-condivisioni" && rest[1] in ANALYTICS_TYPES ->
                    PanelPage.SubjectAnalytics(rest[1], rest[2].takeIf { Regex("^[1-9][0-9]{0,17}$").matches(it) }?.toLongOrNull() ?: return null)
                rest == listOf("statistiche-sponsorizzazioni") -> PanelPage.SponsorshipAnalytics
                rest == listOf("sponsorizzazioni") -> PanelPage.Sponsorships
                /* Lo studio social c'è solo nel pannello del locale; `?event=` lo restringe a un evento (P10). */
                venue && rest == listOf("social") -> PanelPage.Social(queryLong(query, "event"))
                else -> return null
            }
            return Panel(SubjectRef(kind, slug = slug), page, webUrl = url)
        }

        /*
         * `incitta://gestione/{venue|organizer}/{id}/{area}[/{id}]` e
         * `incitta://gestione/me/{applications|invitations}`, da
         * `ManagementNoticeTarget.uri`. Le aree di Q3: `sponsorships` (la
         * pagina delle sponsorizzazioni; l'id dell'ordine non si passa alla
         * rotta, dove `order` vuol dire «ritorno da Stripe» e chiederebbe di
         * nuovo il pagamento), `sponsorship_stats` (le statistiche con la
         * campagna scelta), `statistics` (le statistiche del soggetto).
         */
        private fun parseNotice(uri: URI): ManagementDestination? {
            if (uri.host != NOTICE_HOST || uri.userInfo != null || uri.port != -1) return null
            when (uri.path.orEmpty()) {
                "/$NOTICE_ME/$NOTICE_APPLICATIONS" -> return Registration(RegistrationForm.Applications)
                "/$NOTICE_ME/$NOTICE_INVITATIONS" -> return Invitations
            }
            val match = Regex("^/(venue|organizer)/([1-9][0-9]{0,17})/(event|comments|home|sponsorships|sponsorship_stats|statistics)(?:/([1-9][0-9]{0,17}))?$").matchEntire(uri.path.orEmpty()) ?: return null
            val kind = if (match.groupValues[1] == "venue") SubjectKind.Venue else SubjectKind.Organizer
            val subject = SubjectRef(kind, id = match.groupValues[2].toLongOrNull() ?: return null)
            val id = match.groupValues[4].takeIf(String::isNotEmpty)?.toLongOrNull()
            val page = when (match.groupValues[3]) {
                NOTICE_EVENT -> PanelPage.Event(id = id ?: return null)
                NOTICE_COMMENTS -> PanelPage.Comments(id ?: return null)
                NOTICE_SPONSORSHIPS -> if (id != null) PanelPage.Sponsorships else return null
                NOTICE_SPONSORSHIP_STATS -> PanelPage.CampaignAnalytics(id ?: return null)
                NOTICE_STATISTICS -> if (id == null) PanelPage.Statistics else return null
                else -> if (id == null) PanelPage.Home else return null
            }
            return Panel(subject, page)
        }
    }
}

/* Le pagine del pannello che non sono di un soggetto. */
private val PANEL_RESERVED = setOf("login", "logout", "password-reset")
/* I soggetti della pagina di dettaglio delle statistiche (`EventAnalyticsPage::detailUrl`). */
private val ANALYTICS_TYPES = setOf("event", "venue", "organizer")

/** Il locale o l'organizzazione di un indirizzo: per slug dal sito, per id dagli avvisi. */
internal data class SubjectRef(val kind: SubjectKind, val slug: String? = null, val id: Long? = null) {
    fun matches(subject: ManagementSubject): Boolean =
        subject.kind == kind && (if (slug != null) subject.slug == slug else id != null && subject.id == id)
}

/** Le pagine del pannello che hanno una schermata nativa. */
internal sealed interface PanelPage {
    /** La pagina del soggetto: il riepilogo (P11). */
    data object Home : PanelPage
    data object Events : PanelPage
    data object NewEvent : PanelPage
    /** Un evento per id (avvisi) o per slug (pannello); `edit` apre la modifica invece della scheda. */
    data class Event(val id: Long? = null, val slug: String? = null, val edit: Boolean = false) : PanelPage
    /** I commenti di un evento da moderare (avviso di nuovo commento). */
    data class Comments(val eventId: Long) : PanelPage
    data object Profile : PanelPage
    data object Collaborators : PanelPage
    data object MyAccess : PanelPage
    data object CalendarImport : PanelPage
    data object Statistics : PanelPage
    data object ShareAnalytics : PanelPage
    data class SubjectAnalytics(val type: String, val id: Long) : PanelPage
    data object SponsorshipAnalytics : PanelPage
    /** Le statistiche delle sponsorizzazioni con una campagna già scelta (il riepilogo settimanale, Q3). */
    data class CampaignAnalytics(val campaign: Long) : PanelPage
    data object Sponsorships : PanelPage
    /** Lo studio social del locale (P10), ristretto a un evento con `?event=`. */
    data class Social(val eventId: Long? = null) : PanelPage
}

/** Quale schermata di P13: l'elenco delle richieste o uno dei tre moduli. */
internal enum class RegistrationForm { Applications, Venue, Claim, Organizer }

/* Un numero positivo da un parametro dell'indirizzo; `null` se manca o non è un id. */
private fun queryLong(query: String?, name: String): Long? = query.orEmpty().split('&')
    .firstOrNull { it.substringBefore('=') == name }?.substringAfter('=', "")
    ?.takeIf { Regex("^[1-9][0-9]{0,17}$").matches(it) }?.toLongOrNull()
