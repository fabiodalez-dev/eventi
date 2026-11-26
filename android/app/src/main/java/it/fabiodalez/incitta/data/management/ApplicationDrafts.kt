package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.LocalStore
import java.io.File
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json

/*
 * Q2 (W-AP-02): la bozza dei tre moduli tenuta sul telefono.
 *
 * Sul sito «Ho già un account» conserva quanto scritto nella sessione del
 * browser (`StoreVenueApplicationRequest::saveDraft`, gli stessi campi di
 * `DRAFT_FIELDS`), e un ospite che crea l'account manda la richiesta al
 * server, dove aspetta la conferma dell'email. Nell'app la richiesta resta
 * qui finché non c'è la sessione: la bozza vive nelle preferenze cifrate,
 * distinta per registrazione di un locale, rivendicazione di quel locale e
 * organizzazione, e sopravvive alla chiusura dell'app. L'immagine del locale
 * sta in un file della cache.
 *
 * Mai la password: chi crea l'account la scrive e resta solo in memoria. La
 * bozza si cancella quando la richiesta parte e all'uscita dall'account; una
 * bozza dimenticata sparisce dopo trenta giorni.
 */

/** Quale bozza: una per modulo, e per la rivendicazione una per locale. */
internal object DraftScopes {
    const val VENUE = "venue"
    const val ORGANIZER = "organizer"
    /* Non è una bozza: «Le mie richieste», dove torna chi ha scelto «Accedi» da lì. */
    const val APPLICATIONS = "applications"
    private const val CLAIM = "claim:"

    fun claim(slug: String): String = CLAIM + slug

    fun of(destination: ManagementDestination.Registration): String = when (destination.form) {
        RegistrationForm.Venue -> VENUE
        RegistrationForm.Organizer -> ORGANIZER
        RegistrationForm.Applications -> APPLICATIONS
        RegistrationForm.Claim -> destination.slug?.takeIf { it.isNotBlank() }?.let(::claim) ?: VENUE
    }

    /** La schermata di una bozza; `null` per un nome che non è dei nostri. */
    fun destination(scope: String): ManagementDestination.Registration? = when {
        scope == VENUE -> ManagementDestination.Registration(RegistrationForm.Venue)
        scope == ORGANIZER -> ManagementDestination.Registration(RegistrationForm.Organizer)
        scope == APPLICATIONS -> ManagementDestination.Registration(RegistrationForm.Applications)
        scope.startsWith(CLAIM) && SLUG.matches(scope.removePrefix(CLAIM)) -> ManagementDestination.Registration(RegistrationForm.Claim, scope.removePrefix(CLAIM))
        else -> null
    }

    private val SLUG = Regex("^[a-z0-9][a-z0-9-]{0,190}$")
}

/** Una bozza: i campi del modulo (il JSON della sua classe), l'email scritta da ospite, l'immagine e se parte da sola. */
@Serializable
internal data class StoredDraft(
    val fields: String = "",
    val email: String = "",
    val image: StoredDraftImage? = null,
    /* Vero quando la sessione è arrivata dalla registrazione chiesta da questo modulo: la richiesta parte appena il modulo si apre. */
    val autoSend: Boolean = false,
    val savedAt: Long = 0,
)

@Serializable
internal data class StoredDraftImage(val file: String = "", val width: Int = 0, val height: Int = 0)

/** «Crea l'account e invia» premuto: il modulo e l'indirizzo a cui è partito il messaggio. */
@Serializable
internal data class AwaitingAccount(val scope: String = "", val email: String = "")

@Serializable
internal data class DraftBook(
    val drafts: Map<String, StoredDraft> = emptyMap(),
    val awaiting: AwaitingAccount? = null,
    /* «Ho già un account»: il modulo da riaprire dopo l'accesso. */
    val returnAfterSignIn: String? = null,
)

/** Dove sta il quaderno delle bozze: le preferenze cifrate nell'app, la memoria nei test. */
internal interface DraftVault {
    fun read(): String?
    fun write(value: String?)
}

/** I file delle immagini: la cache nell'app, la memoria nei test. */
internal interface DraftFiles {
    fun write(name: String, bytes: ByteArray)
    fun read(name: String): ByteArray?
    fun delete(name: String)
    fun clear()
}

