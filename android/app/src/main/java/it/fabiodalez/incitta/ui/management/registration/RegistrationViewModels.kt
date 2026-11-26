package it.fabiodalez.incitta.ui.management.registration

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.management.ApplicationDrafts
import it.fabiodalez.incitta.data.management.ApplicationLimits
import it.fabiodalez.incitta.data.management.ApplicationOptions
import it.fabiodalez.incitta.data.management.ApplicationSent
import it.fabiodalez.incitta.data.management.CatalogueVenue
import it.fabiodalez.incitta.data.management.ClaimDraft
import it.fabiodalez.incitta.data.management.ClaimTarget
import it.fabiodalez.incitta.data.management.DraftScopes
import it.fabiodalez.incitta.data.management.ImageUploadException
import it.fabiodalez.incitta.data.management.ManagementApplicationsSource
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.MyApplication
import it.fabiodalez.incitta.data.management.MyApplicationsPage
import it.fabiodalez.incitta.data.management.OrganizerApplicationDraft
import it.fabiodalez.incitta.data.management.PreparedImage
import it.fabiodalez.incitta.data.management.VenueApplicationDraft
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.imageErrorText
import it.fabiodalez.incitta.ui.management.common.valueOrNull
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.KSerializer
import kotlinx.serialization.json.Json

/*
 * P13: «Le mie richieste» (W-AP-03, W-AP-06) e i tre moduli (W-AP-01, 04,
 * 05). Le regole (campi, tetti, chi può confermare) le decide il server con
 * le stesse azioni del sito; i ViewModel portano le richieste e tengono lo
 * stato. Quello che si sta scrivendo resta nella voce di navigazione e, dal
 * pacchetto Q2, anche sul telefono (`ApplicationDrafts`): sopravvive alla
 * chiusura dell'app e all'accesso fatto a metà modulo.
 */

/** Le richieste dell'account, in valutazione o da confermare: in cima ai moduli, come sul sito. */
internal fun MyApplicationsPage.awaiting(): List<MyApplication> = data.filter { it.state == STATE_AWAITING }
internal fun MyApplicationsPage.inReview(): List<MyApplication> = data.filter { it.state == STATE_PENDING }

internal const val STATE_AWAITING = "awaiting"
internal const val STATE_PENDING = "pending"
internal const val STATE_APPROVED = "approved"
internal const val STATE_REJECTED = "rejected"

internal data class ApplicationsUiState(
    val items: Loadable<List<MyApplication>> = Loadable.Loading,
    val nextPage: Int? = null,
    val loadingMore: Boolean = false,
    val busy: Boolean = false,
    val message: String? = null,
    val error: String? = null,
    /* La chiave del soggetto (`venue:12`) da aprire nella gestione dopo «Apri il pannello». */
    val openSubject: String? = null,
)

internal class ApplicationsViewModel(private val repository: ManagementRepository) : ViewModel() {
    private val api: ManagementApplicationsSource? = repository.applications
    private val state = MutableStateFlow(ApplicationsUiState())
    val uiState: StateFlow<ApplicationsUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        val source = api ?: run { state.update { it.copy(items = Loadable.Empty) }; return }
        state.update { it.copy(items = Loadable.Loading, error = null) }
        viewModelScope.launch {
            try { show(source.applications(1), append = false) }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(items = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun loadMore() {
        val source = api ?: return
        val next = state.value.nextPage ?: return
        if (state.value.loadingMore) return
        state.update { it.copy(loadingMore = true, error = null) }
        viewModelScope.launch {
            try { show(source.applications(next), append = true) }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(loadingMore = false) } }
        }
    }

    /** «Conferma e invia»: la richiesta scritta da ospite con l'indirizzo dell'account entra in coda. */
    fun confirm(application: MyApplication) = act { it.confirm(application.id) }

    /** «Elimina»: la richiesta in attesa di conferma sparisce, con l'immagine. */
    fun discard(application: MyApplication) = act { it.discard(application.id) }

