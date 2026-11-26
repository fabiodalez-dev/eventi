package it.fabiodalez.incitta.ui.submission

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.EventSubmissionBody
import it.fabiodalez.incitta.data.EventSubmissionSource
import it.fabiodalez.incitta.data.SUBMISSION_CITY
import it.fabiodalez.incitta.data.SubmissionCity
import it.fabiodalez.incitta.data.SubmissionReceipt
import it.fabiodalez.incitta.data.SubmissionVenue
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.cityIso
import java.time.LocalDateTime
import java.time.ZoneId
import java.util.UUID
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json

/*
 * «Proponi un evento» (Q1): gli stessi campi e le stesse regole del modulo
 * `/proponi-evento`. Le regole le decide il server (`StoreSubmissionRequest`,
 * lo stesso elenco del sito): qui si controllano solo i due campi obbligatori,
 * come fa il browser prima di inviare, perché ogni invio consuma uno dei
 * cinque tentativi l'ora del secchio `public-forms`.
 *
 * Quello che si scrive resta nello stato salvato, così sopravvive a una
 * rotazione e alla chiusura dell'app da parte del sistema.
 */

/** Quello che si sta scrivendo. `startsAt` è l'ora della città (`2026-12-05T21:00`), `rawText` il testo con i segni di `RichText`. */
@Serializable
internal data class SubmissionDraft(
    val title: String = "",
    val startsAt: String? = null,
    val venueId: Long? = null,
    val venueName: String? = null,
    val venueHint: String = "",
    val rawText: String = "",
    val contactName: String = "",
    val contactEmail: String = "",
) {
    val startsAtLocal: LocalDateTime? get() = startsAt?.let { runCatching { LocalDateTime.parse(it) }.getOrNull() }

    /** Il corpo per il server: i campi vuoti restano fuori, la data con lo scarto della città. */
    fun body(zone: ZoneId): EventSubmissionBody = EventSubmissionBody(
        title = title.trim(),
        startsAtHint = startsAtLocal?.let { cityIso(it, zone) },
        venueId = venueId,
        venueHint = venueHint.trim().ifBlank { null },
        rawText = RichText.toHtml(rawText).trim().ifBlank { null },
        contactName = contactName.trim().ifBlank { null },
        contactEmail = contactEmail.trim(),
    )
}

/** La scelta del locale: la ricerca e l'elenco dei locali della città. `results` è `null` prima di cercare. */
internal data class VenuePickerUi(
    val query: String = "",
    val results: Loadable<List<SubmissionVenue>>? = null,
)

internal data class EventSubmissionUi(
    val draft: SubmissionDraft = SubmissionDraft(),
    val city: SubmissionCity = EventSubmissionViewModel.DEFAULT_CITY,
    val picker: VenuePickerUi = VenuePickerUi(),
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    /* Gli errori dei due campi obbligatori trovati prima di inviare, come risorse. */
    val missing: Map<String, Int> = emptyMap(),
    /* Dopo l'invio: la ricevuta del server, al posto del modulo. */
    val receipt: SubmissionReceipt? = null,
) {
    val zone: ZoneId get() = runCatching { ZoneId.of(city.timezone) }.getOrDefault(ZoneId.of(EventSubmissionViewModel.DEFAULT_CITY.timezone))
}