internal class ApplicationDrafts(
    private val vault: DraftVault,
    private val files: DraftFiles,
    private val now: () -> Long = System::currentTimeMillis,
) {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }

    @Synchronized fun load(scope: String): StoredDraft? = book().drafts[scope]

    /** Salva quello che si sta scrivendo; immagine e invio automatico restano come sono. */
    @Synchronized fun save(scope: String, fields: String, email: String) {
        val book = book()
        val current = book.drafts[scope] ?: StoredDraft()
        write(book.copy(drafts = book.drafts + (scope to current.copy(fields = fields, email = email.trim(), savedAt = now()))))
    }

    /** L'immagine del locale nella cache, o via se `null`. */
    @Synchronized fun saveImage(scope: String, image: PreparedImage?) {
        val book = book()
        val current = book.drafts[scope] ?: StoredDraft()
        current.image?.let { files.delete(it.file) }
        val stored = image?.let {
            val name = fileName(scope)
            files.write(name, it.bytes)
            StoredDraftImage(name, it.width, it.height)
        }
        write(book.copy(drafts = book.drafts + (scope to current.copy(image = stored, savedAt = now()))))
    }

    @Synchronized fun image(scope: String): PreparedImage? {
        val stored = book().drafts[scope]?.image ?: return null
        val bytes = files.read(stored.file) ?: return null
        return PreparedImage(bytes, stored.width, stored.height)
    }

    /** La richiesta è partita: via la bozza e la sua immagine. */
    @Synchronized fun remove(scope: String) {
        val book = book()
        book.drafts[scope]?.image?.let { files.delete(it.file) }
        write(book.copy(drafts = book.drafts - scope, awaiting = book.awaiting?.takeIf { it.scope != scope }))
    }

    /** «Crea l'account e invia»: il messaggio è partito, la richiesta aspetta la sessione. */
    @Synchronized fun awaitAccount(scope: String, email: String) {
        val book = book()
        write(book.copy(awaiting = AwaitingAccount(scope, email.trim()), returnAfterSignIn = null))
    }

    @Synchronized fun awaiting(): AwaitingAccount? = book().awaiting

    /** «Correggi i dati»: si torna al modulo, la bozza resta. */
    @Synchronized fun cancelAwaiting() {
        val book = book()
        if (book.awaiting != null) write(book.copy(awaiting = null))
    }

    /** «Ho già un account»: dopo l'accesso si torna a questo modulo. */
    @Synchronized fun returnAfterSignIn(scope: String) {
        val book = book()
        write(book.copy(returnAfterSignIn = scope, awaiting = null))
    }

    /**
     * È arrivata una sessione: quale modulo riaprire, se ce n'è uno.
     *
     * La richiesta parte da sola solo se la sessione chiude la registrazione
     * chiesta da questo modulo (`registration`, dallo scambio del
     * collegamento) con lo stesso indirizzo e l'email confermata. Con un
     * indirizzo che aveva già un account il modulo si riapre precompilato e
     * l'invio lo fa la persona, come la conferma della richiesta sul sito.
     */
    @Synchronized fun afterSignIn(email: String, emailVerified: Boolean, registration: Boolean): String? {
        val book = book()
        val awaiting = book.awaiting
        if (awaiting != null) {
            val draft = book.drafts[awaiting.scope]
            val sends = registration && emailVerified && draft != null && awaiting.email.equals(email.trim(), ignoreCase = true)
            val drafts = if (sends) book.drafts + (awaiting.scope to draft.copy(autoSend = true)) else book.drafts
            write(book.copy(drafts = drafts, awaiting = null, returnAfterSignIn = null))
            return awaiting.scope.takeIf { draft != null }
        }
        val back = book.returnAfterSignIn ?: return null
        write(book.copy(returnAfterSignIn = null))
        return back
    }

    /** Vero una volta sola, quando il modulo deve partire da solo. */
    @Synchronized fun takeAutoSend(scope: String): Boolean {
        val book = book()
        val draft = book.drafts[scope]?.takeIf { it.autoSend } ?: return false
        write(book.copy(drafts = book.drafts + (scope to draft.copy(autoSend = false))))
        return true
    }

    /** All'uscita dall'account: niente bozze, niente immagini. */
    @Synchronized fun clear() {
        vault.write(null)
        files.clear()
    }

    private fun book(): DraftBook {
        val raw = vault.read() ?: return DraftBook()
        val book = runCatching { json.decodeFromString(DraftBook.serializer(), raw) }.getOrNull() ?: return DraftBook().also { clear() }
        val fresh = book.drafts.filterValues { now() - it.savedAt < MAX_AGE_MILLIS }
        if (fresh.size == book.drafts.size) return book
        (book.drafts - fresh.keys).values.forEach { draft -> draft.image?.let { files.delete(it.file) } }
        val cleaned = book.copy(drafts = fresh, awaiting = book.awaiting?.takeIf { it.scope in fresh })
        write(cleaned)
        return cleaned
    }

    private fun write(book: DraftBook) {
        vault.write(if (book.drafts.isEmpty() && book.awaiting == null && book.returnAfterSignIn == null) null else json.encodeToString(DraftBook.serializer(), book))
    }

    /* Il nome del file dal nome della bozza, senza caratteri che un percorso non vuole. */
    private fun fileName(scope: String): String = scope.replace(Regex("[^a-z0-9-]"), "_") + ".jpg"

    companion object {
        const val MAX_AGE_MILLIS = 30L * 24 * 60 * 60 * 1000
        private const val DIRECTORY = "application-drafts"
        @Volatile private var shared: ApplicationDrafts? = null

        /** Le bozze dell'app: una sola istanza per processo, così modulo e accesso leggono lo stesso quaderno. */
        fun of(context: android.content.Context): ApplicationDrafts = shared ?: synchronized(this) {
            shared ?: run {
                val app = context.applicationContext
                val store = LocalStore(app)
                ApplicationDrafts(
                    object : DraftVault {
                        override fun read(): String? = store.readApplicationDrafts()
                        override fun write(value: String?) = store.writeApplicationDrafts(value)
                    },
                    CacheDraftFiles(File(app.cacheDir, DIRECTORY)),
                ).also { shared = it }
            }
        }
    }
}

/* Le immagini delle bozze in una cartella sua della cache: la pulizia dei file della gestione non la tocca. */
private class CacheDraftFiles(private val directory: File) : DraftFiles {
    override fun write(name: String, bytes: ByteArray) {
        directory.mkdirs()
        File(directory, name).writeBytes(bytes)
    }
    override fun read(name: String): ByteArray? = File(directory, name).takeIf { it.isFile }?.let { runCatching { it.readBytes() }.getOrNull() }
    override fun delete(name: String) { File(directory, name).delete() }
    override fun clear() { directory.listFiles()?.forEach { it.delete() } }
}
