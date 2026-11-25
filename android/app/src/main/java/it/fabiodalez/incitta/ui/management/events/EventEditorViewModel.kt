package it.fabiodalez.incitta.ui.management.events

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.management.EventDateChecksResult
import it.fabiodalez.incitta.data.management.EventFormOptions
import it.fabiodalez.incitta.data.management.EventSaveResponse
import it.fabiodalez.incitta.data.management.FacebookImportResult
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.data.management.PreparedImage
import it.fabiodalez.incitta.data.management.VenueLookup
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.cityIso
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/** Le chiavi del server dei campi che il modulo mostra: gli errori vanno sotto di loro. */
internal val EVENT_FORM_FIELDS = listOf(
    "title", "description", "category_id", "price_type", "price_min", "price_max", "tags", "ticket_url", "booking_url",
    "external_links", "custom_location", "starts_at", "ends_at", "repeat", "city_id", "venue_id", "content_details",
    "ticket_tiers", "facts", "organizer_name", "organizer_url", "seo", "url",
)

internal data class EventEditorUiState(
    val subject: ManagementSubject? = null,
    val mode: EventFormMode = EventFormMode.VenueCreate,
    val options: Loadable<EventFormOptions> = Loadable.Loading,
    val catalog: ManagementOptions? = null,
    val form: EventForm = EventForm(),
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val message: UiText? = null,
    /* L'evento salvato: dopo la prima bozza del locale, l'id con cui riprenderla. */
    val eventId: Long? = null,
    val status: String? = null,
    val posterUrl: String? = null,
    val pendingPoster: PreparedImage? = null,
    val checks: EventDateChecksResult = EventDateChecksResult(),
    val facebook: FacebookImportResult? = null,
    val facebookUrl: String = "",
    val tagResults: List<NamedRef> = emptyList(),
    val venueResults: List<VenueLookup> = emptyList(),
    /* Le date dell'evento, per collegare una voce del «Programma» a una data (P2). */
    val eventDates: List<it.fabiodalez.incitta.data.management.ManagedEventDate> = emptyList(),
    /* Dove andare dopo il salvataggio che chiude il modulo. */
    val finishedId: Long? = null,
) {
    val zone get() = subject?.timezone ?: it.fabiodalez.incitta.data.management.DEFAULT_MANAGEMENT_ZONE
}

/**
 * Il modulo dell'evento nelle sue tre varianti. Il modulo lungo non sta nello
 * stato salvato (troppo grande per il `Bundle`, piano §3.4): si salva come
 * bozza sul server, ed è il server a ricordarlo.
 */