internal class EventSubmissionViewModel(
    private val source: EventSubmissionSource,
    private val saved: SavedStateHandle,
    contactEmail: String? = null,
    contactName: String? = null,
) : ViewModel() {
    private val state = MutableStateFlow(EventSubmissionUi(draft = restore() ?: SubmissionDraft()))
    val uiState: StateFlow<EventSubmissionUi> = state.asStateFlow()
    private var searchJob: Job? = null

    init {
        prefill(contactEmail, contactName)
        loadCity()
    }

    /**
     * Da collegati email e nome dell'account, solo nei campi ancora vuoti:
     * quello che si è scritto a mano non si tocca. Si può cambiare: come sul
     * sito, conta l'indirizzo a cui la redazione deve rispondere.
     */
    fun prefill(email: String?, name: String?) {
        val draft = state.value.draft
        val next = draft.copy(
            contactEmail = draft.contactEmail.ifBlank { email?.trim().orEmpty() },
            contactName = draft.contactName.ifBlank { name?.trim().orEmpty() },
        )
        if (next != draft) update { next }
    }

    fun update(change: (SubmissionDraft) -> SubmissionDraft) {
        val next = change(state.value.draft)
        state.update { it.copy(draft = next, missing = it.missing.filterKeys { key -> stillMissing(key, next) }) }
        saved[DRAFT_KEY] = draftJson.encodeToString(SubmissionDraft.serializer(), next)
    }

    fun setStartsAt(value: LocalDateTime?) = update { it.copy(startsAt = value?.withSecond(0)?.withNano(0)?.toString()) }

    fun chooseVenue(venue: SubmissionVenue?) = update { it.copy(venueId = venue?.id, venueName = venue?.name) }

    /** Il nome e il fuso della città; se non arrivano resta Padova, che oggi è l'unica. */
    fun loadCity() {
        viewModelScope.launch {
            try {
                val city = source.city()
                if (city.timezone.isNotBlank()) state.update { it.copy(city = city.copy(name = city.name.ifBlank { DEFAULT_CITY.name })) }
            } catch (e: CancellationException) { throw e }
            catch (_: Exception) { /* La data si sceglie comunque nel fuso di Padova. */ }
        }
    }

    /** L'elenco all'apertura: i locali della città in ordine di nome, o quelli dell'ultima ricerca. */
    fun openPicker() {
        if (state.value.picker.results == null || state.value.picker.results is Loadable.Failed) searchNow(state.value.picker.query)
    }

    /** La ricerca mentre si scrive: si aspetta una pausa, e una ricerca nuova cancella quella vecchia. */
    fun setVenueQuery(query: String) {
        state.update { it.copy(picker = it.picker.copy(query = query)) }
        searchJob?.cancel()
        searchJob = viewModelScope.launch {
            delay(SEARCH_PAUSE_MS)
            runSearch(query)
        }
    }

    fun retryVenues() = searchNow(state.value.picker.query)

    private fun searchNow(query: String) {
        searchJob?.cancel()
        searchJob = viewModelScope.launch { runSearch(query) }
    }

    private suspend fun runSearch(query: String) {
        state.update { it.copy(picker = it.picker.copy(results = Loadable.Loading)) }
        try {
            val found = source.venues(query)
            state.update { it.copy(picker = it.picker.copy(results = if (found.isEmpty()) Loadable.Empty else Loadable.Ready(found))) }
        } catch (e: CancellationException) { throw e }
        catch (e: Exception) { state.update { it.copy(picker = it.picker.copy(results = Loadable.Failed(requestFailureMessage(e)))) } }
    }

    /**
     * «Invia la proposta». La chiave `Idempotency-Key` nasce al primo invio di
     * questa proposta e resta finché il testo non cambia: se la risposta si
     * perde per strada e si riprova, il server restituisce la proposta già
     * ricevuta invece di crearne una seconda. Un testo cambiato è un'altra
     * proposta, con un'altra chiave (con la stessa il server risponderebbe 409).
     */
    fun submit() {
        val current = state.value
        if (current.busy || current.receipt != null) return
        val draft = current.draft
        val missing = buildMap {
            if (draft.title.isBlank()) put(FIELD_TITLE, R.string.event_submission_title_required)
            if (draft.contactEmail.isBlank()) put(FIELD_EMAIL, R.string.event_submission_email_required)
            else if (!EMAIL.matches(draft.contactEmail.trim())) put(FIELD_EMAIL, R.string.event_submission_email_invalid)
        }
        if (missing.isNotEmpty()) {
            state.update { it.copy(missing = missing, errors = FormErrors.None) }
            return
        }
        val body = draft.body(current.zone)
        val encoded = draftJson.encodeToString(EventSubmissionBody.serializer(), body)
        val key = saved.get<String>(KEY_KEY)?.takeIf { saved.get<String>(KEY_BODY) == encoded } ?: UUID.randomUUID().toString()
        saved[KEY_KEY] = key
        saved[KEY_BODY] = encoded
        state.update { it.copy(busy = true, errors = FormErrors.None, missing = emptyMap()) }
        viewModelScope.launch {
            try {
                val receipt = source.submit(body, key)
                saved.remove<String>(DRAFT_KEY)
                saved.remove<String>(KEY_KEY)
                saved.remove<String>(KEY_BODY)
                state.update { it.copy(receipt = receipt) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, FIELDS)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    /** «Proponi un altro evento»: il modulo da capo, con nome ed email già scritti. */
    fun another() {
        val contact = state.value.draft.let { SubmissionDraft(contactName = it.contactName, contactEmail = it.contactEmail) }
        saved[DRAFT_KEY] = draftJson.encodeToString(SubmissionDraft.serializer(), contact)
        state.update { it.copy(draft = contact, receipt = null, errors = FormErrors.None, missing = emptyMap(), picker = VenuePickerUi()) }
    }

    private fun stillMissing(key: String, draft: SubmissionDraft): Boolean = when (key) {
        FIELD_TITLE -> draft.title.isBlank()
        FIELD_EMAIL -> draft.contactEmail.isBlank() || !EMAIL.matches(draft.contactEmail.trim())
        else -> true
    }

    private fun restore(): SubmissionDraft? =
        saved.get<String>(DRAFT_KEY)?.let { raw -> runCatching { draftJson.decodeFromString(SubmissionDraft.serializer(), raw) }.getOrNull() }

    internal companion object {
        const val FIELD_TITLE = "title"
        const val FIELD_EMAIL = "contact_email"
        /* I campi che il modulo mostra, con i nomi del server: un errore su uno di questi va sotto il campo. */
        val FIELDS = listOf(FIELD_TITLE, "starts_at_hint", "venue_id", "venue_hint", "raw_text", "contact_name", FIELD_EMAIL)
        val DEFAULT_CITY = SubmissionCity(slug = SUBMISSION_CITY, name = "Padova", timezone = "Europe/Rome")
        const val SEARCH_PAUSE_MS = 300L
        private const val DRAFT_KEY = "submission_draft"
        private const val KEY_KEY = "submission_idempotency_key"
        private const val KEY_BODY = "submission_idempotency_body"
        /* Lo stesso controllo leggero del browser (`type="email"`): il resto lo dice il server. */
        private val EMAIL = Regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$")
        private val draftJson = Json { ignoreUnknownKeys = true; explicitNulls = false }
    }
}
