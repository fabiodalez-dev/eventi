package it.fabiodalez.incitta.ui.management.registration

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ApplicationLimits
import it.fabiodalez.incitta.data.management.ApplicationOptions
import it.fabiodalez.incitta.data.management.ApplicationSent
import it.fabiodalez.incitta.data.management.CatalogueVenue
import it.fabiodalez.incitta.data.management.ClaimDraft
import it.fabiodalez.incitta.data.management.ClaimTarget
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
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.KSerializer
import kotlinx.serialization.json.Json

/*
 * P13: «Le mie richieste» (W-AP-03, W-AP-06) e i tre moduli (W-AP-01, 04,
 * 05). Le regole (campi, tetti, chi può confermare) le decide il server con
 * le stesse azioni del sito; i ViewModel portano le richieste e tengono lo
 * stato. Quello che si sta scrivendo resta nella voce di navigazione, così
 * sopravvive anche alla chiusura dell'app da parte del sistema.
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

/** Lo stato comune dei tre moduli: quello che si scrive, gli elenchi, le richieste in sospeso, l'esito. */
internal data class ApplicationFormUi<D>(
    val draft: D,
    val options: Loadable<ApplicationOptions> = Loadable.Loading,
    val awaiting: List<MyApplication> = emptyList(),
    val inReview: List<MyApplication> = emptyList(),
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    /* L'esito di «Conferma e invia» / «Elimina» su una richiesta in cima al modulo. */
    val notice: String? = null,
    val noticeError: String? = null,
    /* Dopo l'invio: cosa succede adesso, al posto del modulo. */
    val sent: ApplicationSent? = null,
)

/**
 * Quello che i tre moduli hanno in comune: gli elenchi del server, le
 * richieste dell'account in cima, la conferma o il ritiro di quelle scritte
 * da ospite, l'invio con gli errori sotto i campi.
 */