internal class EventEditorViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val routeId: Long = saved.get<Long>("id") ?: 0L
    private val state = MutableStateFlow(EventEditorUiState(eventId = routeId.takeIf { it > 0 } ?: saved.get<Long>(KEY_DRAFT)))
    val uiState: StateFlow<EventEditorUiState> = state.asStateFlow()
    private var checksJob: Job? = null

    init { load() }

    fun load() {
        state.update { it.copy(options = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val subject = repository.eventsSubject(saved) ?: throw IllegalStateException()
                val api = repository.eventsApi()
                val create = routeId == 0L
                val mode = EventFormMode.of(subject.kind, create)
                val existing = state.value.eventId
                val detail = existing?.let { api.detail(subject, it) }
                val options = api.form(subject, detail?.venue?.id)
                val form = detail?.let(EventForm::from) ?: EventForm.blank(options, mode)
                state.update {
                    it.copy(
                        subject = subject, mode = mode, options = Loadable.Ready(options), catalog = repository.options(),
                        form = form, status = detail?.status?.value, posterUrl = detail?.poster?.card ?: detail?.poster?.full,
                        eventDates = detail?.let { (it.dates.upcoming + it.dates.past).sortedBy { date -> date.startsAt } }.orEmpty(),
                    )
                }
                refreshChecks(immediate = true)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(options = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    /** Ogni modifica del modulo passa di qui; data, luogo e categoria rifanno gli avvisi. */
    fun edit(change: (EventForm) -> EventForm) {
        val before = state.value.form
        val after = change(before)
        state.update { it.copy(form = after) }
        if (before.startsAt != after.startsAt || before.endsAt != after.endsAt || before.categoryId != after.categoryId ||
            before.locationLat != after.locationLat || before.locationLng != after.locationLng || before.locationAddress != after.locationAddress ||
            before.venue != after.venue || before.cityId != after.cityId) refreshChecks()
    }

    /** Le scorciatoie della data: il giorno all'ora abituale, «Ogni giovedì» accende la ripetizione. */
    fun applyShortcut(startsAt: String, repeats: Boolean, weekday: String?) {
        val local = cityLocalDateTime(startsAt, state.value.zone) ?: return
        edit { form ->
            val withDate = form.copy(startsAt = local)
            if (repeats && weekday != null) withDate.copy(repeat = form.repeat.copy(enabled = true, frequency = "weekly", weekdays = setOf(weekday))) else withDate
        }
    }

    fun setFacebookUrl(url: String) { state.update { it.copy(facebookUrl = url) } }

    fun importFacebook() {
        val current = state.value
        val subject = current.subject ?: return
        if (current.busy || current.facebookUrl.isBlank()) return
        state.update { it.copy(busy = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val result = repository.eventsApi().importFacebook(subject, current.facebookUrl.trim(), current.form.cityId)
                state.update { it.copy(form = it.form.withFacebook(result.fields, it.zone), facebook = result, message = UiText.Res(R.string.management_events_facebook_loaded)) }
                refreshChecks(immediate = true)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                val message = if (e is ApiException && e.status == 429) UiText.Raw(e.problem?.message ?: requestFailureMessage(e)) else null
                state.update { it.copy(errors = if (message == null) FormErrors.from(e, listOf("url")) else FormErrors.None, message = message) }
            } finally { state.update { it.copy(busy = false) } }
        }
    }

    fun searchTags(query: String) = lookup { subject -> val found = repository.eventsApi().tags(subject, query); state.update { it.copy(tagResults = found) } }
    fun searchVenues(query: String) = lookup { subject -> val found = repository.eventsApi().venues(subject, query); state.update { it.copy(venueResults = found) } }

    /** Un tag nuovo, condiviso con gli altri locali (solo il locale). */
    fun createTag(name: String) = lookup { subject ->
        val tag = repository.eventsApi().createTag(subject, name, state.value.eventId)
        edit { it.copy(tags = (it.tags + tag).distinctBy(NamedRef::id)) }
    }

    /** La locandina scelta: subito sull'evento salvato, altrimenti dopo il primo salvataggio. */
    fun setPoster(image: PreparedImage) {
        val id = state.value.eventId
        if (id == null) { state.update { it.copy(pendingPoster = image, message = UiText.Res(R.string.management_events_poster_pending)) }; return }
        run { subject ->
            val result = (repository.media ?: throw IllegalStateException()).uploadPoster(subject, id, image)
            val sent = result["sent_to_review"]?.toString() == "true"
            val detail = repository.eventsApi().detail(subject, id)
            state.update { it.copy(posterUrl = detail.poster?.card ?: detail.poster?.full, status = detail.status.value) }
            UiText.Res(if (sent) R.string.management_events_sent_to_review else R.string.management_events_poster_saved)
        }
    }

    fun posterError(message: UiText) { state.update { it.copy(message = message) } }

    /** «Salva come bozza» (locale, creazione): basta il titolo. */
    fun saveDraft() {
        if (state.value.form.title.isBlank()) {
            state.update { it.copy(errors = FormErrors(fields = mapOf("title" to TITLE_NEEDED))) }
            return
        }
        run { subject -> saveVenue(subject, publish = false).let(::saveMessage) }
    }

    /** «Pubblica» (locale, creazione): la locandina prima, poi la pubblicazione sulla stessa bozza. */
    fun publish() = run { subject ->
        if (state.value.pendingPoster != null && state.value.eventId == null) saveVenue(subject, publish = false)
        val response = saveVenue(subject, publish = true)
        state.update { it.copy(finishedId = response.data.id) }
        saveMessage(response)
    }

    /** «Crea» dell'organizzazione, «Salva» della modifica. */
    fun save() = run { subject ->
        val api = repository.eventsApi()
        val current = state.value
        val response = if (current.mode.isCreate) {
            api.create(subject, current.form.toJson(current.mode, current.zone, facebookImport = current.facebook?.import)).also { created ->
                state.update { it.copy(eventId = created.data.id, facebook = null) }
                uploadPending(subject, created.data.id)
                state.update { it.copy(finishedId = created.data.id) }
            }
        } else {
            api.update(subject, current.eventId ?: routeId, current.form.toJson(current.mode, current.zone))
        }
        state.update { it.copy(status = response.data.status.value) }
        saveMessage(response)
    }

    fun consumeMessage() { state.update { it.copy(message = null) } }
    fun consumeFinished() { state.update { it.copy(finishedId = null) } }

    private suspend fun saveVenue(subject: ManagementSubject, publish: Boolean): EventSaveResponse {
        val current = state.value
        val body = current.form.toJson(EventFormMode.VenueCreate, current.zone, publish = publish, draftId = current.eventId, facebookImport = current.facebook?.import)
        val response = repository.eventsApi().create(subject, body)
        saved[KEY_DRAFT] = response.data.id
        state.update { it.copy(eventId = response.data.id, status = response.data.status.value, facebook = null) }
        uploadPending(subject, response.data.id)
        return response
    }

    private suspend fun uploadPending(subject: ManagementSubject, id: Long) {
        val image = state.value.pendingPoster ?: return
        (repository.media ?: return).uploadPoster(subject, id, image)
        state.update { it.copy(pendingPoster = null) }
    }

    private fun run(block: suspend (ManagementSubject) -> UiText) {
        val subject = state.value.subject ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try { val message = block(subject); state.update { it.copy(message = message) } }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, EVENT_FORM_FIELDS)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    private fun lookup(block: suspend (ManagementSubject) -> Unit) {
        val subject = state.value.subject ?: return
        viewModelScope.launch {
            try { block(subject) }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(message = UiText.Raw(problemText(e))) } }
        }
    }

    /** «Stessa sera in zona» e chiusura straordinaria, mezzo secondo dopo l'ultima modifica. */
    private fun refreshChecks(immediate: Boolean = false) {
        checksJob?.cancel()
        val current = state.value
        val subject = current.subject ?: return
        val start = current.form.startsAt
        if (start == null || !current.mode.isCreate) {
            state.update { it.copy(checks = EventDateChecksResult()) }
            return
        }
        checksJob = viewModelScope.launch {
            if (!immediate) delay(500)
            val form = state.value.form
            val body = buildJsonObject {
                put("starts_at", cityIso(start, current.zone))
                form.endsAt?.let { put("ends_at", cityIso(it, current.zone)) }
                form.categoryId?.let { put("category_id", it) }
                form.cityId?.let { put("city_id", it) }
                form.venue?.let { put("venue_id", it.id) }
                if (form.locationAddress.isNotBlank()) put("custom_location", buildJsonObject {
                    put("address", form.locationAddress)
                    form.locationLat.replace(',', '.').toDoubleOrNull()?.let { put("lat", it) }
                    form.locationLng.replace(',', '.').toDoubleOrNull()?.let { put("lng", it) }
                })
            }
            try { val result = repository.eventsApi().checks(subject, body); state.update { it.copy(checks = result) } }
            catch (e: CancellationException) { throw e }
            catch (_: Exception) { /* Gli avvisi non bloccano: senza rete non si mostrano. */ }
        }
    }

    companion object {
        const val KEY_DRAFT = "event_draft_id"
        /* Lo stesso testo del pannello («Per salvare la bozza serve almeno un titolo»), risolto dalla schermata. */
        const val TITLE_NEEDED = "\u0000title_needed"
    }
}