    /**
     * «Apri il pannello»: nell'app è la gestione nativa del locale o
     * dell'organizzazione accolta. Il repository rilegge l'elenco dei
     * soggetti, perché l'accesso può essere appena arrivato.
     */
    fun openPanel(application: MyApplication) {
        val key = application.subject?.key ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true) }
        viewModelScope.launch {
            repository.selectedSubjectKey = key
            try { repository.loadSubjects() } catch (e: CancellationException) { throw e } catch (_: Exception) { /* La pagina del soggetto riprova da sola. */ }
            state.update { it.copy(busy = false, openSubject = key) }
        }
    }

    fun subjectOpened() = state.update { it.copy(openSubject = null) }

    private fun act(call: suspend (ManagementApplicationsSource) -> String) {
        val source = api ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, message = null, error = null) }
        viewModelScope.launch {
            try {
                val message = call(source)
                state.update { it.copy(message = message.ifBlank { null }) }
                show(source.applications(1), append = false)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                state.update { it.copy(error = requestFailureMessage(e)) }
                /* Una richiesta già confermata o eliminata altrove: l'elenco si rilegge. */
                try { show(source.applications(1), append = false) } catch (inner: CancellationException) { throw inner } catch (_: Exception) {}
            } finally { state.update { it.copy(busy = false) } }
        }
    }

    private fun show(page: MyApplicationsPage, append: Boolean) {
        state.update { current ->
            val items = if (append) current.items.valueOrNull.orEmpty() + page.data else page.data
            current.copy(items = if (items.isEmpty()) Loadable.Empty else Loadable.Ready(items), nextPage = page.meta.nextPage)
        }
    }
}

/**
 * L'ospite crea l'account dal modulo (Q2): `POST /auth/register`, che
 * risponde sempre allo stesso modo (D64). Nell'app è
 * `AppRepository.register`, con il verificatore PKCE che resta nel telefono.
 */
internal fun interface GuestSignUp {
    suspend fun register(name: String, email: String, password: String)
}

/** Lo stato comune dei tre moduli: quello che si scrive, gli elenchi, le richieste in sospeso, l'esito. */
internal data class ApplicationFormUi<D>(
    val draft: D,
    val options: Loadable<ApplicationOptions> = Loadable.Loading,
    val awaiting: List<MyApplication> = emptyList(),
    val inReview: List<MyApplication> = emptyList(),
    val errors: FormErrors = FormErrors.None,
    /* Gli errori trovati qui prima di creare l'account, come testi da risolvere nella schermata. */
    val required: Map<String, Int> = emptyMap(),
    val busy: Boolean = false,
    /* L'esito di «Conferma e invia» / «Elimina» su una richiesta in cima al modulo. */
    val notice: String? = null,
    val noticeError: String? = null,
    /* Dopo l'invio: cosa succede adesso, al posto del modulo. */
    val sent: ApplicationSent? = null,
    /* Q2, da ospite: email e password dell'account da creare. La password resta solo qui, in memoria. */
    val guest: Boolean = false,
    val email: String = "",
    val password: String = "",
    val passwordConfirmation: String = "",
    /* «Controlla la posta» al posto del modulo: l'indirizzo a cui è partito il messaggio. */
    val awaitingEmail: String? = null,
    val resent: Boolean = false,
    /* La richiesta che parte da sola dopo la conferma dell'email. */
    val autoSending: Boolean = false,
    /* Eventi di una volta: andare all'accesso, atterrare su «Le mie richieste» dopo l'invio automatico. */
    val signIn: Boolean = false,
    val landOnApplications: Boolean = false,
) {
    /** Il messaggio può ripartire solo se la password è ancora in memoria: sul telefono non si salva. */
    val canResend: Boolean get() = password.isNotEmpty()
}

/**
 * Quello che i tre moduli hanno in comune: gli elenchi del server, le
 * richieste dell'account in cima, la conferma o il ritiro di quelle scritte
 * da ospite, l'invio con gli errori sotto i campi.
 *
 * Q2: con `guestSignUp` il modulo è quello dell'ospite. Al posto dell'invio
 * crea l'account e mostra «controlla la posta»; la bozza resta sul telefono
 * (`ApplicationDrafts`, mai la password) e la richiesta parte quando arriva
 * la sessione. Da collegati la bozza del telefono riempie il modulo, e se la
 * sessione è nata dalla registrazione chiesta da questo modulo
 * (`takeAutoSend`) la richiesta parte da sola appena il modulo si apre.
 */