internal abstract class ApplicationFormViewModel<D : Any>(
    protected val repository: ManagementRepository,
    private val saved: SavedStateHandle,
    private val serializer: KSerializer<D>,
    initial: D,
    private val fieldKeys: List<String>,
) : ViewModel() {
    protected val api: ManagementApplicationsSource? = repository.applications
    private val state = MutableStateFlow(ApplicationFormUi(draft = restore() ?: initial))
    val uiState: StateFlow<ApplicationFormUi<D>> = state.asStateFlow()

    /* Chiamata dalle sottoclassi alla fine del loro `init`, quando i loro stati esistono già. */
    protected fun start() {
        loadOptions()
        loadMine()
    }

    fun update(change: (D) -> D) {
        val next = change(state.value.draft)
        state.update { it.copy(draft = next) }
        saved[DRAFT_KEY] = draftJson.encodeToString(serializer, next)
    }

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

    fun submit() {
        val source = api ?: return
        if (state.value.busy || !canSubmit()) return
        val draft = state.value.draft
        state.update { it.copy(busy = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val sent = send(source, draft)
                saved.remove<String>(DRAFT_KEY)
                onSent()
                state.update { it.copy(sent = sent) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, fieldKeys)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    /** «Scrivi un'altra richiesta»: il modulo da capo, con i dati del referente già scritti. */
    fun another(reset: (D) -> D) {
        val next = reset(state.value.draft)
        saved[DRAFT_KEY] = draftJson.encodeToString(serializer, next)
        state.update { it.copy(draft = next, sent = null, errors = FormErrors.None, notice = null, noticeError = null) }
        loadMine()
    }

    protected abstract suspend fun send(source: ManagementApplicationsSource, draft: D): ApplicationSent
    protected open fun canSubmit(): Boolean = true
    protected open fun onSent() {}

    private fun loadMine() {
        val source = api ?: return
        viewModelScope.launch {
            /* Senza l'elenco mancano solo gli avvisi in cima: il modulo resta usabile. */
            try { showMine(source.applications(1)) } catch (e: CancellationException) { throw e } catch (_: Exception) {}
        }
    }

    private fun act(call: suspend (ManagementApplicationsSource) -> String) {
        val source = api ?: return
        if (state.value.busy) return
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

    private fun restore(): D? = saved.get<String>(DRAFT_KEY)?.let { raw -> runCatching { draftJson.decodeFromString(serializer, raw) }.getOrNull() }

    private companion object {
        const val DRAFT_KEY = "draft"
        val draftJson = Json { ignoreUnknownKeys = true }
    }
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
    repository: ManagementRepository,
    private val saved: SavedStateHandle,
    contactName: String,
) : ApplicationFormViewModel<VenueApplicationDraft>(repository, saved, VenueApplicationDraft.serializer(), VenueApplicationDraft(contactName = contactName), VENUE_FIELDS) {
    private val catalogueState = MutableStateFlow(CatalogueUi(query = saved.get<String>(QUERY_KEY).orEmpty()))
    val catalogue: StateFlow<CatalogueUi> = catalogueState.asStateFlow()
    private val imageState = MutableStateFlow(ApplicationImageUi())
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
    }

    fun imageFailed(reason: ImageUploadException.Reason) { imageState.value = ApplicationImageUi(error = imageErrorText(reason)) }

    fun removeImage() { imageState.value = ApplicationImageUi() }

    override fun onOptions(options: ApplicationOptions) {
        /* Il comune della città, come il valore iniziale del sito; una volta sola, e mai sopra quello che si è scritto. */
        if (saved.get<Boolean>(MUNICIPALITY_KEY) == true) return
        saved[MUNICIPALITY_KEY] = true
        val municipality = options.municipality?.takeIf { it.isNotBlank() } ?: return
        if (uiState.value.draft.municipality.isBlank()) update { it.copy(municipality = municipality) }
    }

    override suspend fun send(source: ManagementApplicationsSource, draft: VenueApplicationDraft): ApplicationSent =
        source.submitVenue(draft, imageState.value.image)

    override fun onSent() { imageState.value = ApplicationImageUi() }

    private companion object {
        const val QUERY_KEY = "catalogue_query"
        const val MUNICIPALITY_KEY = "municipality_set"
        const val MIN_QUERY = 3
        const val MAX_QUERY = 80
    }
}

internal class ClaimVenueViewModel(
    repository: ManagementRepository,
    saved: SavedStateHandle,
    private val slug: String,
    contactName: String,
) : ApplicationFormViewModel<ClaimDraft>(repository, saved, ClaimDraft.serializer(), ClaimDraft(contactName = contactName), CLAIM_FIELDS) {
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

    override suspend fun send(source: ManagementApplicationsSource, draft: ClaimDraft): ApplicationSent = source.claim(slug, draft)
}

internal class RegisterOrganizerViewModel(
    repository: ManagementRepository,
    saved: SavedStateHandle,
    contactName: String,
) : ApplicationFormViewModel<OrganizerApplicationDraft>(repository, saved, OrganizerApplicationDraft.serializer(), OrganizerApplicationDraft(contactName = contactName), ORGANIZER_FIELDS) {
    init { start() }

    override suspend fun send(source: ManagementApplicationsSource, draft: OrganizerApplicationDraft): ApplicationSent = source.submitOrganizer(draft)
}

/* I campi che i moduli mostrano, con i nomi del server: un errore su uno di questi va sotto il campo. */
internal val VENUE_FIELDS = listOf(
    "venue_name", "type", "address", "postal_code", "municipality", "website", "public_email", "public_phone",
    "instagram", "facebook", "capacity", "description", "image", "message", "contact_name", "contact_role", "contact_phone",
)
internal val CLAIM_FIELDS = listOf("contact_name", "contact_role", "contact_phone", "message")
internal val ORGANIZER_FIELDS = listOf("venue_name", "website", "message", "contact_name", "contact_role", "contact_phone")