internal abstract class ApplicationFormViewModel<D : Any>(
    protected val api: ManagementApplicationsSource?,
    private val saved: SavedStateHandle,
    private val serializer: KSerializer<D>,
    initial: D,
    private val fieldKeys: List<String>,
    private val scope: String,
    private val drafts: ApplicationDrafts?,
    private val guestSignUp: GuestSignUp?,
) : ViewModel() {
    private val guest = guestSignUp != null
    private val state = MutableStateFlow(firstState(initial))
    val uiState: StateFlow<ApplicationFormUi<D>> = state.asStateFlow()

    /* Chiamata dalle sottoclassi alla fine del loro `init`, quando i loro stati esistono già. */
    protected fun start() {
        loadOptions()
        if (guest) return
        loadMine()
        if (drafts?.takeAutoSend(scope) == true) send(auto = true)
    }

    fun update(change: (D) -> D) {
        val next = change(state.value.draft)
        state.update { it.copy(draft = next, required = it.required - changedKeys) }
        saved[DRAFT_KEY] = draftJson.encodeToString(serializer, next)
        contentChanged()
        persist(next, state.value.email)
    }

    fun setEmail(value: String) {
        state.update { it.copy(email = value.take(255), required = it.required - EMAIL_FIELD) }
        saved[EMAIL_KEY] = state.value.email
        contentChanged()
        persist(state.value.draft, state.value.email)
    }

    fun setPassword(value: String) = state.update { it.copy(password = value, required = it.required - PASSWORD_FIELD) }

    fun setPasswordConfirmation(value: String) = state.update { it.copy(passwordConfirmation = value, required = it.required - CONFIRMATION_FIELD) }

    fun loadOptions() {
        val source = api ?: run { state.update { it.copy(options = Loadable.Empty) }; return }
        state.update { it.copy(options = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val options = source.options()
                state.update { it.copy(options = Loadable.Ready(options)) }
                onOptions(options)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(options = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    /** Quando arrivano gli elenchi: il modulo del locale propone il comune della città. */
    protected open fun onOptions(options: ApplicationOptions) {}

    fun confirm(application: MyApplication) = act { it.confirm(application.id) }
    fun discard(application: MyApplication) = act { it.discard(application.id) }

    /** «Invia la richiesta» da collegati; «Crea l'account e invia» da ospite. */
    fun submit() = if (guest) createAccount() else send(auto = false)

    /** «Ho già un account»: la bozza è già sul telefono; dopo l'accesso si torna qui. */
    fun signInInstead() {
        persist(state.value.draft, state.value.email)
        drafts?.returnAfterSignIn(scope)
        state.update { it.copy(signIn = true) }
    }

    fun signInOpened() = state.update { it.copy(signIn = false) }

    fun applicationsOpened() = state.update { it.copy(landOnApplications = false) }

    /** «Mandami di nuovo il collegamento»: rifà la registrazione con gli stessi dati, come il reinvio dell'accesso. */
    fun resend() {
        val signUp = guestSignUp ?: return
        val current = state.value
        val email = current.awaitingEmail ?: return
        if (current.busy || !current.canResend) return
        state.update { it.copy(busy = true, resent = false, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                signUp.register(contactName(current.draft).trim(), email, current.password)
                state.update { it.copy(resent = true) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors(general = requestFailureMessage(e))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    /** «Correggi i dati»: di nuovo il modulo, con quello che si era scritto. Il collegamento già spedito resta valido. */
    fun editAgain() {
        drafts?.cancelAwaiting()
        state.update { it.copy(awaitingEmail = null, resent = false, errors = FormErrors.None) }
    }

    /** «Scrivi un'altra richiesta»: il modulo da capo, con i dati del referente già scritti. */
    fun another(reset: (D) -> D) {
        val next = reset(state.value.draft)
        saved[DRAFT_KEY] = draftJson.encodeToString(serializer, next)
        state.update { it.copy(draft = next, sent = null, errors = FormErrors.None, notice = null, noticeError = null) }
        loadMine()
    }

    /** L'invio, con la chiave `Idempotency-Key` di questo contenuto (`sendKey`). */
    protected abstract suspend fun send(source: ManagementApplicationsSource, draft: D, key: String): ApplicationSent
    protected open fun canSubmit(): Boolean = true
    /** Per l'invio automatico: aspetta che il modulo sia pronto (la rivendicazione, il locale). */
    protected open suspend fun awaitReady(): Boolean = true
    protected open fun onSent() {}
    /** Il nome del referente: diventa il nome dell'account creato da ospite. */
    protected abstract fun contactName(draft: D): String
    /** I campi obbligatori del modulo, controllati prima di creare l'account (il resto lo controlla il server all'invio). */
    protected abstract fun missing(draft: D): Map<String, Int>

    protected fun draftScope(): String = scope

    /* Il contenuto è cambiato: il prossimo invio è un'altra richiesta e vuole un'altra chiave. Quella della bozza la cambia `ApplicationDrafts.save`. */
    protected fun contentChanged() { saved.remove<String>(SEND_KEY) }

    /**
     * La chiave dell'invio: quella della bozza del telefono, che resta la
     * stessa finché il contenuto non cambia e sopravvive alla chiusura
     * dell'app; senza bozza, una tenuta nella voce di navigazione. Un invio
     * ripetuto (l'invio automatico la cui risposta si è persa, poi «Invia» a
     * mano) riceve dal server la risposta del primo, senza un doppione.
     */
    private fun sendKey(): String = drafts?.sendKey(scope)
        ?: saved.get<String>(SEND_KEY)
        ?: java.util.UUID.randomUUID().toString().also { saved[SEND_KEY] = it }

    private fun send(auto: Boolean) {
        val source = api ?: return
        if (state.value.busy || (!auto && !canSubmit())) return
        state.update { it.copy(busy = true, errors = FormErrors.None, autoSending = auto) }
        viewModelScope.launch {
            try {
                if (auto && !awaitReady()) return@launch
                persist(state.value.draft, state.value.email)
                val sent = send(source, state.value.draft, sendKey())
                saved.remove<String>(DRAFT_KEY)
                saved.remove<String>(SEND_KEY)
                drafts?.remove(scope)
                onSent()
                state.update { it.copy(sent = sent, landOnApplications = auto) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, fieldKeys)) } }
            finally { state.update { it.copy(busy = false, autoSending = false) } }
        }
    }

    private fun createAccount() {
        val signUp = guestSignUp ?: return
        val current = state.value
        if (current.busy) return
        val problems = missing(current.draft) + accountProblems(contactName(current.draft), current.email, current.password, current.passwordConfirmation)
        if (problems.isNotEmpty()) {
            state.update { it.copy(required = problems, errors = FormErrors.None) }
            return
        }
        val email = current.email.trim()
        state.update { it.copy(busy = true, required = emptyMap(), errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                signUp.register(contactName(current.draft).trim(), email, current.password)
                persist(current.draft, email)
                drafts?.awaitAccount(scope, email)
                state.update { it.copy(awaitingEmail = email, resent = false) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(accountFieldsOf(e), fieldKeys + ACCOUNT_FIELDS)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    private fun persist(draft: D, email: String) {
        drafts?.save(scope, draftJson.encodeToString(serializer, draft), email)
    }

    private fun loadMine() {
        val source = api ?: return
        viewModelScope.launch {
            /* Senza l'elenco mancano solo gli avvisi in cima: il modulo resta usabile. */
            try { showMine(source.applications(1)) } catch (e: CancellationException) { throw e } catch (_: Exception) {}
        }
    }

    private fun act(call: suspend (ManagementApplicationsSource) -> String) {
        val source = api ?: return
        if (state.value.busy || guest) return
        state.update { it.copy(busy = true, notice = null, noticeError = null) }
        viewModelScope.launch {
            try { state.update { it.copy(notice = call(source).ifBlank { null }) } }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(noticeError = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
            try { showMine(source.applications(1)) } catch (e: CancellationException) { throw e } catch (_: Exception) {}
        }
    }

    private fun showMine(page: MyApplicationsPage) = state.update { it.copy(awaiting = page.awaiting(), inReview = page.inReview()) }

    /* La voce di navigazione prima (una rotazione), poi la bozza del telefono (l'app chiusa, «Ho già un account»). */
    private fun firstState(initial: D): ApplicationFormUi<D> {
        val stored = drafts?.load(scope)
        val draft = restore(saved.get<String>(DRAFT_KEY)) ?: restore(stored?.fields) ?: initial
        val email = saved.get<String>(EMAIL_KEY) ?: stored?.email.orEmpty()
        val awaiting = drafts?.awaiting()?.takeIf { guest && it.scope == scope }?.email
        return ApplicationFormUi(draft = draft, guest = guest, email = email, awaitingEmail = awaiting)
    }

    private fun restore(raw: String?): D? = raw?.takeIf { it.isNotBlank() }?.let { runCatching { draftJson.decodeFromString(serializer, it) }.getOrNull() }

    private companion object {
        const val DRAFT_KEY = "draft"
        const val EMAIL_KEY = "guest_email"
        const val SEND_KEY = "send_key"
        val draftJson = Json { ignoreUnknownKeys = true }
        /* Un campo corretto perde il suo avviso: quelli del modulo si tolgono tutti a ogni modifica, li ricontrolla l'invio. */
        val changedKeys = setOf("venue_name", "address", "contact_name", "message")
    }
}

internal const val EMAIL_FIELD = "contact_email"
internal const val PASSWORD_FIELD = "password"
internal const val CONFIRMATION_FIELD = "password_confirmation"
/* I campi dell'account da ospite, con i nomi che il modulo mostra. */
internal val ACCOUNT_FIELDS = listOf(EMAIL_FIELD, PASSWORD_FIELD, CONFIRMATION_FIELD)

private val EMAIL_PATTERN = Regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$")

/** Nome, email e password dell'account da creare: gli stessi controlli del modulo del sito, prima di scrivere al server. */
internal fun accountProblems(name: String, email: String, password: String, confirmation: String): Map<String, Int> = buildMap {
    if (name.isBlank()) put("contact_name", R.string.application_required)
    when {
        email.isBlank() -> put(EMAIL_FIELD, R.string.application_required)
        !EMAIL_PATTERN.matches(email.trim()) -> put(EMAIL_FIELD, R.string.application_email_invalid)
    }
    when {
        password.isEmpty() -> put(PASSWORD_FIELD, R.string.application_required)
        password.length < MIN_PASSWORD -> put(PASSWORD_FIELD, R.string.application_password_short)
    }
    if (password.isNotEmpty() && confirmation != password) put(CONFIRMATION_FIELD, R.string.application_password_mismatch)
}

internal const val MIN_PASSWORD = 8

/**
 * Gli errori di `POST /auth/register` sotto i campi del modulo: l'email
 * dell'account è quella del referente, il nome è il suo.
 */
internal fun accountFieldsOf(error: Throwable): Throwable {
    val problem = (error as? ApiException)?.problem ?: return error
    if (error.status != 422 || problem.fields.isEmpty()) return error
    val renamed = problem.fields.mapKeys { (key, _) ->
        when (key) {
            "email" -> EMAIL_FIELD
            "name", "first_name", "last_name" -> "contact_name"
            else -> key
        }
    }
    return ApiException(error.status, problem.copy(fields = renamed))
}

/** La ricerca «Il tuo locale è già nel catalogo?»: `results` è `null` prima di cercare. */
internal data class CatalogueUi(
    val query: String = "",
    val results: Loadable<List<CatalogueVenue>>? = null,
    val tooShort: Boolean = false,
)

/** L'immagine scelta per la copertina: già ridotta, oppure il motivo per cui non va. */
internal data class ApplicationImageUi(val image: PreparedImage? = null, val error: Int? = null)

internal class RegisterVenueViewModel(
    api: ManagementApplicationsSource?,
    private val saved: SavedStateHandle,
    contactName: String,
    private val drafts: ApplicationDrafts? = null,
    guestSignUp: GuestSignUp? = null,
) : ApplicationFormViewModel<VenueApplicationDraft>(api, saved, VenueApplicationDraft.serializer(), VenueApplicationDraft(contactName = contactName), VENUE_FIELDS, DraftScopes.VENUE, drafts, guestSignUp) {
    constructor(repository: ManagementRepository, saved: SavedStateHandle, contactName: String) : this(repository.applications, saved, contactName)

    private val catalogueState = MutableStateFlow(CatalogueUi(query = saved.get<String>(QUERY_KEY).orEmpty()))
    val catalogue: StateFlow<CatalogueUi> = catalogueState.asStateFlow()
    private val imageState = MutableStateFlow(ApplicationImageUi(image = drafts?.image(DraftScopes.VENUE)))
    val image: StateFlow<ApplicationImageUi> = imageState.asStateFlow()

    init { start() }

    fun setQuery(query: String) {
        saved[QUERY_KEY] = query
        catalogueState.update { it.copy(query = query, tooShort = false) }
    }

    /** Da tre lettere in su, come il modulo del sito; al massimo sei locali. */
    fun search() {
        val source = api ?: return
        val query = catalogueState.value.query.trim()
        if (query.length < MIN_QUERY) { catalogueState.update { it.copy(tooShort = true, results = null) }; return }
        catalogueState.update { it.copy(tooShort = false, results = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val found = source.lookup(query.take(MAX_QUERY))
                catalogueState.update { it.copy(results = if (found.isEmpty()) Loadable.Empty else Loadable.Ready(found)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { catalogueState.update { it.copy(results = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    /** Le regole del server, controllate prima di partire: almeno 400×300, al massimo 5 MB. */
    fun setImage(prepared: PreparedImage) {
        val limits = uiState.value.options.valueOrNull?.limits ?: ApplicationLimits()
        val error = when {
            prepared.width < limits.imageMinWidth || prepared.height < limits.imageMinHeight -> R.string.register_image_too_small
            prepared.bytes.size > limits.imageMaxKb * 1024 -> R.string.register_image_too_heavy
            else -> null
        }
        imageState.value = if (error == null) ApplicationImageUi(prepared) else ApplicationImageUi(error = error)
        contentChanged()
        drafts?.saveImage(draftScope(), imageState.value.image)
    }

    fun imageFailed(reason: ImageUploadException.Reason) { imageState.value = ApplicationImageUi(error = imageErrorText(reason)) }

    fun removeImage() {
        imageState.value = ApplicationImageUi()
        contentChanged()
        drafts?.saveImage(draftScope(), null)
    }

    override fun onOptions(options: ApplicationOptions) {
        /* Il comune della città, come il valore iniziale del sito; una volta sola, e mai sopra quello che si è scritto. */
        if (saved.get<Boolean>(MUNICIPALITY_KEY) == true) return
        saved[MUNICIPALITY_KEY] = true
        val municipality = options.municipality?.takeIf { it.isNotBlank() } ?: return
        if (uiState.value.draft.municipality.isBlank()) update { it.copy(municipality = municipality) }
    }

    override suspend fun send(source: ManagementApplicationsSource, draft: VenueApplicationDraft, key: String): ApplicationSent =
        source.submitVenue(draft, imageState.value.image, key)

    override fun onSent() { imageState.value = ApplicationImageUi() }

    override fun contactName(draft: VenueApplicationDraft): String = draft.contactName

    override fun missing(draft: VenueApplicationDraft): Map<String, Int> = buildMap {
        if (draft.venueName.isBlank()) put("venue_name", R.string.application_required)
        if (draft.address.isBlank()) put("address", R.string.application_required)
    }

    private companion object {
        const val QUERY_KEY = "catalogue_query"
        const val MUNICIPALITY_KEY = "municipality_set"
        const val MIN_QUERY = 3
        const val MAX_QUERY = 80
    }
}

internal class ClaimVenueViewModel(
    api: ManagementApplicationsSource?,
    saved: SavedStateHandle,
    private val slug: String,
    contactName: String,
    drafts: ApplicationDrafts? = null,
    guestSignUp: GuestSignUp? = null,
) : ApplicationFormViewModel<ClaimDraft>(api, saved, ClaimDraft.serializer(), ClaimDraft(contactName = contactName), CLAIM_FIELDS, DraftScopes.claim(slug), drafts, guestSignUp) {
    constructor(repository: ManagementRepository, saved: SavedStateHandle, slug: String, contactName: String) : this(repository.applications, saved, slug, contactName)

    private val targetState = MutableStateFlow<Loadable<ClaimTarget>>(Loadable.Loading)
    /** Il locale da rivendicare; `Failed` se non si può più (ha un referente, non è pubblico). */
    val target: StateFlow<Loadable<ClaimTarget>> = targetState.asStateFlow()

    init {
        loadTarget()
        start()
    }

    fun loadTarget() {
        val source = api ?: run { targetState.value = Loadable.Empty; return }
        targetState.value = Loadable.Loading
        viewModelScope.launch {
            try { targetState.value = Loadable.Ready(source.claimTarget(slug)) }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { targetState.value = Loadable.Failed(requestFailureMessage(e)) }
        }
    }

    override fun canSubmit(): Boolean = targetState.value is Loadable.Ready

    override suspend fun awaitReady(): Boolean = targetState.first { it !is Loadable.Loading } is Loadable.Ready

    override suspend fun send(source: ManagementApplicationsSource, draft: ClaimDraft, key: String): ApplicationSent = source.claim(slug, draft, key)

    override fun contactName(draft: ClaimDraft): String = draft.contactName

    override fun missing(draft: ClaimDraft): Map<String, Int> = buildMap {
        when {
            draft.message.isBlank() -> put("message", R.string.application_required)
            draft.message.trim().length < CLAIM_MESSAGE_MIN -> put("message", R.string.claim_message_short)
        }
    }
}

internal const val CLAIM_MESSAGE_MIN = 10

internal class RegisterOrganizerViewModel(
    api: ManagementApplicationsSource?,
    saved: SavedStateHandle,
    contactName: String,
    drafts: ApplicationDrafts? = null,
    guestSignUp: GuestSignUp? = null,
) : ApplicationFormViewModel<OrganizerApplicationDraft>(api, saved, OrganizerApplicationDraft.serializer(), OrganizerApplicationDraft(contactName = contactName), ORGANIZER_FIELDS, DraftScopes.ORGANIZER, drafts, guestSignUp) {
    constructor(repository: ManagementRepository, saved: SavedStateHandle, contactName: String) : this(repository.applications, saved, contactName)

    init { start() }

    override suspend fun send(source: ManagementApplicationsSource, draft: OrganizerApplicationDraft, key: String): ApplicationSent = source.submitOrganizer(draft, key)

    override fun contactName(draft: OrganizerApplicationDraft): String = draft.contactName

    override fun missing(draft: OrganizerApplicationDraft): Map<String, Int> = buildMap {
        if (draft.name.isBlank()) put("venue_name", R.string.application_required)
    }
}

/* I campi che i moduli mostrano, con i nomi del server: un errore su uno di questi va sotto il campo. */
internal val VENUE_FIELDS = listOf(
    "venue_name", "type", "address", "postal_code", "municipality", "website", "public_email", "public_phone",
    "instagram", "facebook", "capacity", "description", "image", "message", "contact_name", "contact_role", "contact_phone",
)
internal val CLAIM_FIELDS = listOf("contact_name", "contact_role", "contact_phone", "message")
internal val ORGANIZER_FIELDS = listOf("venue_name", "website", "message", "contact_name", "contact_role", "contact_phone")
